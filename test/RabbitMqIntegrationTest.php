<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use Micx\Vcs\{MixVcs,RabbitMqTransport};
use PhpAmqpLib\Connection\AMQPStreamConnection;
final class RabbitMqIntegrationTest extends TestCase
{
    public function testRealBrokerCorrelationAndTransport(): void
    {
        if (!getenv('AMQP_TEST_HOST')) self::markTestSkipped('Set AMQP_TEST_HOST and start test/rpc-fixture.php');
        $c=new AMQPStreamConnection(getenv('AMQP_TEST_HOST'),5672,'guest','guest');
        try {
            $vcs=new MixVcs(new RabbitMqTransport($c),5);
            self::assertSame(['echo'=>['value'=>42]],$vcs->call('echo',['value'=>42]));
        } finally { $c->close(); }
    }
}
