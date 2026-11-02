<?php
/**
 * Minimal router: path patterns with {placeholders}, named routes and
 * string based middleware ("auth", "guest", "csrf", "setup", "api").
 */

namespace App\Core;

final class Router
{
    private $routes = array();
    private $names = array();

    public function get($path, $handler, $middleware = array())
    {
        return $this->add('GET', $path, $handler, $middleware);
    }

    public function post($path, $handler, $middleware = array())
    {
        return $this->add('POST', $path, $handler, $middleware);
    }

    public function put($path, $handler, $middleware = array())
    {
        return $this->add('PUT', $path, $handler, $middleware);
    }

    public function patch($path, $handler, $middleware = array())
    {
        return $this->add('PATCH', $path, $handler, $middleware);
    }

    public function delete($path, $handler, $middleware = array())
    {
        return $this->add('DELETE', $path, $handler, $middleware);
    }

    public function any($path, $handler, $middleware = array())
    {
        return $this->add('ANY', $path, $handler, $middleware);
    }

    public function add($method, $path, $handler, $middleware = array(), $name = null)
    {
        $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', rtrim($path, '/'));
        if ($pattern === '') {
            $pattern = '/';
        }
        $route = array(
            'method' => strtoupper($method),
            'path' => $path,
            'regex' => '#^' . $pattern . '$#',
            'handler' => $handler,
            'middleware' => (array) $middleware,
            'name' => $name,
        );
        $this->routes[] = $route;
        $index = count($this->routes) - 1;
        if ($name !== null) {
            $this->names[$name] = &$this->routes[$index];
        }
        return new Route($this, $index);
    }

    public function setMiddleware($index, $middleware)
    {
        $this->routes[$index]['middleware'] = array_merge($this->routes[$index]['middleware'], (array) $middleware);
    }

    public function setName($index, $name)
    {
        $this->routes[$index]['name'] = $name;
        $this->names[$name] = $index;
    }

    public function path($name, array $params = array())
    {
        foreach ($this->routes as $route) {
            if ($route['name'] === $name) {
                $path = $route['path'];
                foreach ($params as $key => $value) {
                    $path = str_replace('{' . $key . '}', rawurlencode((string) $value), $path);
                }
                return $path === '' ? '/' : $path;
            }
        }
        return '/';
    }

    public function dispatch()
    {
        $method = Request::method();
        $path = Request::path();
        $allowed = array();
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            if ($route['method'] !== 'ANY' && $route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = array();
            foreach ($matches as $key => $value) {
                if (!is_int($key)) {
                    $params[$key] = $value;
                }
            }
            return $this->run($route, $params);
        }
        if ($allowed) {
            Response::abort(405, '不支持的请求方法: ' . $method);
        }
        Response::abort(404, '页面不存在: ' . $path);
    }

    private function run(array $route, array $params)
    {
        foreach ($route['middleware'] as $middleware) {
            $this->middleware($middleware);
        }
        $handler = $route['handler'];
        if (is_array($handler) && is_string($handler[0])) {
            $class = $handler[0];
            $method = $handler[1];
            $controller = new $class();
            return $controller->$method($params);
        }
        return call_user_func($handler, $params);
    }

    private function middleware($name)
    {
        switch ($name) {
            case 'auth':
                Auth::requireLogin();
                return;
            case 'guest':
                if (Auth::check()) {
                    Response::redirect('/');
                }
                return;
            case 'csrf':
                Csrf::verify();
                return;
            case 'installed':
                if (!Config::isInstalled()) {
                    Response::redirect('/setup');
                }
                return;
            case 'not_installed':
                if (Config::isInstalled()) {
                    Response::redirect('/login');
                }
                return;
            case 'api':
                Api::requireToken();
                return;
            case 'setup_token':
                return;
            default:
                return;
        }
    }
}

final class Route
{
    private $router;
    private $index;

    public function __construct(Router $router, $index)
    {
        $this->router = $router;
        $this->index = $index;
    }

    public function middleware($name)
    {
        $this->router->setMiddleware($this->index, $name);
        return $this;
    }

    public function name($name)
    {
        $this->router->setName($this->index, $name);
        return $this;
    }
}