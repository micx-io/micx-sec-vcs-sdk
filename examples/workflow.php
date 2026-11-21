<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Micx\Vcs\MixVcsManager;
// Worker exposes /data; application mounts the same volume at /mix/vcs.
// RabbitMQ connection is established only when a real repository is requested.
$manager = new MixVcsManager(connector: 'rabbitmq://micx:micx@rabbitmq:5672');
$repo = $manager->getRepository('git@github.com:example/repo1.git', 'main');

file_put_contents($repo->getDirectory() . '/hello.txt', "Hello from SDK\n");
$repo->commit('Update hello');
$repo->pull(); // Worker resolves conflicts in favor of this workspace.
$repo->push();
