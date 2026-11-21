<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Micx\Vcs\MixVcsManager;

// Mischbetrieb: /mix/vcs ist das bereits vorhandene gemeinsame Volume
// (im VCS-Worker unter /data eingebunden). Git/SSH bleiben im Worker.
$manager = new MixVcsManager(
    sharedDirectory: '/mix/vcs',
    connector: 'rabbitmq://user:password@rabbitmq:5672'
);

// Alternative für lokale Entwicklung: Nur Mock-Verzeichnisse, kein RabbitMQ
// und kein Shared Directory. Dazu die obige Konstruktion ersetzen:
// $manager = new MixVcsManager();
// $manager = new MixVcsManager(connector: 'mock');

// Die gleiche Repository-URL funktioniert in Mock- und Remote-Betrieb.
// Das Mock-Verzeichnis darf außerhalb des Shared Directory liegen.
$mockDirectory = sys_get_temp_dir() . '/mix-vcs-repo1-mock';
if (!is_dir($mockDirectory)) {
    mkdir($mockDirectory, 0770, true);
}

$manager->setAllowMockDir(true);
$manager->addMockDir('git@github.com:example/repo1.git', 'main', $mockDirectory);

// Repo 1: URL und Branch entsprechen dem Mapping -> lokales Mock-Verzeichnis.
// Auch bei konfiguriertem RabbitMQ wird keine Verbindung aufgebaut.
$repo1 = $manager->getRepository('git@github.com:example/repo1.git', 'main');
// getDirectory() wirft vor pull() MIX-VCS-2008, auch beim Mock.
$repo1->pull(); // Lokales Mock-Verzeichnis ohne RabbitMQ aktivieren.
file_put_contents($repo1->getDirectory() . '/hello.txt', "Hello from mock\n");
$repo1->commit('Update mock content');
$repo1->push();
echo $repo1->hasUnpushedChanges() ? "Mock changed\n" : "Mock clean\n";
foreach ($repo1->getHistory() as $entry) {
    echo $entry->message, PHP_EOL;
}

// Repo 2: kein Mock-Mapping -> nur ein Remote-Handle, noch kein Checkout.
// Ohne Connector wirft dieselbe Zeile RepositoryNotFoundException (MIX-VCS-3005).
// Im rein lokalen Modus kann dieser Block auskommentiert werden.
$repo2 = $manager->getRepository('git@github.com:example/repo2.git', 'main');
// Erst dieser explizite Pull startet den Checkout und die Aktualisierung.
$repo2->pull();
file_put_contents($repo2->getDirectory() . '/hello.txt', "Hello from shared volume\n");
$repo2->commit('Update shared content');
$repo2->sync(); // Pull + Push; uncommittete Dateien werden nicht committet.

// Beide Repository-Objekte besitzen dieselbe VcsRepository-API.
// Über RabbitMQ werden nur Git-Befehle/Metadaten, keine Dateiinhalte übertragen.
