@if(isset($options[$value]))
    @if(isset($options[$value]['label']))
        @if(isset($options[$value]['title']))
            {{$options[$value]['title']}}
        @else
            {{$options[$value]['label']}}
        @endif
    @else
        {{$options[$value]}}
    @endif
@else
    @if($value)
        {{$value}}
    @else
        <span class="ub-text-muted">-</span>
    @endif
@endif
