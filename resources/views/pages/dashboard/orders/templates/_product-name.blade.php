<span class="">
    {{ $product->name }} 
    @if ($product->product_type == 'dynamic')
    ({{ $product->rate }}%)
    @else 
    (x{{ $product->quantity }})
    @endif
</span>
<br>
<span class="text-xs text-gray-600">{{ $product->unit?->name }}</span>