{!! '<'.'?xml version="1.0" encoding="UTF-8"?>' !!}
<feed xmlns="http://www.w3.org/2005/Atom">
    <title>{{ $title }}</title>
    <link href="{{ $link }}"/>
    <link rel="self" href="{{ url()->current() }}"/>
    <id>{{ url()->current() }}</id>
    <updated>{{ ($dispatches->first()?->created_at ?? now())->toAtomString() }}</updated>
@foreach ($dispatches as $d)
    <entry>
        <title>{{ str($d->body)->limit(80) }}</title>
        <link href="{{ route('dispatches.show', $d) }}"/>
        <id>{{ route('dispatches.show', $d) }}</id>
        <updated>{{ $d->created_at->toAtomString() }}</updated>
        <author><name>{{ $d->user->name }} (@{{ $d->user->username }})</name></author>
        <category term="{{ $d->line }}" label="{{ $d->lineName() }}"/>
        <content type="text">{{ $d->body }}{{ $d->stop ? ' — '.$d->stop.' · '.$d->stopName() : '' }}</content>
    </entry>
@endforeach
</feed>
