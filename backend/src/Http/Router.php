<?php declare(strict_types=1);

namespace Quermy\Http;

use Quermy\Kernel\ContainerResolverInterface;
use ReflectionClass;
use ReflectionMethod;

final class Router
{
    /** @var array<int, array{method:string, regex:string, params:string[], class:string, action:string}> */
    private array $routes = [];

    /** @param class-string[] $controllerClasses */
    public function registerControllers(array $controllerClasses): void
    {
        foreach ($controllerClasses as $class) {
            $this->registerController($class);
        }
    }

    private function registerController(string $class): void
    {
        $rc = new ReflectionClass($class);
        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Route::class) as $attr) {
                /** @var Route $route */
                $route = $attr->newInstance();
                [$regex, $params] = $this->compile($route->path);
                $this->routes[] = [
                    'method' => strtoupper($route->method),
                    'regex'  => $regex,
                    'params' => $params,
                    'class'  => $class,
                    'action' => $method->getName(),
                ];
            }
        }
    }

    /**
     * Turn '/api/databases/{db}/tables/{table}' into a regex
     * plus the ordered list of parameter names.
     *
     * @return array{0:string, 1:string[]}
     */
    private function compile(string $path): array
    {
        $params = [];
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            function ($m) use (&$params) {
                $params[] = $m[1];
                return '([^/]+)';
            },
            $path
        );
        return ['#^' . $regex . '$#', $params];
    }

    /**
     * Dispatch a request to the appropriate controller action.
     */
    public function dispatch(string $method, string $path, ContainerResolverInterface $resolver): void
    {
        $method = strtoupper($method);

        foreach ($this->routes as $r) {
            if ($r['method'] !== $method) continue;
            if (!preg_match($r['regex'], $path, $m)) continue;

            $args = [];

            foreach ($r['params'] as $i => $name) {
                $args[$name] = rawurldecode($m[$i + 1]);
            }

            $controller = $resolver->resolve($r['class']);
            $controller->{$r['action']}(...$args);

            return;
        }

        Json::error("Not found: $method $path", 404);
    }
}
