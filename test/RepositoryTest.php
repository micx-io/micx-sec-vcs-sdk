<?php
declare(strict_types=1);

use Micx\Vcs\MixVcsManager;
use Micx\Vcs\Exception\InternalException;
use Micx\Vcs\Exception\ConnectionException;
use Micx\Vcs\Exception\AccessDeniedException;
use Micx\Vcs\Exception\RepositoryNotFoundException;
use Micx\Vcs\Exception\ErrorCode;
use Micx\Vcs\Exception\ExceptionMapper;
use Micx\Vcs\RpcTransport;
use Micx\Vcs\Transport\TransportFactory;
use PHPUnit\Framework\TestCase;

final class RepositoryTest extends TestCase
{
    public function testMockCommitPushAndHistory(): void
    {
        $directory = sys_get_temp_dir() . '/mix-vcs-test-' . bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            $manager = new MixVcsManager();
            $manager->addMockDir('demo', 'main', $directory);
            $manager->setAllowMockDir(true);
            $repo = $manager->getRepository('demo', 'main');

            file_put_contents($directory . '/a.txt', 'one');
            self::assertTrue($repo->hasUnpushedChanges());
            $repo->commit('First');
            self::assertTrue($repo->hasUnpushedChanges());
            $repo->push();
            self::assertFalse($repo->hasUnpushedChanges());
            self::assertSame('First', $repo->getHistory()[0]->message);
            self::assertSame('main', $repo->getBranches()[0]->name);

            file_put_contents($directory . '/a.txt', 'two');
            self::assertTrue($repo->hasUnpushedChanges());
            $repo->commit('Second');
            self::assertCount(2, $repo->getHistory());
        } finally {
            foreach (glob($directory . '/{*,.*}', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }

    public function testRealRepositoryDelegatesToWorker(): void
    {
        $transport = new class implements RpcTransport {
            public array $methods = [];
            public function request(array $request, float $timeout): array
            {
                $this->methods[] = $request['method'];
                $result = $request['method'] === 'checkout'
                    ? ['workspace' => 'abc', 'path' => '/data/example']
                    : [];
                return ['version' => 1, 'id' => $request['id'], 'ok' => true, 'result' => $result];
            }
        };

        $sharedDirectory = realpath(sys_get_temp_dir());
        $manager = new MixVcsManager($sharedDirectory, $transport);
        $repo = $manager->getRepository('git@example.com:a.git', 'main');
        self::assertSame($sharedDirectory . '/example', $repo->getDirectory());
        $repo->commit('Commit');
        $repo->pull();
        $repo->push();
        self::assertSame(['checkout', 'pull', 'commit', 'pull', 'push'], $transport->methods);
        $this->expectException(InternalException::class);
        $repo->hasUnpushedChanges();
    }

    public function testCheckoutPullFlagAndSyncOrdering(): void
    {
        $transport = new class implements RpcTransport {
            public array $methods = [];
            public function request(array $request, float $timeout): array
            {
                $this->methods[] = $request['method'];
                $result = $request['method'] === 'checkout'
                    ? ['workspace' => 'abc', 'path' => '/data/project']
                    : [];
                return ['version' => 1, 'id' => $request['id'], 'ok' => true, 'result' => $result];
            }
        };

        $manager = new MixVcsManager(realpath(sys_get_temp_dir()), $transport);
        $repo = $manager->getRepository('git@example.test:repo.git', pull: false);
        self::assertSame(['checkout'], $transport->methods);
        $repo->sync();
        self::assertSame(['checkout', 'pull', 'push'], $transport->methods);
    }

    public function testMockSyncUsesLocalJournalWithoutAdditionalCommit(): void
    {
        $directory = sys_get_temp_dir() . '/mix-vcs-sync-' . bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            $manager = new MixVcsManager();
            $manager->setAllowMockDir(true);
            $manager->addMockDir('repo1.git', 'main', $directory);
            $repo = $manager->getRepository('repo1.git');
            file_put_contents($directory . '/file.txt', 'test');
            $repo->commit('Test');
            $repo->sync();
            self::assertFalse($repo->hasUnpushedChanges());
            self::assertCount(1, $repo->getHistory());
        } finally {
            foreach (glob($directory . '/{*,.*}', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }

    public function testDiagnosticErrorNumbersAndCategories(): void
    {
        $denied = ExceptionMapper::fromWorker('ACCESS_DENIED', 'Write permission missing', 'push');
        self::assertInstanceOf(AccessDeniedException::class, $denied);
        self::assertSame(ErrorCode::ACCESS_DENIED, $denied->errorCode);
        self::assertStringContainsString('MIX-VCS-2002', $denied->getMessage());

        $routing = ExceptionMapper::fromWorker('NO_ROUTE', 'Queue not bound', 'pull');
        self::assertInstanceOf(ConnectionException::class, $routing);
        self::assertSame(ErrorCode::BROKER_NOT_ROUTED, $routing->errorCode);

        $missing = ExceptionMapper::fromWorker('REPOSITORY_NOT_FOUND', 'Missing', 'checkout');
        self::assertInstanceOf(RepositoryNotFoundException::class, $missing);
        self::assertSame(ErrorCode::REPOSITORY_NOT_FOUND, $missing->errorCode);
    }

    public function testMockDirectoryMissingHasDiagnosticCode(): void
    {
        $manager = new MixVcsManager();
        try {
            $manager->addMockDir('demo', 'main', '/missing-mix-vcs-' . bin2hex(random_bytes(6)));
            self::fail('Expected missing mock directory error');
        } catch (RepositoryNotFoundException $e) {
            self::assertSame(ErrorCode::MOCK_DIRECTORY_NOT_FOUND, $e->errorCode);
        }
    }

    public function testRabbitMqUrlDoesNotConnectWhenMockMatches(): void
    {
        $directory = sys_get_temp_dir();
        $sshUrl = 'git@github.com:example/repo1.git';
        $manager = new MixVcsManager(sharedDirectory: realpath(sys_get_temp_dir()), connector: 'rabbitmq://user:password@unreachable.invalid:5672');
        $manager->setAllowMockDir(true);
        $manager->addMockDir($sshUrl, 'main', $directory);

        $repo = $manager->getRepository($sshUrl, 'main');
        self::assertSame(realpath($directory), $repo->getDirectory());
        self::assertSame('main', $repo->getBranches()[0]->name);
    }

    public function testMockSelectorsDoNotInitializeRemoteConnections(): void
    {
        foreach ([null, 'mock', 'file', 'mock://local', 'file:///tmp'] as $connector) {
            $manager = new MixVcsManager(connector: $connector);
            $manager->setAllowMockDir(true);
            $manager->addMockDir('repo1.git', 'main', sys_get_temp_dir());
            self::assertSame(realpath(sys_get_temp_dir()), $manager->getRepository('repo1.git')->getDirectory());
            try {
                $manager->getRepository('repo2.git');
            } catch (RepositoryNotFoundException $e) {
                self::assertSame(ErrorCode::MOCK_REPOSITORY_NOT_FOUND, $e->errorCode);
                continue;
            }
            self::fail('Unmapped repository should require a connector');
        }
    }

    public function testMockBranchUsesExactMapping(): void
    {
        $manager = new MixVcsManager(connector: 'file');
        $manager->setAllowMockDir(true);
        $manager->addMockDir('git@github.com:example/repo1.git', 'main', sys_get_temp_dir());

        $this->expectException(RepositoryNotFoundException::class);
        $manager->getRepository('git@github.com:example/repo1.git', 'develop');
    }

    public function testTransportFactoryRecognizesMockAndRabbitMqUrls(): void
    {
        foreach ([null, '', 'mock', 'file', 'mock://local', 'file:///tmp'] as $url) {
            self::assertFalse(TransportFactory::validate($url));
        }

        self::assertTrue(TransportFactory::validate('rabbitmq://user:pass@broker:5672'));
        self::assertTrue(TransportFactory::validate('rabbitmq://user:p%40ss@broker:5672/%2F'));
    }

    public function testTransportFactoryRejectsUnsupportedUrl(): void
    {
        $this->expectException(InternalException::class);
        TransportFactory::validate('http://broker:5672');
    }

    public function testMissingSharedDirectoryIsRejectedForRabbitMqConnector(): void
    {
        try {
            new MixVcsManager(connector: 'rabbitmq://user:pass@rabbitmq:5672');
            self::fail('Remote connector must require shared directory');
        } catch (InternalException $e) {
            self::assertSame(ErrorCode::SHARED_DIRECTORY_INVALID, $e->errorCode);
        }
    }

    public function testMissingSharedDirectoryIsRejectedForExplicitTransport(): void
    {
        $transport = $this->createMock(RpcTransport::class);
        $this->expectException(InternalException::class);
        new MixVcsManager(connector: $transport);
    }

    public function testInvalidSharedDirectoryIsRejectedBeforeAnyBrokerConnection(): void
    {
        $missing = sys_get_temp_dir() . '/mix-vcs-missing-' . bin2hex(random_bytes(6));
        try {
            new MixVcsManager(sharedDirectory: $missing, connector: 'rabbitmq://user:pass@rabbitmq:5672');
            self::fail('Missing volume should not be accepted');
        } catch (InternalException $e) {
            self::assertSame(ErrorCode::SHARED_DIRECTORY_INVALID, $e->errorCode);
        }
    }

    public function testMockOnlyDoesNotRequireSharedDirectory(): void
    {
        $manager = new MixVcsManager();
        $manager->setAllowMockDir(true);
        $manager->addMockDir('repo1.git', 'main', sys_get_temp_dir());
        self::assertSame(realpath(sys_get_temp_dir()), $manager->getRepository('repo1.git')->getDirectory());
    }

    public function testDisabledMockCannotBeUsedWithoutConnector(): void
    {
        $directory = sys_get_temp_dir();
        $manager = new MixVcsManager();
        $manager->addMockDir('demo', 'main', $directory);
        $this->expectException(RepositoryNotFoundException::class);
        $manager->getRepository('demo', 'main');
    }
}
