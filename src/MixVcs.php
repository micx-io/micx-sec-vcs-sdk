<?php
declare(strict_types=1);
namespace Micx\Vcs;
final class MixVcs
{
    public function __construct(private RpcTransport $transport, private float $timeout = 60.0)
    {
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > 300) throw new \InvalidArgumentException('Timeout must be in (0,300] seconds');
    }

    public function call(string $method, array $params = [], ?string $requestId = null): array
    {
        $id = $requestId ?? bin2hex(random_bytes(16));
        if (!preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $id)) throw new \InvalidArgumentException('Request ID must contain 8–128 letters, digits, underscores or hyphens');
        $request = ['version' => 1, 'id' => $id, 'method' => $method, 'params' => $params];
        try { $reply = $this->transport->request($request, $this->timeout); }
        catch (RpcException $e) {
            if ($e->errorCode === 'TIMEOUT') throw new OperationTimeoutException($e->getMessage(), $request, $e->details);
            throw $e;
        }
        if (($reply['version'] ?? null) !== 1 || ($reply['id'] ?? null) !== $id || !is_bool($reply['ok'] ?? null)) {
            throw new RpcException('INVALID_RESPONSE', 'Invalid RPC envelope');
        }
        if (!$reply['ok']) {
            $e = $reply['error'] ?? null;
            if (!is_array($e) || !is_string($e['code'] ?? null) || $e['code']===''
                || !is_string($e['message'] ?? null) || $e['message']===''
                || (array_key_exists('details',$e) && !is_array($e['details']))) {
                throw new RpcException('INVALID_RESPONSE', 'Malformed RPC error; operation outcome is unknown', ['requestId'=>$id]);
            }
            if ($e['code'] === 'TIMEOUT') throw new OperationTimeoutException($e['message'], $request, $e['details'] ?? []);
            throw new RpcException($e['code'], $e['message'], $e['details'] ?? []);
        }
        if (!is_array($reply['result'] ?? null)) throw new RpcException('INVALID_RESPONSE', 'Missing result');
        return $reply['result'];
    }

    public function checkout(string $url, ?string $branch = null, ?string $directory = null, bool $temporary = false): array
    {
        return $this->call('checkout', compact('url', 'branch', 'directory', 'temporary'));
    }
    /** Create an empty local repository; the remote URL is used by push later. */
    public function create(string $url, string $branch = 'main', ?string $directory = null): array
    {
        return $this->call('create', compact('url', 'branch', 'directory'));
    }
    public function status(string $workspace): array { return $this->call('status', compact('workspace')); }
    public function path(string $workspace): string { return $this->status($workspace)['path']; }
    public function pull(string $workspace): array { return $this->call('pull', compact('workspace')); }
    public function commit(string $workspace, string $message = 'Update workspace', bool $push = false): array
    {
        return $this->call('commit', compact('workspace', 'message', 'push'));
    }
    public function push(string $workspace, ?string $branch = null): array { return $this->call('push', compact('workspace', 'branch')); }
    public function branch(string $workspace, string $branch): array { return $this->call('branch', compact('workspace', 'branch')); }
    public function merge(string $workspace, string $source): array { return $this->call('merge', compact('workspace', 'source')); }
    public function listing(string $workspace, string $path = ''): array { return $this->call('list', compact('workspace', 'path')); }
    public function read(string $workspace, array $paths): array { return $this->call('read', compact('workspace', 'paths')); }
    /** Files: [['path'=>'a.txt', 'content'=>base64_encode('hello')]], null content deletes. */
    public function update(string $workspace, array $files): array { return $this->call('update', compact('workspace', 'files')); }
    public function archive(string $workspace): array { return $this->call('archive', compact('workspace')); }
    public function snapshot(string $workspace): array { return $this->call('snapshot', compact('workspace')); }
    public function release(string $workspace): array { return $this->call('release', compact('workspace')); }
}
