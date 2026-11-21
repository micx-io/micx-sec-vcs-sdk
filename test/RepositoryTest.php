<?php
declare(strict_types=1);

use Micx\Vcs\MixVcsManager;
use Micx\Vcs\Exception\InternalException;
use Micx\Vcs\RpcTransport;
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

        $manager = new MixVcsManager('/mix/vcs', $transport);
        $repo = $manager->getRepository('git@example.com:a.git', 'main');
        self::assertSame('/mix/vcs/example', $repo->getDirectory());
        $repo->commit('Commit');
        $repo->pull();
        $repo->push();
        self::assertSame(['checkout', 'commit', 'pull', 'push'], $transport->methods);
        $this->expectException(InternalException::class);
        $repo->hasUnpushedChanges();
    }

    public function testDisabledMockCannotBeUsedWithoutConnector(): void
    {
        $directory = sys_get_temp_dir();
        $manager = new MixVcsManager();
        $manager->addMockDir('demo', 'main', $directory);
        $this->expectException(InternalException::class);
        $manager->getRepository('demo', 'main');
    }
}
