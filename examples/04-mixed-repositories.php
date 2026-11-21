<?php
declare(strict_types=1);

// Independent example: one manager can serve a local mock and real Git checkout.
// This application runs with the VCS worker and shared volume at /mix/vcs.
require __DIR__ . '/../vendor/autoload.php';

use Micx\Vcs\MixVcsManager;

$mockDirectory = sys_get_temp_dir() . '/mix-vcs-mixed-demo';
if (!is_dir($mockDirectory)) {
    mkdir($mockDirectory, 0770, true);
}

$manager = new MixVcsManager(connector: 'rabbitmq://micx:micx@rabbitmq:5672');
$manager->setAllowMockDir(true);
$manager->addMockDir('git@github.com:example/repo1.git', 'main', $mockDirectory);

// Exact URL+branch match uses mockDirectory and does not contact RabbitMQ.
$repo1 = $manager->getRepository('git@github.com:example/repo1.git', 'main');
echo $repo1->getDirectory(), PHP_EOL;

// Different URL: the manager connects to RabbitMQ and checks out real Git.
$repo2 = $manager->getRepository('git@github.com:example/repo2.git', 'main');
echo $repo2->getDirectory(), PHP_EOL;
