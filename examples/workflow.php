<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Micx\Vcs\MixVcsManager;
use Micx\Vcs\RabbitMqTransport;
use PhpAmqpLib\Connection\AMQPStreamConnection;

// Worker exposes /data; application mounts the same volume at /mix/vcs.
$connection = new AMQPStreamConnection('rabbitmq', 5672, 'micx', 'micx');
$manager = new MixVcsManager(connector: new RabbitMqTransport($connection));
$repo = $manager->getRepository('git@github.com:example/project.git', 'main');

file_put_contents($repo->getDirectory() . '/hello.txt', "Hello from SDK\n");
$repo->commit('Update hello');
$repo->pull(); // Worker resolves conflicts in favor of this workspace.
$repo->push();
$connection->close();
