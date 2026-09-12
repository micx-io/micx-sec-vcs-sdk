<?php
declare(strict_types=1);
namespace Micx\Vcs;
final class MixVcs
{
    public function __construct(private RpcTransport $transport, private float $timeout = 60.0) {}

    public function call(string $method, array $params = [], ?string $requestId = null): array
    {
        $id = $requestId ?? bin2hex(random_bytes(16));
        $request = ['version' => 1, 'id' => $id, 'method' => $method, 'params' => $params];
        $reply = $this->transport->request($request, $this->timeout);
        if (($reply['version'] ?? null) !== 1 || ($reply['id'] ?? null) !== $id || !is_bool($reply['ok'] ?? null)) {
            throw new RpcException('INVALID_RESPONSE', 'Invalid RPC envelope');
        }
        if (!$reply['ok']) {
            $e = $reply['error'] ?? [];
            throw new RpcException($e['code'] ?? 'REMOTE_ERROR', $e['message'] ?? 'Remote operation failed', $e['details'] ?? []);
        }
        if (!is_array($reply['result'] ?? null)) throw new RpcException('INVALID_RESPONSE', 'Missing result');
        return $reply['result'];
    }

    public function checkout(string $url, ?string $branch = null, ?string $directory = null, bool $temporary = false): array
    {
        return $this->call('checkout', compact('url', 'branch', 'directory', 'temporary'));
    }
    public function status(string $workspace): array { return $this->call('status', compact('workspace')); }
    public function pull(string $workspace, string $expectedRevision, int $expectedGeneration): array { return $this->call('pull', compact('workspace', 'expectedRevision', 'expectedGeneration')); }
    public function commit(string $workspace, string $message, string $expectedRevision, int $expectedGeneration, bool $push = false, ?string $requestId = null): array
    {
        return $this->call('commit', compact('workspace', 'message', 'expectedRevision', 'expectedGeneration', 'push'), $requestId);
    }
    public function push(string $workspace, string $expectedRevision, int $expectedGeneration, ?string $branch = null): array { return $this->call('push', compact('workspace', 'expectedRevision', 'expectedGeneration', 'branch')); }
    public function branch(string $workspace, string $branch, string $expectedRevision, int $expectedGeneration): array { return $this->call('branch', compact('workspace', 'branch', 'expectedRevision', 'expectedGeneration')); }
    public function merge(string $workspace, string $source, string $expectedRevision, int $expectedGeneration): array { return $this->call('merge', compact('workspace', 'source', 'expectedRevision', 'expectedGeneration')); }
    public function listing(string $workspace, string $path = ''): array { return $this->call('list', compact('workspace', 'path')); }
    public function read(string $workspace, array $paths): array { return $this->call('read', compact('workspace', 'paths')); }
    /** Files: [['path'=>'a.txt', 'content'=>base64_encode('hello')]], null content deletes. */
    public function update(string $workspace, array $files, string $expectedRevision, int $expectedGeneration): array { return $this->call('update', compact('workspace', 'files', 'expectedRevision', 'expectedGeneration')); }
    public function archive(string $workspace): array { return $this->call('archive', compact('workspace')); }
    public function snapshot(string $workspace): array { return $this->call('snapshot', compact('workspace')); }
    public function release(string $workspace): array { return $this->call('release', compact('workspace')); }
}
