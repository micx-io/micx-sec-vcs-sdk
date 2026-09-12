<?php
declare(strict_types=1);
use Micx\Vcs\{MixVcs, RpcTransport, RpcException};
use PHPUnit\Framework\TestCase;
final class MixVcsTest extends TestCase
{
    public function testCommitPreservesRetryIdentityAndArguments(): void
    {
        $transport = new class implements RpcTransport {
            public array $requests = [];
            public function request(array $request, float $timeout): array {
                $this->requests[] = $request;
                return ['version'=>1, 'id'=>$request['id'], 'ok'=>true, 'result'=>['revision'=>'abc']];
            }
        };
        $vcs = new MixVcs($transport);
        $vcs->commit('workspace', 'message', 'before', 0, true, 'stable-id');
        $vcs->commit('workspace', 'message', 'before', 0, true, 'stable-id');
        self::assertSame($transport->requests[0], $transport->requests[1]);
        self::assertSame('before', $transport->requests[0]['params']['expectedRevision']);
    }
    public function testRejectsMismatchedReply(): void
    {
        $transport = new class implements RpcTransport {
            public function request(array $r, float $t): array { return ['version'=>1,'id'=>'wrong','ok'=>true,'result'=>[]]; }
        };
        $this->expectException(RpcException::class);
        (new MixVcs($transport))->status('workspace');
    }
    public function testRemoteConflictIsTyped(): void
    {
        $transport = new class implements RpcTransport {
            public function request(array $r, float $t): array { return ['version'=>1,'id'=>$r['id'],'ok'=>false,'error'=>['code'=>'CONFLICT','message'=>'Changed']]; }
        };
        try { (new MixVcs($transport))->status('workspace'); self::fail(); }
        catch (RpcException $e) { self::assertSame('CONFLICT', $e->errorCode); }
    }
}
