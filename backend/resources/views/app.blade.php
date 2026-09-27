<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PayFlow HR</title>
    @if (file_exists(public_path('build/manifest.json')))
        @php($manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true))
        @php($entry = $manifest['index.html'] ?? null)
        @if ($entry)
            @foreach ($entry['css'] ?? [] as $stylesheet)
                <link rel="stylesheet" href="{{ asset('build/'.$stylesheet) }}">
            @endforeach
            <script type="module" src="{{ asset('build/'.$entry['file']) }}"></script>
        @endif
    @elseif (app()->environment('local'))
        <script type="module" src="http://localhost:5173/@vite/client"></script>
        <script type="module" src="http://localhost:5173/src/main.jsx"></script>
    @endif
</head>
<body>
    <div id="root"></div>
</body>
</html>
