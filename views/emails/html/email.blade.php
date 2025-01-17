@component('twill::emails.html.layout')
{{-- Header --}}
@slot('header')
@component('twill::emails.html.header', ['url' => config('app.url')])
# {{ config('app.name') }}&nbsp;<span class="envlabel envlabel--{{ app()->environment() }}">{{ app()->environment() }}</span>
## {{ $title ?? $actionText }}
@endcomponent
@endslot

{{-- Body --}}
{{ twillTrans('twill::lang.emails.hello') }}

{{ $copy }}

{{ twillTrans('twill::lang.emails.regards') }}<br>
{{ config('app.name') }}

{{-- Button --}}
@slot('button')
@component('twill::emails.html.button', ['url' => $url])
{{ $actionText }}
@endcomponent
@endslot

{{-- Subcopy --}}
@slot('subcopy')
@component('twill::emails.html.subcopy')
{{ twillTrans('twill::lang.emails.problems', ['actionText' => $actionText, 'url' => $url]) }}
@endcomponent
@endslot

{{-- Footer --}}
@slot('footer')
@component('twill::emails.html.footer')
&copy; {{ date('Y') }}  &mdash; All rights reserved.
@endcomponent
@endslot
@endcomponent
