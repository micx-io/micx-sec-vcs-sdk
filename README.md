# MICX Sec VCS SDK

A small PHP 8.3+ API for repositories, with interchangeable local mock
directories and real Git workspaces served through the
[MICX Sec VCS worker](https://github.com/micx-io/micx-sec-vcs).

The old `MixVcs` facade is removed; **there is no backward compatibility**.

**Shared-folder architecture:** All content is read and written directly by
services through a shared volume; the SDK never uploads/downloads file
content over RabbitMQ. Only Git control operations and their compact metadata
(e.g. commit messages, branch names and status) travel over the broker.
The worker owns the actual Git checkout. Every consumer must mount the *same*
worker storage volume; `sharedDirectory` is the mount path seen by PHP.

## 1. Work with a repository

The worker shares its `/data` volume with the application at `/mix/vcs`.
The worker checks out missing repositories and reuses existing workspaces.

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Micx\Vcs\MixVcsManager;
$manager = new MixVcsManager(connector: 'rabbitmq://micx:micx@rabbitmq:5672');
$repo = $manager->getRepository('git@github.com:example/repo1.git', 'main');

file_put_contents($repo->getDirectory() . '/hello.txt', "Hello\n");
$repo->commit('Write hello.txt');
$repo->pull(); // Local version wins on conflicts, as defined by the worker.
$repo->push();

```

`getRepository()` returns `VcsRepository`, regardless of the backend.
The public API is `getDirectory()`, `pull()`, `commit()`, `push()`,
`hasUnpushedChanges()`, `getHistory()` and `getBranches()`.

The optional `connector:` accepts an existing `RpcTransport`, a
`rabbitmq://username:password@host:port` URL, or `null` (the default).
Credentials containing reserved URL characters must be percent-encoded.
An optional `/vhost` selects the RabbitMQ virtual host; otherwise `/` is used.
`mock`, `file`, `mock://...` and `file://...` also mean **no remote
connector**. Such selectors do not implicitly create a mock mapping.
The RabbitMQ connection is created **lazily** only for an unmapped repository.
A manager can thus serve both local mocks and real repositories without
connecting to the broker for a mock hit.

Configure a different local mount point with `sharedDirectory:`.
This path mapping assumes the worker exposes its storage as `/data`.

## 2. Map a local mock directory

```php
$manager = new MixVcsManager();
$manager->setAllowMockDir(true);
$manager->addMockDir('git@github.com:example/repo1.git', 'main', '/tmp/demo-project');
$repo = $manager->getRepository('git@github.com:example/repo1.git', 'main');

file_put_contents($repo->getDirectory() . '/page.md', "# Hello\n");
var_dump($repo->hasUnpushedChanges()); // true
$repo->commit('Create page');
var_dump($repo->hasUnpushedChanges()); // true: not pushed
$repo->push();
var_dump($repo->hasUnpushedChanges()); // false
```

The directory must exist and be writable. It can be **outside** the shared
directory. A mock mapping is selected only when mock mode is enabled.
The key is the **exact repository string plus branch**: SSH URLs are allowed,
and the mapping does not require the repository to exist remotely. Without a
mapping, the manager uses the configured connector or fails explicitly.
For mixed mode, initialize the same manager with a RabbitMQ URL, enable mocks,
and register only the repositories/branches that should use local folders.

Mocks store commit metadata in `.mix-vcs.json` inside the mapped directory.
They fingerprint files to detect subsequent changes. This journal is **not Git**:
it does not store file versions or provide a real remote, and `pull()` is a
no-op for mocks. History records commit metadata, not retrievable snapshots.
Each mock branch should have its own directory; mock `getBranches()` reports
the configured branch only. Do not write the same mock directory concurrently
from multiple processes without external synchronization.

## 3. Typed history and branches

```php
foreach ($repo->getHistory() as $entry) {
    echo $entry->id, ' ', $entry->message, ' ', $entry->timestamp, PHP_EOL;
}

foreach ($repo->getBranches() as $branch) {
    echo $branch->name, ' ', $branch->latestCommit?->message ?? '', PHP_EOL;
}
```

History entries use `Micx\Vcs\Dto\LogEntry`; branch entries use
`Micx\Vcs\Dto\BranchInfo`.

**Worker compatibility:** The existing worker already supports checkout,
pull, commit and push. It does **not yet** expose the `log` and `branches`
RPC operations or `status.hasUnpushedChanges` required by the new real
backend. Those three methods will fail explicitly until the worker is
extended. This SDK PR does not silently synthesize results. The old
`MixVcs` API has been removed without a compatibility alias.

## Errors and troubleshooting

All SDK failures use stable `MIX-VCS-xxxx` numbers. Catch
`Micx\Vcs\Exception\InternalException` for worker, RabbitMQ and protocol
failures; catch `Micx\Vcs\Exception\RepositoryException` for repository,
Git, permissions and mock-folder errors. Each exception contains `errorCode`,
`operation` and `details`. See the [error code table](docs/error-codes.md)
for numbered causes and remedies.

## Examples

1. [Remote workflow](examples/workflow.php)
2. [Local mock workflow](examples/02-mock-repository.php)
3. [Typed repository metadata](examples/03-history-branches.php)

Run `composer install` and `composer test` in a development checkout.
