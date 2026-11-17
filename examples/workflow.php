<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Micx\Vcs\MixVcs;
use Micx\Vcs\RabbitMqTransport;
use PhpAmqpLib\Connection\AMQPStreamConnection;

// Voraussetzung: Service im gleichen Compose-Netz; URL durch eigenes Repository ersetzen.
$connection = new AMQPStreamConnection('rabbitmq', 5672, 'micx', 'micx');
$vcs = new MixVcs(new RabbitMqTransport($connection));

$repository = $vcs->checkout('git@github.com:example/project.git', 'main');
$workspace = $repository['workspace'];
echo $repository['path'], PHP_EOL; // Absoluter Service-Pfad unter /data.

// RPC benötigt keinen lokalen /data-Mount; auch Binärdateien werden Base64-kodiert.
$vcs->update($workspace, [
    ['path' => 'hello.txt', 'content' => base64_encode("Hello from MICX\n")],
]);
$vcs->commit($workspace, 'Add hello');
$vcs->push($workspace); // Erst jetzt ist der Commit auch im Remote.

// Ohne eigene Commit-Nachricht reicht $vcs->commit($workspace).
// Commit und Push können auch mit commit($workspace, 'Add hello', push: true) erfolgen.
$files = $vcs->read($workspace, ['hello.txt']);
echo base64_decode($files['files'][0]['content'], true); // Hello from MICX
$connection->close();
