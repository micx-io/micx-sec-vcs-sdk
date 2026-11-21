<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Micx\Vcs\MixVcsManager;

// Der Worker stellt sein /data-Volume für alle Services bereit.
// /mix/vcs muss für einen Remote-Connector bereits existieren und lesbar sein.
$manager = new MixVcsManager(
    sharedDirectory: '/mix/vcs',
    connector: 'rabbitmq://user:password@rabbitmq:5672'
);

// Alternativen nur für lokale Entwicklung OHNE RabbitMQ oder Shared Directory:
// $manager = new MixVcsManager();
// $manager = new MixVcsManager(connector: 'mock');

// Mock-Modus und Remote-Connector können gleichzeitig aktiviert sein.
$manager->setAllowMockDir(true);
$mockDirectory = sys_get_temp_dir() . '/mix-vcs-repo1-mock';
if (!is_dir($mockDirectory)) {
    mkdir($mockDirectory, 0770, true);
}

// Die vollständige SSH-URL und der Branch sind der exakte Mock-Schlüssel.
$manager->addMockDir('git@github.com:example/repo1.git', 'main', $mockDirectory);

// Repo 1: registrierter Mock, daher KEINE RabbitMQ-Verbindung.
$repo1 = $manager->getRepository('git@github.com:example/repo1.git', 'main');
file_put_contents($repo1->getDirectory() . '/hello.txt', "Hello from mock\n");
$repo1->commit('Update mock content');
$repo1->push();
echo $repo1->hasUnpushedChanges() ? "Mock has changes\n" : "Mock is clean\n";

// Repo 2: kein Mock-Mapping. Der Connector nutzt RabbitMQ zum Checkout.
// Die Git-Dateien selbst bleiben im Shared Directory, ohne Datei-RPCs.
// Im reinen Mock-Modus löst dieser Aufruf MIX-VCS-3005 aus.
// Für einen lokalen Test ohne RabbitMQ den folgenden Block auskommentieren.
$repo2 = $manager->getRepository('git@github.com:example/repo2.git', 'main');
echo $repo2->getDirectory(), PHP_EOL;
$repo2->pull();
$repo2->commit('Update remote workspace');
$repo2->push();

// Beide Backends implementieren dasselbe VcsRepository-Interface.
// $repo1->getHistory() und $repo1->getBranches() liefern typisierte DTOs.
// Für Repo 2 müssen diese RPCs erst im Worker bereitgestellt werden.
