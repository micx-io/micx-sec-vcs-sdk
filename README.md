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

Der Client erstellt keine automatische neue ID für einen Retry. Ein synchroner Aufruf blockiert standardmäßig maximal 60 Sekunden (bis 300 konfigurierbar); eine laufende Remote-Operation wird durch Timeout nicht abgebrochen. Der Transport öffnet einen eigenen Kanal pro Aufruf, lässt aber die übergebene Verbindung offen.

## Tests

```sh
composer install
composer test
```

PHP 8.3+. Unit-Tests verwenden einen Fake-Transport. Das vollständige Protokoll und die Sicherheitsgrenzen stehen im Service-Repository unter `docs/2026-09-12-secure-vcs-rpc.md`.
