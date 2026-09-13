<?php
// Unabhängige Alternative zu checkout() in workflow.php; $vcs stammt aus dessen Verbindungsaufbau.
// Beim Git-Anbieter muss example/new-project.git bereits als leeres Remote existieren.
// create() legt das lokale Repository an, erstellt kein GitHub-Projekt und kontaktiert das Remote nicht.
$repository = $vcs->create('git@github.com:example/new-project.git', directory: 'new-project');
$workspace = $repository['workspace'];
echo $repository['path']; // /data/new-project; vor dem ersten Commit ist revision ein leerer String.

// Für einen späteren Aufruf genügt die gespeicherte Workspace-Kennung:
$path = $vcs->path($workspace); // /data/new-project
// Derselbe absolute Pfad ist lokal nutzbar, wenn die Anwendung das Volume ebenfalls unter /data mountet.
// Direkte Schreibzugriffe müssen mit anderen Anwendungen koordiniert werden.

$vcs->update($workspace, [
    ['path' => 'README.md', 'content' => base64_encode("# New project\n")],
]);
$vcs->commit($workspace); // Standardnachricht: Update workspace; umfasst alle Arbeitsänderungen.
$vcs->push($workspace); // Veröffentlicht den ersten Commit auf main; kein Force-Push.
