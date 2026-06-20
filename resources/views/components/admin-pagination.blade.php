@props([
    'paginator',
    'label' => 'data',
    'query' => [],
])

@php
    if (! empty($query)) {
        $paginator->appends($query);
    }

    $firstItem = $paginator->firstItem() ?? 0;
    $lastItem = $paginator->lastItem() ?? 0;
    $windowSize = \App\Services\Admin\AdminPagination::WINDOW_SIZE;
    $lastPage = max(1, (int) $paginator->lastPage());
    $currentPage = max(1, min((int) $paginator->currentPage(), $lastPage));
    $windowStart = ((int) floor(($currentPage - 1) / $windowSize) * $windowSize) + 1;
    $windowEnd = min($lastPage, $windowStart + $windowSize - 1);
    $visiblePages = range($windowStart, $windowEnd);
@endphp

<div class="panel-pagination">
    <span>Menampilkan {{ $firstItem }}-{{ $lastItem }} dari {{ $paginator->total() }} {{ $label }}</span>
    <div class="pagination-controls">
        @if($paginator->onFirstPage())
            <span class="btn-page disabled" aria-disabled="true">&laquo;</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="btn-page" aria-label="Halaman sebelumnya">&laquo;</a>
        @endif

        @foreach($visiblePages as $page)
            @if($page === $currentPage)
                <span class="btn-page active" aria-current="page">{{ $page }}</span>
            @else
                <a href="{{ $paginator->url($page) }}" class="btn-page" aria-label="Halaman {{ $page }}">{{ $page }}</a>
            @endif
        @endforeach

        @if($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="btn-page" aria-label="Halaman berikutnya">&raquo;</a>
        @else
            <span class="btn-page disabled" aria-disabled="true">&raquo;</span>
        @endif
    </div>
</div>
