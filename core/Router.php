<?php
declare(strict_types=1);

namespace RankMathWebapp\Core;

/**
 * Table-driven router. Rows come from config/routes.php:
 *   [METHOD, '/path/{id}', 'Class@method', ['public' => bool, 'limit' => 'group', 'upload' => bool]]
 * `{id}` matches digits only; any other {name} matches one path segment.
 */
final class Router
{
    /** @var array<int,array{method:string,path:string,regex:string,handler:string,opts:array}> */
    private array $routes = [];

    /** @param array<int,array> $table */
    public function __construct(array $table)
    {
        foreach ($table as $row) {
            [$method, $path, $handler] = $row;
            $regex = preg_replace_callback(
                '/\{([a-zA-Z0-9_]+)\}/',
                static fn (array $m): string => '(?P<' . $m[1] . '>' . ($m[1] === 'id' ? '[0-9]{1,18}' : '[^/]+') . ')',
                $path
            );
            $this->routes[] = [
                'method' => $method, 'path' => $path, 'regex' => '#^' . $regex . '$#',
                'handler' => $handler, 'opts' => $row[3] ?? [],
            ];
        }
    }

    /** @return array<int,array> */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * @return array{route:array,params:array<string,string>}
     * @throws ResponseException 404 / 405
     */
    public function match(string $method, string $path): array
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m)) {
                if ($route['method'] === $method) {
                    $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                    return ['route' => $route, 'params' => $params];
                }
                $allowed[] = $route['method'];
            }
        }
        if ($allowed) {
            Response::error('Method not allowed', 405, [], ['Allow' => implode(', ', array_unique($allowed))]);
        }
        Response::error('Route not found', 404);
    }

    /** Replace {placeholders} with sample values (used by tests to hit every route). */
    public static function samplePath(string $path): string
    {
        return (string)preg_replace('/\{[a-zA-Z0-9_]+\}/', 'x', str_replace('{id}', '1', $path));
    }
}
