<?php declare(strict_types=1);

namespace Tests\Support;

use Quermy\Drivers\DriverInterface;

/**
 * Pest's beforeAll runs in a static context — there's no $this to hang
 * shared state on. We boot one container per engine per file, so we keep
 * that state in a small registry indexed by engine id.
 *
 * The registry also makes teardown idempotent: if a test file is
 * interrupted, the next run starts fresh.
 */
final class BootedEngine
{
    /** @var array<string,self> */
    private static array $booted = [];

    public function __construct(
        public readonly object $container,
        public readonly DriverInterface $driver,
        public readonly string $database,
    ) {}

    public static function set(string $engineId, self $booted): void
    {
        self::$booted[$engineId] = $booted;
    }

    public static function get(string $engineId): ?self
    {
        return self::$booted[$engineId] ?? null;
    }

    public static function tearDown(string $engineId): void
    {
        if (!isset(self::$booted[$engineId])) {
            return;
        }
        $booted = self::$booted[$engineId];
        try {
            $booted->driver->disconnect();
        } catch (\Throwable) {
            // Swallow — we're tearing down anyway.
        }
        try {
            if (method_exists($booted->container, 'stop')) {
                $booted->container->stop();
            }
        } catch (\Throwable) {
            // Swallow.
        }
        unset(self::$booted[$engineId]);
    }
}
