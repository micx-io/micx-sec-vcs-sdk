<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Micx\Vcs\MixVcsManager;
use Micx\Vcs\Exception\RepositoryNotReadyException;

// Mischbetrieb: /mix/vcs ist das bereits vorhandene gemeinsame Volume
// (im VCS-Worker unter /data eingebunden). Git/SSH bleiben im Worker.
// Der URL-Pfad /micx.vcs.v1 ist der RabbitMQ-Topic-Exchange.
$manager = new MixVcsManager(
    sharedDirectory: '/mix/vcs',
    connector: 'rabbitmq://user:password@rabbitmq:5672/micx.vcs.v1'
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
var_dump($repo1->ping()); // true, sofort ohne RabbitMQ
// Bereits existierende Mock-Verzeichnisse sind sofort verfügbar, ohne Pull.
// getRepository() selbst erstellt nur das Objekt, auch bei Remote-Repositories.
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
var_dump($repo2->ping()); // Worker-RPC, standardmäßig 5 Sekunden Timeout
// $repo2->ping(2.0); // Optional kürzeres Zeitlimit
// Normaler Request: Nur lokalen Checkout-Pfad abfragen, ohne RPC.
// Ist der Checkout nicht vorhanden, muss ein anderer Prozess pull() starten.
try {
    $directory = $repo2->getDirectory();
} catch (RepositoryNotReadyException $e) {
    echo $e->getMessage(), PHP_EOL;
    // Beispiel für einen separaten Initialisierungsprozess/Job:
    $repo2->pull();
    $directory = $repo2->getDirectory();
}
file_put_contents($directory . '/hello.txt', "Hello from shared volume\n");
$repo2->commit('Update shared content');
$repo2->sync(); // Pull + Push; uncommittete Dateien werden nicht committet.

// Beide Repository-Objekte implementieren Micx\\Vcs\\Repository\\VcsRepository.
// Über RabbitMQ werden nur Git-Befehle/Metadaten, keine Dateiinhalte übertragen.
