# MICX Sec VCS SDK

PHP-Client für den [MICX Git-Service](https://github.com/micx-io/micx-sec-vcs). Das SDK hält keine SSH-Schlüssel. Es übergibt Requests über einen austauschbaren Queue-Transport.

```sh
composer require micx-io/micx-sec-vcs-sdk
```

Dieser Installationsname setzt eine Veröffentlichung des Pakets voraus. Bis dahin dieses Repository klonen und `composer install` ausführen oder eine Composer-VCS-/Path-Repository-Quelle eintragen.

```php
use Micx\Vcs\MixVcs;
use Micx\Vcs\RabbitMqTransport;
use PhpAmqpLib\Connection\AMQPStreamConnection;

$mq = new AMQPStreamConnection('rabbitmq', 5672, 'micx', 'micx');
$vcs = new MixVcs(new RabbitMqTransport($mq), timeout: 120);
$w = $vcs->checkout('git@github.com:example/project.git', 'main');
echo $w['path'];
$w = $vcs->update($w['workspace'], [
    ['path'=>'hello.txt', 'content'=>base64_encode("Hello\n")],
], $w['revision'], $w['generation']);
$w = $vcs->commit($w['workspace'], 'Write hello', $w['revision'], $w['generation'], push: true);
```

[Ausführliches Beispiel](examples/workflow.php). Exchange, Queue-Binding und Reply-Format sind standardisiert; nur die bestehende AMQP-Verbindung wird übergeben. Ein anderer Connector implementiert `RpcTransport::request(array $request, float $timeout): array`.

## Methoden

`checkout`, `status`, `pull`, `commit`, `push`, `branch`, `merge`, `listing`, `read`, `update`, `archive`, `snapshot`, `release`. Schreiboperationen benötigen die zuletzt erhaltene Revision und Generation. `branch` legt einen lokalen Branch an; `push` mit optionalem Branch-Namen veröffentlicht ihn. Anschließend kann `checkout` einen eigenen Arbeitsbaum dafür anlegen. `release` löscht ausschließlich temporäre Workspaces.

`read`/`update` arbeiten auf Arbeitsdateien, `archive`/`snapshot` auf committed HEAD. Inhalte sind Base64-kodiert. `update` nutzt eine Liste von `{path, content}`, `content:null` bedeutet löschen. Maximal 100 Dateien und 2 MiB Rohdaten. Für ZIP `base64_decode($result['content'], true)` verwenden. Ein ZIP-Extractor muss Symlinks und Traversal sicher behandeln.

## Fehler und Wiederholungen

`RpcException::$errorCode` und `$details` enthalten Remote-Fehler. `CONFLICT`: Status aktualisieren und Änderung abgleichen. `PUSH_FAILED`: lokaler Commit kann existieren, Remote prüfen. `TIMEOUT` und `OUTCOME_UNKNOWN` bedeuten ausdrücklich keine bestätigte Rücknahme.

Bei Timeout dieselbe ID und exakt dieselben Parameter wiederverwenden:

```php
$id = bin2hex(random_bytes(16)); // vor dem Senden persistent speichern
$params = ['workspace'=>$w['workspace'], 'message'=>'Change', 'push'=>true,
    'expectedRevision'=>$w['revision'], 'expectedGeneration'=>$w['generation']];
$result = $vcs->call('commit', $params, $id);
// Nach Transport-Timeout nur call('commit', $params, $id) wiederholen.
```

Der Client erstellt keine automatische neue ID für einen Retry. Die Wartefrist auf eine RPC-Antwort beträgt standardmäßig 60 Sekunden (bis 300 konfigurierbar); Verbindungs- und Kanalaufbau unterliegen zusätzlich den Timeouts der übergebenen AMQP-Verbindung; eine laufende Remote-Operation wird durch Timeout nicht abgebrochen. Der Transport öffnet einen eigenen Kanal pro Aufruf, lässt aber die übergebene Verbindung offen.

## Tests

```sh
composer install
composer test
```

PHP 8.3+. Unit-Tests verwenden einen Fake-Transport. Das vollständige Protokoll und die Sicherheitsgrenzen stehen im Service-Repository unter `docs/2026-09-12-secure-vcs-rpc.md`.

## Fehler abfangen und Antworten zuordnen

```php
try {
    $result = $vcs->call('commit', $params, $id);
} catch (\Micx\Vcs\RpcException $e) {
    // Maschinenlesbar: $e->errorCode; verständliche Ursache: $e->getMessage().
    // Bei PUSH_FAILED enthält $e->details['cause'] den ursprünglichen Git-Fehler.
    // TIMEOUT / OUTCOME_UNKNOWN: Verbindung erneuern und ausschließlich gleiche ID + Parameter nutzen.
    throw $e;
}
```

SSH-Fehler (`SSH_KEY_INVALID`, `SSH_AUTH_FAILED`, `SSH_HOST_KEY_FAILED`), `REPOSITORY_UNAVAILABLE`, `REMOTE_UNREACHABLE`, `BRANCH_NOT_FOUND`, `PUSH_REJECTED` und `IO_ERROR` kommen als `RpcException` mit unterscheidbarem `errorCode` an. Repository fehlt und fehlende Berechtigung sind remote nicht immer unterscheidbar. Vollständiger [Fehlerkatalog und Worker-Verhalten](https://github.com/micx-io/micx-sec-vcs/blob/feat/secure-vcs-rpc/README.md#fehler-parallelität-und-broker-ausfall).

Der Transport erstellt pro Aufruf eine zufällige exklusive Reply-Queue und übernimmt die Request-ID als AMQP-`correlation_id`. Er ignoriert Nachrichten ohne passende Korrelation; `MixVcs` prüft zusätzlich Version, ID und Antwortstruktur im JSON. Defektes JSON und fehlerhafte Fehlerobjekte ergeben `INVALID_RESPONSE`. Die Reihenfolge der Antworten spielt keine Rolle: Der Broker-Test lässt zwei unabhängige Clients gleichzeitig anfragen und beantwortet absichtlich den zuletzt eingegangenen Request zuerst.

Für parallele PHP-Prozesse jeweils eine eigene AMQP-Verbindung nach dem Prozessstart erstellen. Geteilte Verbindungen über Threads/Fibers sind nicht als nebenläufige API unterstützt. N Service-Worker bearbeiten höchstens N Requests gleichzeitig; derselbe Workspace bleibt gesperrt, nach fünf Sekunden Wartezeit kann `BUSY` folgen. Keine globale FIFO-Zusage. Revision und Generation verhindern, dass ein wartender Schreiber einen inzwischen geänderten RPC-Zustand überschreibt.

Bei Broker-Ausfall verbindet sich das SDK nicht automatisch neu. Vor Publish schlägt ein Kanalfehler mit `UNAVAILABLE` fehl, ab Publish-Beginn ist der Ausgang `OUTCOME_UNKNOWN`, bei Ablauf der Wartefrist `TIMEOUT`. Verbindung in der Anwendung erneuern; Schreibaufrufe nach unklarem Ausgang nur mit zuvor gespeicherter ID und exakt gleichen Parametern wiederholen. Die Erstellung der AMQP-Verbindung liegt außerhalb des SDK und kann selbst eine AMQP-Exception werfen. Der Service-Supervisor bleibt bei Brokerfehlern aktiv und startet den einzelnen Worker nach 1, 2, 4, 8, 16 und maximal 30 Sekunden Pause neu; das Journal sichert bereits gespeicherte Ergebnisse. Kein Exactly-once-Versprechen bei Verlust von Broker-/Journal-Daten.
