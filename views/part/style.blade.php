@if(!empty($style))
<style type="text/css">
    @foreach($style as $s)
        {!! $s !!}
    @endforeach
</style>
@endif
