# MICX Sec VCS SDK

PHP SDK für den Zugriff auf Git-Repositories über einen isolierten VCS-Worker.
Die Anwendung benötigt keinen Git-SSH-Key: Er liegt als Secret ausschließlich
beim Worker. Zwischen Anwendung und Worker liegt RabbitMQ; die Dateien
stehen über ein gemeinsam gemountetes Volume bereit.

Das gleiche API funktioniert lokal ohne Worker. Mock-Verzeichnisse können
direkt im Anwendungsrepository liegen. Dadurch ist zwischen Entwicklung
und Produktion keine eigene Git-Implementierung oder aufwendige
Fallunterscheidung erforderlich.

## Verwendung

```php
use Micx\Vcs\MixVcsManager;

$manager = new MixVcsManager(
    sharedDirectory: '/vcs-share',
    connector: 'rabbitmq://user:password@rabbitmq:5672/micx.vcs.v1'
);

// Nur für lokale Entwicklung:
if ($developmentMode) {
    $manager->setAllowMockDir(true);
    $manager->addMockDir(
        'git@github.com:example/repo1.git',
        'main',
        __DIR__ . '/mock-repos/repo1'
    );
}

$repo = $manager->getRepository('git@github.com:example/repo1.git');
$directory = $repo->getDirectory();
```

Das Verzeichnis `/vcs-share` muss existieren und direkt als Root-Mount
im App-Container bereitstehen. Im Worker ist dasselbe Volume unter
`/data` eingebunden. Auch ein Mock-Verzeichnis muss bereits existieren.

Der RabbitMQ-URL-Pfad bezeichnet den Topic-Exchange. Benutzername und
Passwort dürfen im Connection-String stehen, wenn RabbitMQ ausschließlich
über ein abgeschottetes privates Docker-Netzwerk erreichbar ist. Zugangsdaten
dürfen dennoch nicht in Git eingecheckt oder protokolliert werden.

**Mock-Modus ist standardmäßig aus.** Nur bei explizitem
`setAllowMockDir(true)` werden hinterlegte Mock-Verzeichnisse verwendet.
Andernfalls nimmt der Manager ausschließlich gültig formatierte
Git-Remote-URLs an; lokale Pfade und `file://` werden abgelehnt.
Ohne RabbitMQ kann lokal auch `new MixVcsManager()` verwendet werden.

## Repository-API

`getRepository()` liefert nur einen Repository-Handle: kein Checkout,
kein Pull und keine Netzwerkübertragung. `getDirectory()` liest ein
bereits bestehendes Verzeichnis ohne RPC. Existiert noch kein Checkout,
wird eine Exception geworfen. Ein separater Job kann dann `pull()`
ausführen. `sync()` führt erst Pull, dann Push aus und erstellt keinen
automatischen Commit.

Weitere Methoden: `ping($timeout = 5.0)` (Mock: sofort `true`),
`commit()`, `push()`, `hasUnpushedChanges()`, `getHistory()`,
`getBranches()`. Alle Backends implementieren
`Micx\Vcs\Repository\VcsRepository`.

Siehe [das einzige vollständige Beispiel](examples/mixed-repositories.php).
