<?php
declare(strict_types=1);

// Extends examples/02-mock-repository.php after commit() and push().
foreach ($repo->getHistory() as $entry) {
    echo $entry->id, ': ', $entry->message, PHP_EOL;
}
foreach ($repo->getBranches() as $branch) {
    echo $branch->name, ' latest: ', $branch->latestCommit?->message ?? '(none)', PHP_EOL;
}
