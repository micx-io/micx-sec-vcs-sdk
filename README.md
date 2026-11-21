# MICX Sec VCS SDK

PHP 8.3+ library for using the **same repository API** in local development
and production. Switching between a fake local directory and a real Git
checkout should require only configuration, not alternative application
workflows. This removes development-environment overhead and allows the
same application to use a mix of mocks and actual repositories.

## Design and security

In production, only the isolated [MICX Sec VCS worker](https://github.com/micx-io/micx-sec-vcs)
owns SSH credentials and executes Git operations. The PHP SDK sends only
checkout, pull, commit, push and compact Git metadata requests over RabbitMQ.
**No repository files are copied or transmitted over RabbitMQ.** Instead, all
services mount the worker's shared volume, so multiple independent service
instances can work with the same checked-out files. Coordinate simultaneous
writes to a workspace.

Mocks use ordinary **local directories**, including paths outside the shared
volume, without requiring RabbitMQ, SSH or Git. Their JSON journal is not a
Git repository or a complete file-version history: mock `pull()` is a no-op,
and mock `push()` records the published commit in local metadata.

## One complete example

[examples/mixed-repositories.php](examples/mixed-repositories.php) demonstrates **mixed mode**:
a RabbitMQ URL with explicit shared directory, one SSH URL mapped to a mock
directory, and another SSH URL checked out by the worker. The same file also
shows the pure-mock constructor alternatives as commented lines.

A short configuration overview:

```php
$manager = new MixVcsManager(
    sharedDirectory: '/mix/vcs',
    connector: 'rabbitmq://user:password@rabbitmq:5672'
);

$manager->setAllowMockDir(true);
$mockDirectory = '/tmp/local-repo1'; // Must exist.
$manager->addMockDir('git@github.com:example/repo1.git', 'main', $mockDirectory);

$repo1 = $manager->getRepository('git@github.com:example/repo1.git', 'main');
$repo2 = $manager->getRepository('git@github.com:example/repo2.git', 'main');
```

The mock lookup compares the **full repository identifier and branch exactly**,
so a Git SSH URL can map to a local folder even when the actual remote does
not exist. If no match exists, a configured connector checks out the real
repository. **Without a connector**, an unmapped repository raises
`RepositoryNotFoundException` with `MIX-VCS-3005`, not a connection error.

With **no** remote connector, simply use `new MixVcsManager()`,
`new MixVcsManager(connector: 'mock')` or `connector: null` and register
mock mappings. `file`, `mock://...` and `file://...` are also mock-only
selectors. A mock mapping is only active after `setAllowMockDir(true)`.

For **any** real connector, including a supplied `RpcTransport`, the caller
must **explicitly set** `sharedDirectory:` to an existing readable absolute
directory. There is no silent default path or implicit directory creation.
The worker's `/data` volume must be mounted there for the PHP process;
the SDK maps the worker's workspace path into that mount.

The RabbitMQ URL supports an optional port (default 5672), user/password and
virtual host path. Percent-encode reserved characters in credentials.
Connections are lazy: looking up a registered mock does not contact RabbitMQ.
The internal `Micx\\Vcs\\Transport\\TransportFactory` validates the URL and,
for real checkouts, opens and verifies a RabbitMQ connection and channel.
`Micx\\Vcs\\Transport\\RabbitMqTransport` holds broker-specific transport
logic; further transport types can be added under `src/Transport/`.
Applications normally do not need to construct transport objects themselves.

## Repository API

`getRepository($url, $branch, pull: true)` ensures a checkout exists and,
by default, also pulls changes from the remote before returning. Set
`pull: false` when you only want the existing checkout without refreshing.
The mock backend accepts the same flag, but its pull is a no-op. An automatic
pull never pushes. `sync()` performs an explicit **pull followed by push**;
it does not commit uncommitted files. Existing worker rules still apply, for
example a dirty working tree may prevent a pull.

`getRepository()` returns `VcsRepository` with
`getDirectory()`, `pull()`, `sync()`, `commit()`, `push()`,
`hasUnpushedChanges()`, `getHistory()` and `getBranches()`.
History and branches use `Micx\Vcs\Dto\LogEntry` and
`Micx\Vcs\Dto\BranchInfo` respectively.

The worker currently needs additional RPC support for `log`, `branches`
and `status.hasUnpushedChanges` on real repositories. Those calls fail
explicitly until the worker supports them. Mock implementations are available.
The former `MixVcs` API has been removed without backward compatibility.

## Errors

Exceptions expose stable `MIX-VCS-xxxx` error codes and distinguish
internal/connection faults from repository, authorization and filesystem
problems. See [the error code catalog](docs/error-codes.md) for remedies.
In particular, `MIX-VCS-1006` denotes a missing or invalid shared mount,
and `MIX-VCS-3005` an unmapped mock repository without a remote connector.

Run `composer install` and `composer test` to validate the checkout.
