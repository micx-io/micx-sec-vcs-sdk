<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Micx\Vcs\MixVcsManager;

// Independent local example. No RabbitMQ or Git process is necessary.
$directory = sys_get_temp_dir() . '/mix-vcs-demo';
if (!is_dir($directory)) {
    mkdir($directory, 0770, true);
}

$manager = new MixVcsManager(connector: 'mock');
$manager->setAllowMockDir(true);
$manager->addMockDir('git@github.com:example/repo1.git', 'main', $directory);
$repo = $manager->getRepository('git@github.com:example/repo1.git', 'main');

file_put_contents($repo->getDirectory() . '/hello.txt', "Hello mock\n");
echo $repo->hasUnpushedChanges() ? "Changed\n" : "Clean\n"; // Changed
$repo->commit('Add hello');
$repo->push();
echo $repo->hasUnpushedChanges() ? "Changed\n" : "Clean\n"; // Clean
