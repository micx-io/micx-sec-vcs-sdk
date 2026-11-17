<?php
// Ersetzt die Client-Konstruktion aus workflow.php; $connection bleibt die dort aufgebaute Verbindung.
$vcs = new \Micx\Vcs\MixVcs(new \Micx\Vcs\RabbitMqTransport($connection), timeout: 15);
// Sekunden pro RPC-Aufruf, Standard 60; erlaubt sind endliche Werte > 0 bis einschließlich 300.

try {
    $vcs->commit($workspace, 'Update content', push: true);
} catch (\Micx\Vcs\OperationTimeoutException $e) {
    // Das Warten ist beendet. Der Service kann trotzdem bereits committed oder gepusht haben.
    // Für eine spätere bewusste Wiederholung enthält request die automatisch erzeugte ID
    // und exakt dieselben Parameter; diese Daten bei Bedarf in der Anwendung persistieren.
    $request = $e->request;
    echo $e->getMessage();
}

// Separater Wiederaufnahme-Schritt nach Prüfung der Verbindung und des Workspace-Zustands:
// $request ist der zuvor gespeicherte Request, $vcs verwendet eine funktionsfähige Verbindung.
// Nicht als automatischen Retry an den catch-Block anhängen.
// $result = $vcs->call($request['method'], $request['params'], $request['id']);
