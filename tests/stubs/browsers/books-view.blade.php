<div>This is a book</div>
<<<<<<< HEAD
=======

<div>
    @php $names = $item->getRelated('writers')->pluck('title'); @endphp

    @if ($names->isNotEmpty())
        Writers: {{ $names->sort()->join(', ') }}
    @else
        No writers
    @endif
</div>
>>>>>>> upstream/2.x
