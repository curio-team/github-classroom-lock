@props([
    'src',
    'size' => 50,
])

@if($src)
<img src="{{ $src }}" class="border rounded-lg" style="width: {{ $size }}px; height: {{ $size }}px; object-fit: cover;">
@else
<div class="border rounded-lg bg-surface-subtle" style="width: {{ $size }}px; height: {{ $size }}px;"></div>
@endif