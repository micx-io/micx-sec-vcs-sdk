<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use Micx\Vcs\MixVcs;
use Micx\Vcs\RabbitMqTransport;
use PhpAmqpLib\Connection\AMQPStreamConnection;
$connection = new AMQPStreamConnection(getenv('AMQP_HOST') ?: 'rabbitmq', 5672, 'micx', 'micx');
$vcs = new MixVcs(new RabbitMqTransport($connection));
$checkout = $vcs->checkout('git@github.com:example/project.git', 'main');
echo $checkout['path'], PHP_EOL; // Same /data mount in the application, optional.
$updated = $vcs->update($checkout['workspace'], [
    ['path' => 'hello.txt', 'content' => base64_encode("Hello from RPC\n")],
], $checkout['revision'], $checkout['generation']);
// Keep this ID and these arguments if retrying after a transport timeout.
$requestId = bin2hex(random_bytes(16));
$committed = $vcs->commit($checkout['workspace'], 'Update hello', $updated['revision'], $updated['generation'], true, $requestId);
$files = $vcs->read($checkout['workspace'], ['hello.txt']);
echo base64_decode($files['files'][0]['content'], true);
$archive = $vcs->archive($checkout['workspace']);
file_put_contents('revision.zip', base64_decode($archive['content'], true));
$connection->close();
