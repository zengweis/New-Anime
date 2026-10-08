<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 极简路由。支持 {param} 路径参数与三级守卫：public / user / admin。
 */
final class Router
{
    /** @var array<int,array{method:string,regex:string,params:string[],handler:array,guard:string}> */
    private array $routes = [];
    private string $prefix = '';
    private string $guard  = 'public';

    /** 分组可继承权限级别：group('/api/me', fn, 'user') */
    public function group(string $prefix, callable $fn, ?string $guard = null): void
    {
        $oldPrefix = $this->prefix;
        $oldGuard  = $this->guard;

        $this->prefix = $oldPrefix . '/' . trim($prefix, '/');
        if ($guard !== null) {
            $this->guard = $guard;
        }

        $fn($this);

        $this->prefix = $oldPrefix;
        $this->guard  = $oldGuard;
    }

    public function get(string $p, array $h, ?string $guard = null): void   { $this->add('GET', $p, $h, $guard); }
    public function post(string $p, array $h, ?string $guard = null): void  { $this->add('POST', $p, $h, $guard); }
    public function put(string $p, array $h, ?string $guard = null): void   { $this->add('PUT', $p, $h, $guard); }
    public function delete(string $p, array $h, ?string $guard = null): void{ $this->add('DELETE', $p, $h, $guard); }

    private function add(string $method, string $path, array $handler, ?string $guard): void
    {
        $full   = $this->prefix . $path;
        $params = [];
        $regex  = '';
        $offset = 0;

        if (preg_match_all('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', $full, $mm, PREG_OFFSET_CAPTURE)) {
            foreach ($mm[0] as $i => $m) {
                $regex .= preg_quote(substr($full, $offset, $m[1] - $offset), '#');
                $regex .= '([^/]+)';
                $params[] = $mm[1][$i][0];
                $offset = $m[1] + strlen($m[0]);
            }
        }
        $regex .= preg_quote(substr($full, $offset), '#');

        $this->routes[] = [
            'method'  => $method,
            'regex'   => '#^' . $regex . '$#u',
            'params'  => $params,
            'handler' => $handler,
            'guard'   => $guard ?? $this->guard,
        ];
    }

    public function dispatch(): void
    {
        $method  = Request::method();
        $path    = Request::path();
        $allowed = [];

        foreach ($this->routes as $r) {
            if (!preg_match($r['regex'], $path, $m)) {
                continue;
            }
            if ($r['method'] !== $method) {
                $allowed[] = $r['method'];
                continue;
            }

            array_shift($m);
            $args = $r['params'] ? array_combine($r['params'], $m) : [];
            $ctx  = $this->guard($r['guard']);

            [$class, $fn] = $r['handler'];
            (new $class())->{$fn}($args ?: [], $ctx);
            return;
        }

        if ($allowed) {
            header('Allow: ' . implode(', ', array_unique($allowed)));
            Response::error('方法不允许', 405);
        }
        Response::notFound('接口不存在: ' . $path);
    }

    private function guard(string $g): ?array
    {
        return match ($g) {
            'user'  => Auth::requireUser(),
            'admin' => Auth::requireAdmin(),
            default => null,
        };
    }
}
