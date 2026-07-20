<?php

declare(strict_types=1);

if ($argc < 6) {
    fwrite(STDERR, "Usage: php local-http-proxy-relay.php <listen-port> <upstream-host> <upstream-port> <user> <pass>\n");
    exit(1);
}

[, $listenPort, $upstreamHost, $upstreamPort, $username, $password] = $argv;
$authHeader = 'Proxy-Authorization: Basic '.base64_encode($username.':'.$password)."\r\n";

$bindAddress = 'tcp://127.0.0.1:'.((int) $listenPort === 0 ? '0' : $listenPort);
$server = stream_socket_server($bindAddress);
if ($server === false) {
    fwrite(STDERR, "Failed to bind local proxy on {$bindAddress}\n");
    exit(1);
}

$address = stream_socket_get_name($server, false);
if (!is_string($address) || !str_contains($address, ':')) {
    fwrite(STDERR, "Failed to resolve local proxy listen address\n");
    exit(1);
}

$actualPort = (int) substr($address, strrpos($address, ':') + 1);
fwrite(STDERR, 'READY '.$actualPort."\n");
fflush(STDERR);

stream_set_blocking($server, false);

/** @var array<int, array{socket: resource, upstream: resource|null, buffer: string}> $clients */
$clients = [];

while (true) {
    $read = [$server];
    $streamOwners = [];

    foreach ($clients as $id => $state) {
        if (is_resource($state['socket'])) {
            $read[] = $state['socket'];
            $streamOwners[(int) $state['socket']] = [$id, 'socket'];
        }
        if (is_resource($state['upstream'])) {
            $read[] = $state['upstream'];
            $streamOwners[(int) $state['upstream']] = [$id, 'upstream'];
        }
    }

    $write = null;
    $except = null;
    $ready = @stream_select($read, $write, $except, 1);
    if ($ready === false) {
        pruneClients($clients);

        continue;
    }

    if ($ready === 0) {
        continue;
    }

    foreach ($read as $stream) {
        if ($stream === $server) {
            $client = @stream_socket_accept($server);
            if (!is_resource($client)) {
                continue;
            }

            stream_set_blocking($client, false);
            $clients[(int) $client] = [
                'socket' => $client,
                'upstream' => null,
                'buffer' => '',
            ];

            continue;
        }

        $owner = $streamOwners[(int) $stream] ?? null;
        if ($owner === null) {
            continue;
        }

        [$id, $side] = $owner;
        if (!isset($clients[$id])) {
            continue;
        }

        $chunk = @fread($stream, 16384);
        if ($chunk === false || $chunk === '') {
            closeClient($clients, $id);

            continue;
        }

        if ($side === 'socket' && $clients[$id]['upstream'] === null) {
            $clients[$id]['buffer'] .= $chunk;
            if (!str_contains($clients[$id]['buffer'], "\r\n\r\n")) {
                continue;
            }

            $upstream = @stream_socket_client('tcp://'.$upstreamHost.':'.$upstreamPort);
            if (!is_resource($upstream)) {
                @fwrite($clients[$id]['socket'], "HTTP/1.1 502 Bad Gateway\r\n\r\n");
                closeClient($clients, $id);

                continue;
            }

            stream_set_blocking($upstream, false);
            $clients[$id]['upstream'] = $upstream;
            @fwrite($upstream, injectProxyAuth($clients[$id]['buffer'], $authHeader));
            $clients[$id]['buffer'] = '';

            continue;
        }

        $target = $side === 'socket' ? $clients[$id]['upstream'] : $clients[$id]['socket'];
        if (!is_resource($target)) {
            closeClient($clients, $id);

            continue;
        }

        if (@fwrite($target, $chunk) === false) {
            closeClient($clients, $id);
        }
    }
}

function injectProxyAuth(string $request, string $authHeader): string
{
    $parts = explode("\r\n", $request, 2);
    if (count($parts) < 2) {
        return $request;
    }

    return $parts[0]."\r\n".$authHeader.$parts[1];
}

/** @param array<int, array{socket: resource, upstream: resource|null, buffer: string}> $clients */
function closeClient(array &$clients, int $id): void
{
    if (!isset($clients[$id])) {
        return;
    }

    if (is_resource($clients[$id]['socket'])) {
        @fclose($clients[$id]['socket']);
    }

    if (is_resource($clients[$id]['upstream'])) {
        @fclose($clients[$id]['upstream']);
    }

    unset($clients[$id]);
}

/** @param array<int, array{socket: resource, upstream: resource|null, buffer: string}> $clients */
function pruneClients(array &$clients): void
{
    foreach (array_keys($clients) as $id) {
        if (!is_resource($clients[$id]['socket'])) {
            closeClient($clients, $id);
        }
    }
}
