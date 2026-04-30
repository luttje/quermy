<?php declare(strict_types=1);

namespace Quermy\Kernel;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Minimal dependency-injection container with three binding modes.
 *
 * bind()      — factory, fresh instance on every make() call
 * singleton() — factory, instance created once then cached for the
 *               container's lifetime
 * instance()  — register a pre-built object; always returned as-is
 *
 * The container is intentionally small: constructor injection only,
 * no auto-wiring of unregistered types, no tagged bindings. That keeps
 * it easy to reason about and easy to test.
 *
 * Testing swap-in example:
 *
 *   $container = new Container();
 *   $container->singleton(ConnectionSessionInterface::class, fn() => new FakeSession());
 *   $controller = $container->make(SomeController::class);
 */
final class Container implements ContainerResolverInterface
{
    private const MODE_TRANSIENT = 'transient';  // bind()
    private const MODE_SINGLETON = 'singleton';  // singleton()
    private const MODE_INSTANCE  = 'instance';   // instance()

    /**
     * @var array<string, array{
     *   mode:     string,
     *   factory:  (Closure(static):object)|null,
     *   resolved: object|null
     * }>
     */
    private array $bindings = [];

    /**
     * Bind a factory that is called fresh on every make().
     *
     * Use for stateful or mutable objects that must not be shared across
     * different resolution sites in the same request.
     *
     * @param class-string           $abstract
     * @param Closure(static):object $factory
     */
    public function bind(string $abstract, Closure $factory): void
    {
        $this->bindings[$abstract] = [
            'mode'     => self::MODE_TRANSIENT,
            'factory'  => $factory,
            'resolved' => null,
        ];
    }

    /**
     * Bind a factory that is called once; the result is cached for the
     * lifetime of this container instance.
     *
     * Use for services that are safe (and desirable) to share — connection
     * wrappers, session objects, loggers, etc.
     *
     * @param class-string           $abstract
     * @param Closure(static):object $factory
     */
    public function singleton(string $abstract, Closure $factory): void
    {
        $this->bindings[$abstract] = [
            'mode'     => self::MODE_SINGLETON,
            'factory'  => $factory,
            'resolved' => null,
        ];
    }

    /**
     * Register an already-constructed object. Equivalent to singleton() but
     * skips the factory step because you've done the construction yourself.
     *
     * Ideal for objects that exist before the container (e.g. the session
     * object created right after session_start()), or for injecting test
     * doubles without a factory wrapper:
     *
     *   $container->instance(ConnectionSessionInterface::class, $mockSession);
     *
     * @param class-string $abstract
     */
    public function instance(string $abstract, object $concrete): void
    {
        $this->bindings[$abstract] = [
            'mode'     => self::MODE_INSTANCE,
            'factory'  => null,
            'resolved' => $concrete,
        ];
    }

    /**
     * Resolve $abstract according to its registered binding mode.
     *
     * @param  class-string $abstract
     * @throws RuntimeException when no binding is registered.
     */
    public function get(string $abstract): object
    {
        if (!isset($this->bindings[$abstract])) {
            throw new RuntimeException(
                "No binding registered for '{$abstract}'. "
                . "Call bind(), singleton(), or instance() first."
            );
        }

        $entry = &$this->bindings[$abstract];

        return match ($entry['mode']) {
            self::MODE_INSTANCE  => $entry['resolved'],
            self::MODE_SINGLETON => $entry['resolved'] ??= ($entry['factory'])($this),
            self::MODE_TRANSIENT => ($entry['factory'])($this),
        };
    }

    /**
     * Reflect-construct $class, resolving each constructor parameter from
     * the registered bindings. Falls back to parameter defaults for optional
     * params; throws for anything it cannot resolve.
     *
     * @param  class-string $class
     * @throws RuntimeException on unresolvable parameters.
     */
    public function make(string $class): object
    {
        $rc   = new ReflectionClass($class);
        $ctor = $rc->getConstructor();

        if ($ctor === null) {
            return new $class();
        }

        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            $name = $type instanceof ReflectionNamedType ? $type->getName() : null;

            if ($name !== null && isset($this->bindings[$name])) {
                $args[] = $this->get($name);
                continue;
            }

            if ($param->isOptional()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            throw new RuntimeException(
                "Cannot resolve parameter \${$param->getName()} of type "
                    . ($name ?? 'mixed') . " for {$class}. "
                    . "Register a binding with bind(), singleton(), or instance()."
            );
        }

        return $rc->newInstanceArgs($args);
    }

    /**
     * @param class-string $class
     */
    public function resolve(string $class): object
    {
        return $this->make($class);
    }
}
