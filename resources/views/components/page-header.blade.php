@props([
    'title',
    'searchPlaceholder' => 'Cari...',
    'showSearch' => true,
])

<div class="panel-header" style="flex-direction: column; align-items: stretch; gap: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div class="panel-title">{{ $title }}</div>
        
        <div style="display: flex; gap: 10px; align-items: center;">
            {{-- Named slot: actions (tombol / dropdown tambahan) --}}
            @isset($actions)
                {{ $actions }}
            @endisset

            @if($showSearch)
            <div class="search-bar" style="width: 280px;">
                <i class='bx bx-search'></i>
                <input type="text" placeholder="{{ $searchPlaceholder }}" style="width: 100%;">
            </div>
            @endif
        </div>
    </div>

    {{-- Named slot: tabs --}}
    @isset($tabs)
    <div class="tabs">
        {{ $tabs }}
    </div>
    @endisset
</div>
