<div>This is a writer</div>
<<<<<<< HEAD
=======

<div>
    @php $titles = $item->bios->pluck('title'); @endphp

    @if ($titles->isNotEmpty())
        Bios: {{ $titles->sort()->join(', ') }}
    @else
        No bios
    @endif
</div>
>>>>>>> upstream/2.x
