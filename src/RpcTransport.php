<?php
declare(strict_types=1);
namespace Micx\Vcs;
interface RpcTransport
{
    /** Implementations must preserve request IDs and enforce a bounded wait. */
    public function request(array $request, float $timeout): array;
}
