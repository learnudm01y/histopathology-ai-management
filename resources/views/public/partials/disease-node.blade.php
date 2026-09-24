{{-- One disease and everything below it. Included recursively by api-docs. --}}
<li>
    <span class="node disease {{ $node['is_leaf'] ? 'leaf' : 'branch' }}">{{ $node['name'] }}</span>
    <span class="nid">#{{ $node['id'] }}</span>
    @unless ($node['is_leaf'])
        <span class="tag">refined further — not selectable</span>
    @endunless
    @if ($node['children'] !== [])
        <ul>
            @foreach ($node['children'] as $child)
                @include('public.partials.disease-node', ['node' => $child])
            @endforeach
        </ul>
    @endif
</li>
