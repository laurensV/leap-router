<?php
namespace Leap;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Class Router
 *
 * @package Leap
 */
class Router
{
    /**
     * @var array
     */
    public $routeCollection;
    /**
     * @var \Leap\PluginManager
     */
    private $pluginManager;

    /**
     * @var string
     */
    private $groupPrefix;

    /**
     * Router constructor.
     */
    public function __construct()
    {
        $this->routeCollection    = [];
        $this->currentGroupPrefix = '';
    }

    /**
     * Setter injection for a Leap plugin manager instance
     *
     * @param \Leap\PluginManager $pluginManager
     */
    public function setPluginManager(PluginManager $pluginManager): void
    {
        $this->pluginManager = $pluginManager;
    }

    /**
     * Add a new file with routes
     *
     * @param string $file
     * @param string $pluginForNamespace
     */
    public function addFile(string $file, string $pluginForNamespace = null): void
    {
        if (file_exists($file)) {
            $routes = require $file;
            $path   = str_replace("\\", "/", dirname($file)) . "/";
            $routes = $this->addFileOptions($routes, $path, $pluginForNamespace);
            $this->addArray($routes);
        }
    }

    /**
     * @param array       $routes
     * @param string      $path
     * @param string|null $pluginForNamespace
     *
     * @return array
     */
    private function addFileOptions(array $routes, string $path, string $pluginForNamespace = null): array
    {
        foreach ($routes as $pattern => &$options) {
            if (strtoupper(explode(' ', $pattern)[0]) === 'GROUP') {
                $options = $this->addFileOptions($options, $path, $pluginForNamespace);
            } else {
                /* set the path of the route file */
                $options['path'] = $options['path'] ?? $path;
                /* If we have support for Leap plugins, set the plugin for the namespace */
                if (isset($this->pluginManager)) {
                    $options['plugin'] = $options['plugin'] ?? $pluginForNamespace;
                }
            }
        }
        return $routes;
    }

    /**
     * @param array $routes
     */
    public function addArray(array $routes): void
    {
        foreach ($routes as $pattern => $options) {
            if (strpos($pattern, ' ') !== false) {
                [$group, $prefix] = explode(' ', trim($pattern), 2);
                if (strtoupper($group) === 'GROUP') {
                    $previousGroupPrefix = $this->groupPrefix;
                    $this->groupPrefix   = $previousGroupPrefix . $prefix;
                    $this->addArray($options);
                    $this->groupPrefix = $previousGroupPrefix;
                    continue;
                }
            }

            $callback = null;
            if (isset($options['callback'])) {
                $callback = $options['callback'];
                unset($options['callback']);
            }

            $this->add($pattern, $callback, $options);
        }
    }

    /**
     * Create a route group with a common prefix.
     * All routes created in the passed callback will have the given group prefix prepended.
     *
     * @param string   $prefix
     * @param callable $callback
     */
    public function addGroup(string $prefix, callable $callback)
    {
        $previousGroupPrefix = $this->groupPrefix;
        $this->groupPrefix   = $previousGroupPrefix . $prefix;
        $callback($this);
        $this->groupPrefix = $previousGroupPrefix;
    }

    /**
     * Add a new route to the route collection
     *
     * @param string $pattern
     * @param        $callback
     * @param array  $options
     */
    public function add(string $pattern, $callback, array $options = []): void
    {
        $abstract = $options['abstract'] ?? false;
        $callback = $callback            ?? $options['callback'] ?? null;
        $weight   = $options['weight']   ?? 1;
        $path     = $options['path']     ?? ROOT;
        $plugin   = $options['plugin']   ?? null;
        if (isset($this->pluginManager) && isset($options['dependencies'])) {
            $error = [];
            foreach ($options['dependencies'] as $plugin) {
                if (!$this->pluginManager->isEnabled($plugin)) {
                    $error[] = "need plugin " . $plugin . " for route \n";
                }
            }
            if (!empty($error)) {
                return;
            }
        }
        /* Get method(s) from options or from pattern */
        $methods = $options['methods'] ?? null;
        if (strpos($this->groupPrefix, ' ') !== false) {
            [$methods, $this->groupPrefix] = explode(' ', $this->groupPrefix, 2);
        }
        if (strpos($pattern, ' ') !== false) {
            [$methods, $pattern] = explode(' ', $pattern, 2);
        }
        if (is_string($methods)) {
            $methods = explode('|', $methods);
        }

        $pattern = trim($this->groupPrefix . $pattern);

        /* Add route to route collection */
        $this->routeCollection[] = [
            'pattern'  => $pattern,
            'callback' => $callback,
            'methods'  => $methods,
            'weight'   => $weight,
            'abstract' => $abstract,
            'path'     => $path,
            'plugin'   => $plugin,
            'options'  => $options
        ];
    }

    /**
     * Route a given url based on the added route files
     *
     * @param string $uri
     * @param string $method
     *
     * @return \Leap\Route
     */
    public function routeUri(string $uri, string $method = 'GET'): Route
    {
        $uri = trim($uri, "/");

        // Sort route array
        $this->routeCollection = $this->sortRouteCollection($this->routeCollection);
        $parsedRoute           = new Route();

        // Try to match url to one or multiple routes
        foreach ($this->routeCollection as $route) {
            $regex = $this->getBetterRegex(trim($route['pattern'], '/'));

            // Search for named parameters
            $paramNames = [];
            if (strpos($regex, "{") !== false) {
                if (preg_match_all("#{([a-zA-Z_]+[\w]*)(?::(.+))?}#", $regex, $matches)) {
                    foreach ($matches[0] as $key => $whole_match) {
                        $param_name = $matches[1][$key];
                        /* default regex for named paramters */
                        $regexReplace = "([^/]+)";
                        /* check for custom regex for named parameter */
                        if (!empty($matches[2][$key])) {
                            $regexReplace = "(" . $matches[2][$key] . ")";
                        }
                        /* replace named parameters with regex */
                        $regex        = strReplaceFirst($whole_match, $regexReplace, $regex);
                        $paramNames[] = $param_name;
                    }
                }
            }
            /* Check if uri matches the routes regex */
            if (preg_match($regex, $uri, $paramValues)) {
                if (!isset($route['methods']) || in_array($method, $route['methods'])) {
                    /* resolve any named parameters */
                    $parameters = [];
                    if (!empty($paramNames)) {
                        foreach ($paramNames as $k => $paramName) {
                            $parameters['{' . $paramName . '}'] = $paramValues[$k + 1] ?? null;
                        }
                    }
                    /* We found at least one valid route */
                    $this->parseRoute($route, $parameters, $parsedRoute);
                } else {
                    if ($parsedRoute->status === Route::NOT_FOUND) {
                        $parsedRoute->status = Route::METHOD_NOT_ALLOWED;
                    }
                }
            }
        }

        return $parsedRoute;
    }

    /**
     * Route a PSR-7 Request based on the added route files
     *
     * @param ServerRequestInterface $request
     *
     * @return Route
     */
    public function route(ServerRequestInterface $request): Route
    {
        $uri = $request->getUri()->getPath();
        $uri = strReplaceFirst(BASE_URL, '/', $uri);
        $method = $request->getMethod();
        return $this->routeUri($uri, $method);
    }

    /**
     * Sort route array by weight first, then by length of route (key)
     *
     * @param array $routeCollection
     *
     * @return array
     */
    /* TODO: check overhead for sorting, maybe try to improve performance */
    /**
     * @param array $routeCollection
     *
     * @return array
     */
    private function sortRouteCollection(array $routeCollection): array
    {
        $weight      = [];
        $routeLength = [];
        foreach ($routeCollection as $route) {
            $pattern  = $route['pattern'];
            $weight[] = $route['weight'];
            /* set length for homepage route to 1 instead of 0 */
            if (empty($pattern)) {
                $pattern = '1';
            }

            /* remove regex and wildcards from route so it doesn't count for the length */
            $wildcards     = ['?', '*', '+', ':'];
            $pattern       = str_replace($wildcards, '', $pattern);
            $pattern       = preg_replace("/\{(.*?)\}/", '', $pattern);
            $pattern       = preg_replace("/\[(.*?)\]/", '', $pattern);
            $routeLength[] = strlen($pattern);
        }
        array_multisort($weight, SORT_ASC, $routeLength, SORT_ASC, $routeCollection);
        return $routeCollection;
    }

    /**
     * Get regex pattern for preg* functions based on fnmatch function pattern
     *
     * @param      $pattern
     *
     * @return string
     */
    private function getBetterRegex(string $pattern): string
    {
        $transforms = [
            '*' => '[^/]+',
            '**' => '.+',
            '?' => '.',
            '[!' => '[^',
            '('  => '(?:',
            ')'  => ')?',
        ];

        return '#^' . strtr(trim($pattern), $transforms) . '$#i';
    }

    /**
     * Parse a route from a route file
     *
     * @param array            $route
     * @param array            $parameters
     * @param \Leap\Route $parsedRoute
     */
    private function parseRoute(array $route, array $parameters, Route $parsedRoute): void
    {
        $pattern                        = $route['pattern'];
        $options                        = $route['options'];
        $parsedRoute->matchedPatterns[] = $pattern;
        $parsedRoute->base_path         = $route['path'];

        if (isset($options['clear'])) {
            $parsedRoute->defaultRouteValues($options['clear']);
        }

        /* Check for at least one Route that is NOT abstract */
        $abstractRoute = $route['abstract'] ?? false;
        if ($parsedRoute->status !== Route::FOUND && !$abstractRoute) {
            $parsedRoute->status = Route::FOUND;
        }

        if (isset($route['callback'])) {
            if (is_callable($route['callback'])) {
                $parsedRoute->callback = $route['callback'];
            } else {
                $parsedRoute->callback = [];
                $parts                 = explode('@', $route['callback']);

                $parsedRoute->callback['class'] = $this->replaceParams($parts[0], $parameters);
                $action                         = null;
                if (isset($parts[1])) {
                    $action = $this->replaceParams($parts[1], $parameters);
                }
                $parsedRoute->callback['action'] = $action;
            }
        }
        /* retrieve parameters from named parameters */
        foreach ($parameters as $paramName => $paramValue) {
            $parsedRoute->parameters[substr($paramName, 1, -1)] = $paramValue;
        }
        /* retrieve parameters from options */
        if (isset($options['parameters']) && is_array($options['parameters'])) {
            foreach ($options['parameters'] as $param => $value) {
                /* check all parameter values for special paths */
                if (is_array($value)) {
                    array_walk_recursive($value, function (&$val) use ($route) {
                        if (is_string($val)) {
                            $val = parsePath($val, $route['path']);
                        }
                    });
                } else if (is_string($value)) {
                    $value = parsePath($value, $route['path']);
                }

                if (substr($param, -2) === '[]') {
                    $parsedRoute->parameters[substr($param, 0, -2)][] = $this->replaceParams($value, $parameters);
                } else {
                    $parsedRoute->parameters[$param] = $this->replaceParams($value, $parameters);
                }
            }
        }
    }

    /**
     * @param mixed $var
     * @param array $parameters
     *
     * @return mixed
     */
    private function replaceParams($var, array $parameters)
    {
        if (is_string($var) && !empty($parameters)) {
            $var = strtr($var, $parameters);
        }
        return $var;
    }
}
