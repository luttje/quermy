<?php declare(strict_types=1);

namespace Tests\Support;

/**
 * Tiny helpers for the per-engine boot phase.
 *
 * The test suite needs Docker to be running. When it isn't, we'd rather
 * skip the whole engine's test file than have it fail with a confusing
 * connection error halfway through the first test.
 */
final class ContainerSupport
{
    /**
     * Skip the current test (or all tests in the file when called from
     * beforeAll) with a clear, actionable message if the named class
     * isn't loaded — usually because composer install hasn't been run.
     */
    public static function skipIfNotInstalled(string $class, string $package): void
    {
        if (!class_exists($class)) {
            test()->markTestSkipped(
                "The $package package is not installed. "
                . 'Run `composer install` and make sure dev dependencies are pulled in.'
            );
        }
    }

    /**
     * Best-effort check that the local Docker daemon is reachable.
     *
     * Testcontainers will eventually fail with a noisy stack trace when
     * Docker isn't running; this gives contributors a one-line hint
     * before that happens.
     */
    public static function skipIfDockerUnavailable(): void
    {
        $output = [];
        $code   = 1;
        @exec('docker info > /dev/null 2>&1', $output, $code);
        if ($code !== 0) {
            test()->markTestSkipped(
                'Docker is not running or not on PATH. '
                . 'Start Docker Desktop (or your local engine) and retry. '
                . 'See CONTRIBUTING.md for setup details.'
            );
        }
    }
}
