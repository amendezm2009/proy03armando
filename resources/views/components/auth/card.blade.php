@props(['title', 'icon' => null, 'width' => 'col-md-6 col-lg-4'])

<div class="row justify-content-center">
    <div class="{{ $width }}">
        <div class="card">
            <div class="card-header">
                @if ($icon)
                    <i class="bi {{ $icon }} me-1"></i>
                @endif
                {{ $title }}
            </div>
            <div class="card-body">{{ $slot }}</div>
            @isset($footer)
                <div class="card-footer text-center small">{{ $footer }}</div>
            @endisset
        </div>
    </div>
</div>
