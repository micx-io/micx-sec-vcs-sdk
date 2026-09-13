<?php
// Ergänzt workflow.php nach einem lokalen Commit; $vcs und $workspace sind dort eingeführt.
// Beispiel: hello.txt wurde lokal auf "Hallo" und remote auf "Hello" geändert.
// Uncommittete Änderungen zuerst committen, sonst folgt DIRTY_WORKTREE.
$vcs->pull($workspace);
// Bei überlappenden Änderungen gewinnt "Hallo" aus diesem Workspace.
// Konfliktfreie Remote-Änderungen werden übernommen; es wird kein gesamter Remote-Stand verworfen.
// Auch bei Binär- und Lösch-/Änderungskonflikten gewinnt die eigene Seite.
$vcs->push($workspace);

// Alternative zum Pull des aktuellen Branches: einen anderen Remote-Branch integrieren.
// Ersetzt für diesen Anwendungsfall den pull()-Aufruf oben; dieselbe Konfliktstrategie gilt.
$vcs->merge($workspace, 'feature/content');
$vcs->push($workspace);
// Technische Mergefehler bleiben MERGE_FAILED. Push-Ablehnungen werden nicht durch Force umgangen.
