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
    public function testRemoteErrorsPreserveActionableDetails(): void
    {
        foreach (['SSH_KEY_INVALID','SSH_AUTH_FAILED','REPOSITORY_UNAVAILABLE','IO_ERROR','BUSY','OUTCOME_UNKNOWN','PUSH_FAILED'] as $code) {
            $transport = new class($code) implements RpcTransport {
                public function __construct(private string $code) {}
                public function request(array $r,float $t): array {
                    return ['version'=>1,'id'=>$r['id'],'ok'=>false,'error'=>['code'=>$this->code,'message'=>'Actionable message','details'=>['exitCode'=>128]]];
                }
            };
            try { (new MixVcs($transport))->status('workspace'); self::fail(); }
            catch (RpcException $e) {
                self::assertSame($code,$e->errorCode);
                self::assertSame('Actionable message',$e->getMessage());
                self::assertSame(['exitCode'=>128],$e->details);
            }
        }
    }
    public function testMalformedErrorsAlwaysThrowRpcException(): void
    {
        foreach ([null,'bad',[],['code'=>42,'message'=>'bad'],['code'=>'FAIL','message'=>[]],['code'=>'FAIL','message'=>'bad','details'=>null]] as $error) {
            $transport=new class($error) implements RpcTransport {
                public function __construct(private mixed $error) {}
                public function request(array $r,float $t): array { return ['version'=>1,'id'=>$r['id'],'ok'=>false,'error'=>$this->error]; }
            };
            try { (new MixVcs($transport))->status('workspace'); self::fail(); }
            catch (RpcException $e) { self::assertSame('INVALID_RESPONSE',$e->errorCode); }
        }
    }
    public function testClosedBrokerConnectionBecomesUnavailable(): void
    {
        $connection=$this->createMock(\PhpAmqpLib\Connection\AbstractConnection::class);
        $connection->method('channel')->willThrowException(new \PhpAmqpLib\Exception\AMQPConnectionClosedException('internal endpoint'));
        try { (new MixVcs(new \Micx\Vcs\RabbitMqTransport($connection)))->status('workspace'); self::fail(); }
        catch (RpcException $e) {
            self::assertSame('UNAVAILABLE',$e->errorCode);
            self::assertStringNotContainsString('internal endpoint',$e->getMessage());
        }
    }
    public function testBrokerLossAfterPublishReportsUnknownOutcome(): void
    {
        $connection=$this->createMock(\PhpAmqpLib\Connection\AbstractConnection::class);
        $channel=$this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class);
        $connection->method('channel')->willReturn($channel);
        $channel->method('queue_declare')->willReturn(['micx.vcs.reply.'.str_repeat('a',32),0,0]);
        $channel->expects(self::once())->method('basic_publish');
        $channel->method('wait_for_pending_acks_returns')->willThrowException(new \PhpAmqpLib\Exception\AMQPConnectionClosedException('connection lost'));
        try { (new MixVcs(new \Micx\Vcs\RabbitMqTransport($connection)))->call('commit',[],'stable-123'); self::fail(); }
        catch (RpcException $e) {
            self::assertSame('OUTCOME_UNKNOWN',$e->errorCode);
            self::assertSame('stable-123',$e->details['requestId']);
        }
    }
}
