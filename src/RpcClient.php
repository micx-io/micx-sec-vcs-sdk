<?php
declare(strict_types=1);

namespace Micx\Vcs;

/** Internal client for the existing VCS worker protocol. */
final class RpcClient
{
    public function __construct(private RpcTransport $transport, private float $timeout = 60.0)
    {
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > 300) {
            throw new \InvalidArgumentException('Timeout must be in (0, 300] seconds');
        }
    }

    /** @param array<string, mixed> $params
     *  @return array<string, mixed>
     */
    public function call(string $method, array $params = []): array
    {
        $id = bin2hex(random_bytes(16));
        $request = ['version' => 1, 'id' => $id, 'method' => $method, 'params' => $params];

        try {
            $reply = $this->transport->request($request, $this->timeout);
        } catch (RpcException $e) {
            if ($e->errorCode === 'TIMEOUT') {
                throw new OperationTimeoutException($e->getMessage(), $request, $e->details);
            }
            throw $e;
        }

        if (($reply['version'] ?? null) !== 1 || ($reply['id'] ?? null) !== $id
            || !is_bool($reply['ok'] ?? null)) {
            throw new RpcException('INVALID_RESPONSE', 'Invalid RPC response envelope');
        }

        if (!$reply['ok']) {
            $error = $reply['error'] ?? [];
            if (!is_array($error) || !is_string($error['code'] ?? null)
                || !is_string($error['message'] ?? null)) {
                throw new RpcException('INVALID_RESPONSE', 'Malformed RPC error');
            }
            if ($error['code'] === 'TIMEOUT') {
                throw new OperationTimeoutException($error['message'], $request, $error['details'] ?? []);
            }
            throw new RpcException($error['code'], $error['message'], $error['details'] ?? []);
        }

        if (!is_array($reply['result'] ?? null)) {
            throw new RpcException('INVALID_RESPONSE', 'Missing RPC result');
        }

        return $reply['result'];
    }
}
