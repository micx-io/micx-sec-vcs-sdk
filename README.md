# MICX Sec VCS SDK

Git-Repositories aus PHP verwalten, ohne der Anwendung SSH-Schlüssel zu geben. Das SDK steuert den [VCS-Service](https://github.com/micx-io/micx-sec-vcs/pull/1) über RabbitMQ: Repository öffnen, Arbeitsdateien ändern, committen und pushen.

PHP 8.3+. Dieser Stand ist noch kein veröffentlichtes Composer-Release: PR-Branch klonen und `composer install` ausführen oder das Repository als Composer-VCS-/Path-Quelle einbinden. Paketname: `micx-io/micx-sec-vcs-sdk`.

## 1. Bestehendes Repository bearbeiten

Der Service läuft im gleichen Compose-Netz. Der folgende Anwendungsausschnitt setzt Composer-Autoloading voraus; die Beispiel-URL wird durch ein Repository ersetzt, auf das der Service-Key Zugriff hat.

```php
use Micx\Vcs\MixVcs;
use Micx\Vcs\RabbitMqTransport;
use PhpAmqpLib\Connection\AMQPStreamConnection;

$connection = new AMQPStreamConnection('rabbitmq', 5672, 'micx', 'micx');
$vcs = new MixVcs(new RabbitMqTransport($connection));

$repository = $vcs->checkout('git@github.com:example/project.git', 'main');
$workspace = $repository['workspace'];
$vcs->update($workspace, [
    ['path' => 'hello.txt', 'content' => base64_encode("Hello\n")],
]);
$vcs->commit($workspace);
$vcs->push($workspace);
```

`commit($workspace)` erfasst alle Arbeitsänderungen mit der Standardnachricht `Update workspace`. Eine eigene Nachricht ist optional: `commit($workspace, 'Add hello')`. `push` veröffentlicht den Commit; `commit($workspace, 'Add hello', push: true)` kombiniert beide Schritte. Ohne Änderungen entsteht kein weiterer Commit.

Die Workspace-Kennung genügt für Folgeaufrufe. Revision, Generation und Request-ID müssen im normalen Anwendungscode nicht verwaltet werden. `checkout` gibt einen vorhandenen Workspace unverändert zurück; zum Aktualisieren `pull` verwenden.

## 2. Neues Repository und Workspace-Pfad

`create` legt ein leeres lokales Git-Repository mit privatem Git-Verzeichnis im Service an. Die angegebene Remote-URL wird erst beim Push verwendet. Das Remote-Repository muss beim Git-Anbieter bereits existieren; das SDK erstellt kein GitHub-Projekt.

```php
$repository = $vcs->create('git@github.com:example/new-project.git', directory: 'new-project');
$workspace = $repository['workspace'];
echo $repository['path'];        // /data/new-project
echo $vcs->path($workspace);     // Derselbe Pfad bei späteren Aufrufen.
```

`directory` ist relativ zum Service-Verzeichnis `/data`; ohne Angabe wählt der Service einen stabilen Pfad. Standardbranch von `create` ist `main`. Vor dem ersten Commit ist `revision` ein leerer String. Danach `update`, `commit` und `push` wie im Einstieg verwenden. Ein belegtes fremdes Ziel wird nicht überschrieben.

`path` ist ein Service-Pfad. Die Anwendung kann ihn direkt nutzen, wenn dasselbe Volume ebenfalls unter `/data` gemountet ist. RPC-Dateioperationen benötigen keinen Mount. Direkte Dateischreiber müssen sich untereinander koordinieren.

## 3. Änderungen holen und Konflikte lösen

Zuerst lokale Änderungen committen, dann `$vcs->pull($workspace)`, anschließend `$vcs->push($workspace)`. Bei Konflikten gewinnt die eigene Workspace-Seite: lokale Inhalte oder lokale Löschungen. Konfliktfreie Remote-Änderungen bleiben erhalten. Textkonflikte werden auf Ebene der überlappenden Änderungen gelöst; bei Binärkonflikten gewinnt die eigene Datei. Für einen anderen Remote-Branch gilt dasselbe mit `$vcs->merge($workspace, 'feature/content')`.

Uncommittete Änderungen ergeben `DIRTY_WORKTREE`. Technische Mergeprobleme ergeben `MERGE_FAILED` mit Ursache in `details.cause`; Status vor dem Weiterarbeiten prüfen. Push verwendet niemals Force. Bei `PUSH_REJECTED` wegen neuer Remote-Commits erneut pullen und pushen; Branchschutz und Server-Hooks müssen separat geklärt werden.

## 4. Timeout und Fehlerbehandlung

```php
$vcs = new MixVcs(new RabbitMqTransport($connection), timeout: 15);

try {
    $vcs->commit($workspace, 'Update content', push: true);
} catch (\Micx\Vcs\OperationTimeoutException $e) {
    $request = $e->request; // Für eine spätere, bewusste Wiederholung aufbewahren.
}
```

`timeout` gilt in Sekunden pro RPC-Aufruf: standardmäßig 60, endlicher Wert > 0 bis 300. Ein Ablauf wirft `OperationTimeoutException`, eine Unterklasse von `RpcException` mit `errorCode = TIMEOUT`. Die AMQP-Verbindung wird von der Anwendung aufgebaut; Verbindungs- und Kanalaufbau unterliegen zusätzlich deren eigenen Zeitgrenzen.

Der Timeout beendet das Warten, bestätigt aber keinen Abbruch oder Rollback im Service. Bei unklarem Ausgang nicht einfach erneut `commit` mit neuer Identität aufrufen. Die Exception enthält automatisch den vollständigen Request; nach Prüfung und gegebenenfalls neuer Verbindung mit `$vcs->call($request['method'], $request['params'], $request['id'])` wiederholen. Das Service-Journal gibt gespeicherte Ergebnisse zurück. Ein begonnener Request ohne gespeichertes Ergebnis bleibt `OUTCOME_UNKNOWN`.

Andere Fehler liefern `RpcException::$errorCode`, `getMessage()` und `$details`:

| Fehler | Behandlung |
|---|---|
| `PUSH_FAILED` | Lokaler Commit existiert möglicherweise; `details.cause` und Remote prüfen, anschließend separat pushen. |
| `UNAVAILABLE` | Kanal vor Publish nicht verfügbar; Verbindung und Broker prüfen. |
| `OUTCOME_UNKNOWN`, `INVALID_RESPONSE` | Ausgang nicht gesichert; Workspace/Remote prüfen. Für dauerhafte Wiederaufnahme kann `call` mit zuvor gespeicherter ID und Payload verwendet werden. |
| `SSH_KEY_INVALID`, `SSH_AUTH_FAILED`, `SSH_HOST_KEY_FAILED` | Service-Key, Berechtigungen beziehungsweise geprüfte Hostschlüssel korrigieren. |
| `REPOSITORY_UNAVAILABLE`, `REMOTE_UNREACHABLE` | Remote-Adresse, Zugriff beziehungsweise Netzwerk prüfen. |
| `BUSY` | Workspace-Sperre nach fünf Sekunden nicht verfügbar; später erneut versuchen. |
| `CONFLICT` | Nur bei ausdrücklich übergebenen Zustandsvorbedingungen: Status abgleichen. |

## Beispiele in Lesereihenfolge

Nur `workflow.php` ist eigenständig ausführbar. Die nummerierten Folgebeispiele sind PHP-Anwendungsausschnitte; sie nennen jeweils, welchen Kontext sie ergänzen oder ersetzen.

1. [Repository bearbeiten](examples/workflow.php): Verbindung, Checkout, Dateien, Commit und Push.
2. [Neues Repository und Pfad](examples/02-create-repository.php): leeres Repository, erster Commit und erster Push.
3. [Konflikte](examples/03-conflicts.php): Pull und Merge zugunsten der eigenen Version.
4. [Timeout](examples/04-timeout.php): Konfiguration, Exception und bewusste Wiederaufnahme.

## Weitere Operationen und Betrieb

`status` liefert Pfad, Revision, Generation und NUL-separierten Git-Porcelain-Status. `listing` listet eine Verzeichnisebene. `read` und `update` übertragen Arbeitsdateien als Base64; `content: null` löscht. Maximal 100 Dateien und 2 MiB Rohdaten pro Transfer; mehrere Dateiersetzungen sind nicht als Gesamtoperation atomar.

`archive` liefert committed HEAD als Base64-ZIP, `snapshot` dessen reguläre Dateien; beide benötigen mindestens einen Commit. `branch` erzeugt einen lokalen Branch ohne Wechsel des Workspace-Branches; `push($workspace, branch: 'feature/demo')` veröffentlicht ihn. Ein anschließender Checkout öffnet ihn in einem separaten Workspace. `release` löscht ausschließlich temporäre Workspaces.

Der Service serialisiert RPC-Aufrufe pro Workspace. Ohne explizite Vorbedingungen arbeitet jeder Aufruf auf dem dann aktuellen Zustand. Anwendungen, die Änderungen seit einem gelesenen Stand erkennen müssen, können bei `call` optional `expectedRevision` und `expectedGeneration` übergeben. Diese Prüfung erfasst keine externen Dateischreiber.

Ein anderer Transport implementiert `RpcTransport::request(array $request, float $timeout): array`. Der RabbitMQ-Transport ordnet Antworten über ID und Korrelation zu; er verbindet sich nicht automatisch neu. Pro parallelem PHP-Prozess eine eigene Verbindung verwenden. Keine nebenläufig geteilten Verbindungen über Threads/Fibers.

## Entwicklung

`composer install` und `composer test`. Das [Service-Protokoll](https://github.com/micx-io/micx-sec-vcs/blob/feat/secure-vcs-rpc/docs/2026-09-12-secure-vcs-rpc.md) beschreibt Journal, Grenzen und Betriebsverhalten. SDK und Service aus den zugehörigen PRs gemeinsam aktualisieren: Die vereinfachten Methodensignaturen ersetzen die bisherigen Entwurfssignaturen.
