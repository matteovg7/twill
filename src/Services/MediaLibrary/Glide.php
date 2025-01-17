<?php

namespace A17\Twill\Services\MediaLibrary;

use Illuminate\Config\Repository as Config;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use League\Glide\Responses\LaravelResponseFactory;
use League\Glide\Server;
use League\Glide\ServerFactory;
use League\Glide\Signatures\SignatureFactory;
use League\Glide\Urls\UrlBuilderFactory;
use Illuminate\Support\Facades\Cache;

class Glide implements ImageServiceInterface
{
    use ImageServiceDefaults;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var Application
     */
    protected $app;

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var \League\Glide\Server
     */
    private $server;

    /**
     * @var \League\Glide\Urls\UrlBuilder
     */
    private $urlBuilder;

    /**
     * @param Config $config
     * @param Application $app
     * @param Request $request
     */
    public function __construct(Config $config, Application $app, Request $request)
    {
        $this->config = $config;
        $this->app = $app;
        $this->request = $request;

        $baseUrlHost = $this->config->get(
            'twill.glide.base_url',
            $this->request->getScheme() . '://' . str_replace(
                ['http://', 'https://'],
                '',
                $this->config->get('app.url')
            )
        );

        $baseUrl = join('/', [
            rtrim($baseUrlHost, '/'),
            ltrim($this->config->get('twill.glide.base_path'), '/'),
        ]);

        $this->server = ServerFactory::create([
            'response' => new LaravelResponseFactory($this->request),
            'source' => $this->config->get('twill.glide.source'),
            'cache' => $this->config->get('twill.glide.cache'),
            'source_path_prefix' => $this->config->get('twill.glide.source_path_prefix'),
            'cache_path_prefix' => $this->config->get('twill.glide.cache_path_prefix'),
            'base_url' => $baseUrl,
            'presets' => $this->config->get('twill.glide.presets', []),
            'driver' => $this->config->get('twill.glide.driver')
        ]);

        $this->urlBuilder = UrlBuilderFactory::create(
            $baseUrl,
            $this->config->get('twill.glide.use_signed_urls') ? $this->config->get('twill.glide.sign_key') : null
        );
    }

    /**
     * @param string $path
     * @return mixed
     */
    public function render($path)
    {
        $pathArray = explode(".", $path);
        $extension = end($pathArray);
        if($extension == "gif" || $extension == "svg") {
            return $this->server->getResponseFactory()->create($this->server->getSource(), $this->server->getSourcePath($path));
        }

        if ($this->config->get('twill.glide.use_signed_urls')) {
            SignatureFactory::create($this->config->get('twill.glide.sign_key'))->validateRequest($this->config->get('twill.glide.base_path') . '/' . $path, $this->request->all());
        }

        $response = $this->server->getImageResponse($path, $this->request->all());

        if(env('GLIDE_STORAGE') == "s3") {
            $defaultParams = config('twill.glide.default_params');
            $cachePath = $this->server->getCachePath($path, array_replace($defaultParams, $this->request->all()));
            $cachePathWithoutTwillDefault = $this->server->getCachePath($path, $this->request->all());
            $cachePathMd5 = md5($cachePathWithoutTwillDefault);
            $pathExplode = explode("/", $path);
            $uuid = $pathExplode[0];

            Cache::forever('image_' . $cachePathMd5, $uuid);
        }

        return $response;
    }

    /**
     * @param string $id
     * @param array $params
     * @return string
     */
    public function getUrl($id, array $params = [])
    {
        $defaultParams = config('twill.glide.default_params');
        $addParamsToSvgs = config('twill.glide.add_params_to_svgs', false);

        if (!$addParamsToSvgs && Str::endsWith($id, '.svg')) {
            return $this->urlBuilder->getUrl($id);
        }

        $cachePath = $this->server->getCachePath($id, array_replace($defaultParams, $params));
        $cachePathMd5 = md5($cachePath);

        if(env('GLIDE_STORAGE') == "s3" && Cache::has('image_' . $cachePathMd5)) {
            $url = env("CDN_ENDPOINT", "https://cdn.vg7.org") . "/" . $cachePath;
            return $url;
        }
        else {
            return $this->getOriginalMediaUrl($id) ?? $this->urlBuilder->getUrl($id, array_replace($defaultParams, $params));
        }
        return $this->getOriginalMediaUrl($id) ??
            $this->urlBuilder->getUrl($id, array_replace($defaultParams, $params));
    }

    /**
     * @param string $id
     * @param array $cropParams
     * @param array $params
     * @return string
     */
    public function getUrlWithCrop($id, array $cropParams, array $params = [])
    {
        $defaultParams = config('twill.glide.default_params');
        $cachePath = $this->server->getCachePath($id, array_replace($defaultParams, $this->getCrop($cropParams) + $params));
        $cachePathMd5 = md5($cachePath);

        if(env('GLIDE_STORAGE') == "s3") {
            $urlCdn = env("CDN_ENDPOINT", "https://cdn.vg7.org") . "/" . $cachePath;
            $urlLocal = $this->getUrl($id, $this->getCrop($cropParams) + $params);

            return [
                'url_cdn' => $urlCdn,
                'url_local' => $urlLocal
            ];
        }
        else {
            $urlLocal = $this->getUrl($id, $this->getCrop($cropParams) + $params);

            return [
                'url_local' => $urlLocal
            ];
        }
    }

    /**
     * @param string $id
     * @param array $cropParams
     * @param mixed $width
     * @param mixed $height
     * @param array $params
     * @return string
     */
    public function getUrlWithFocalCrop($id, array $cropParams, $width, $height, array $params = [])
    {
        return $this->getUrl($id, $this->getFocalPointCrop($cropParams, $width, $height) + $params);
    }

    /**
     * @param string $id
     * @param array $params
     * @return string
     */
    public function getLQIPUrl($id, array $params = [])
    {
        $defaultParams = config('twill.glide.lqip_default_params');

        $cropParams = Arr::has($params, $this->cropParamsKeys) ? $this->getCrop($params) : [];

        $params = Arr::except($params, $this->cropParamsKeys);

        $path = $this->server->makeImage($id, array_replace($defaultParams, $params + $cropParams));

        if(env('GLIDE_STORAGE') == "s3") {
            $url = env("CDN_ENDPOINT", "https://cdn.vg7.org") . "/" . $path;
            return $url;
        }

        return $path;
    }

    /**
     * @param string $id
     * @param array $params
     * @return string
     */
    public function getSocialUrl($id, array $params = [])
    {
        $defaultParams = config('twill.glide.social_default_params');

        $cropParams = Arr::has($params, $this->cropParamsKeys) ? $this->getCrop($params) : [];

        $params = Arr::except($params, $this->cropParamsKeys);

        return $this->getUrl($id, array_replace($defaultParams, $params + $cropParams));
    }

    /**
     * @param string $id
     * @return string
     */
    public function getCmsUrl($id, array $params = [])
    {
        $defaultParams = config('twill.glide.cms_default_params');

        $cropParams = Arr::has($params, $this->cropParamsKeys) ? $this->getCrop($params) : [];

        $params = Arr::except($params, $this->cropParamsKeys);

        $cachePath = $this->server->getCachePath($id, array_replace($defaultParams, $params + $cropParams));
        $cachePathMd5 = md5($cachePath);

        if(env('GLIDE_STORAGE') == "s3" && Cache::has('image_' . $cachePathMd5)) {
            $url = env("CDN_ENDPOINT", "https://cdn.vg7.org") . "/" . $cachePath;
            return $url;
        }
        else {
            return $this->urlBuilder->getUrl($id, array_replace($defaultParams, $params + $cropParams));
        }
    }

    /**
     * @param string $id, string $preset
     * @return string
     */
    public function getPresetUrl($id, $preset)
    {
        return $this->getRawUrl($id) . '?p=' . $preset;
    }

    /**
     * @param string $id
     * @return string
     */
    public function getRawUrl($id)
    {
        return $this->getOriginalMediaUrl($id) ?? $this->urlBuilder->getUrl($id);
    }

    /**
     * @param string $id
     * @return array
     */
    public function getDimensions($id)
    {
        $url = $this->urlBuilder->getUrl($id);

        try {
            list($w, $h) = getimagesize($url);

            return [
                'width' => $w,
                'height' => $h,
            ];
        } catch (\Exception $e) {
            return [
                'width' => 0,
                'height' => 0,
            ];
        }
    }

    /**
     * @param array $crop_params
     * @return array
     */
    protected function getCrop($crop_params)
    {
        if (!empty($crop_params)) {
            return ['crop' =>
                $crop_params['crop_w'] . ',' .
                $crop_params['crop_h'] . ',' .
                $crop_params['crop_x'] . ',' .
                $crop_params['crop_y'],
            ];
        }

        return [];
    }

    /**
     * @param array $crop_params
     * @param int $width
     * @param int $height
     * @return array
     */
    protected function getFocalPointCrop($crop_params, $width, $height)
    {
        if (!empty($crop_params)) {
            // determine center coordinates of user crop and express it in term of original image width and height percentage
            $fpX = 100 * ($crop_params['crop_w'] / 2 + $crop_params['crop_x']) / $width;
            $fpY = 100 * ($crop_params['crop_h'] / 2 + $crop_params['crop_y']) / $height;

            // determine focal zoom
            if ($crop_params['crop_w'] > $crop_params['crop_h']) {
                $fpZ = $width / ($crop_params['crop_w'] ?? $width);
            } else {
                $fpZ = $height / ($crop_params['crop_h'] ?? $height);
            }

            $fpX = number_format($fpX, 0, ".", "");
            $fpY = number_format($fpY, 0, ".", "");
            $fpZ = number_format($fpZ, 4, ".", "");

            return ['fit' => 'crop-' . $fpX . '-' . $fpY . '-' . $fpZ];
        }

        return [];
    }

    /**
     * @param string $id
     * @return string
     */
    private function getOriginalMediaUrl($id)
    {
        $libraryDisk = $this->config->get('twill.media_library.disk');
        $endpointType = $this->config->get('twill.media_library.endpoint_type');
        $localMediaLibraryUrl = $this->config->get("filesystems.disks.$libraryDisk.url");
        $originalMediaForExtensions = $this->config->get('twill.glide.original_media_for_extensions');
        $addParamsToSvgs = $this->config->get('twill.glide.add_params_to_svgs', false);

        if ((Str::endsWith($id, '.svg') && $addParamsToSvgs) || !Str::endsWith($id, $originalMediaForExtensions)) {
            return null;
        }

        switch ($endpointType) {
            case 'local':
                $endpoint = $localMediaLibraryUrl;
                break;
            case 's3':
                $endpoint = s3Endpoint($libraryDisk);
                break;
            case 'azure':
                $endpoint = azureEndpoint($libraryDisk);
                break;
            default:
                $endpoint = '';
        }

        return $endpoint . '/' . $id;
    }
}
