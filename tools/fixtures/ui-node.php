<?php

// Synthetic node transport for local UI exercises only. Real TLS and native
// Wings end-to-end qualification uses the independent release acceptance stack.
use App\Models\Server;
use Illuminate\Support\Facades\Http;
use ShookEagle\BetterPelicanSecrets\Node\NodeCapabilityService;

if (!app()->environment('local') || config('database.connections.sqlite.database') !== '/workspace/.work/panel/database/bps-ui.sqlite'
    || !is_dir('/bps-ui-data')) {
    throw new RuntimeException('Only the disposable UI fixture may use this transport.');
}
$server = Server::firstWhere('name', 'CS2 development');
if (!$server) {
    throw new RuntimeException('Disposable UI server is missing.');
}
$node = $server->node;
Http::fake([$node->getConnectionAddress() . '/api/*' => function ($request) use ($server, $node) {
    $path = parse_url($request->url(), PHP_URL_PATH);
    $headers = ['User-Agent' => 'Pelican Wings/v1.0.0 (id:' . $node->daemon_token_id . ')'];
    if ($path === '/api/system') {
        return Http::response(['protocol' => 1, 'wings_base_commit' => NodeCapabilityService::WINGS_BASE,
            'panel_base_version' => NodeCapabilityService::PANEL_VERSION], 200, $headers);
    }
    if (!str_starts_with($path, '/api/servers/' . $server->uuid . '/files/')) {
        throw new RuntimeException('Synthetic UI node only supports file setup.');
    }
    parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
    $relative = ltrim((string) ($query['file'] ?? $query['directory'] ?? ''), '/');
    if (str_contains($relative, chr(92)) || preg_match('/[[:cntrl:]]/u', $relative)
        || ($relative !== '' && array_intersect(explode('/', $relative), ['', '.', '..']))) {
        throw new RuntimeException('Invalid fixture path.');
    }
    $target = '/bps-ui-data' . ($relative === '' ? '' : '/' . $relative);
    if (str_ends_with($path, '/files/list-directory')) {
        $entries = [];
        foreach (is_dir($target) ? scandir($target) : [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $file = $target . '/' . $name;
            $entries[] = ['name' => $name, 'file' => is_file($file), 'directory' => is_dir($file), 'symlink' => is_link($file)];
        }

        return Http::response($entries, 200, $headers);
    }
    if (str_ends_with($path, '/files/contents')) {
        return is_file($target) ? Http::response(file_get_contents($target), 200, $headers) : Http::response('', 404, $headers);
    }
    if (str_ends_with($path, '/files/write')) {
        if ($relative === '' || !is_dir(dirname($target))) {
            return Http::response('', 400, $headers);
        }
        file_put_contents($target, $request->body());
        chmod($target, 0644);

        return Http::response('', 204, $headers);
    }
    throw new RuntimeException('Unexpected synthetic UI request.');
}]);
