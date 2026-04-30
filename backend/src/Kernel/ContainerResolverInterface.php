<?php declare(strict_types=1);

namespace Quermy\Kernel;

/**
 * Resolves a controller class name to a fully constructed instance.
 *
 * The canonical implementation is Container, but any object that can turn a
 * class name into an instance — a test double, a decorated container, a
 * factory registry — can satisfy this interface without touching the router.
 *
 * Example test double:
 *
 *   $resolver = new class implements ContainerResolverInterface {
 *       public function resolve(string $class): object {
 *           return new $class(new FakeConnectionSession());
 *       }
 *   };
 *
 *   $router->dispatch('GET', '/api/status', $resolver);
 */
interface ContainerResolverInterface
{
    /**
     * Build and return an instance of $class with all dependencies injected.
     *
     * @param  class-string $class Fully-qualified class name to instantiate.
     * @return object              The constructed controller instance.
     *
     * @throws \RuntimeException  If a required dependency cannot be resolved.
     */
    public function resolve(string $class): object;
}
