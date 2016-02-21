<?php
class Router
{
    public $routes;
    public $page;
    public $action;
    public $params;
    public $model;
    private $modelFile;
    public $controller;
    private $controllerFile;
    public $template;
    private $base_path;
    private $plugin_manager;
    public $stylesheets_route;
    public $scripts_route;

    public function __construct()
    {
        $this->routes            = array();
        $this->model             = 'Model';
        $this->controller        = 'Controller';
        $this->template['path']  = ROOT . '/site/templates';
        $this->template['value'] = "default_page.php";
        $this->page['path']      = ROOT . '/site/pages';
        $this->page['value']     = "";
        $this->stylesheets_route       = array();
        $this->scripts_route           = array();
    }

    public function set_plugin_manager($plugin_manager)
    {
        $this->plugin_manager = $plugin_manager;
    }

    public function add_route_file($file)
    {
        if (file_exists($file)) {
            $routes = parse_ini_file($file, true);
            $path   = dirname($file);
            foreach ($routes as $regex => $route) {
                if (isset($route['dependencies'])) {
                    $error = "";
                    foreach ($route['dependencies'] as $plugin) {
                        if (!$this->plugin_manager->is_enabled($plugin)) {
                            $error .= "need plugin " . $plugin . " for route " . $regex . "\n";
                        }
                    }
                    if ($error != "") {
                        unset($routes[$regex]);
                        continue;
                    }
                }
                foreach ($routes[$regex] as $option => $value) {
                    $routes[$regex][$option] = array("value" => $value, "path" => $path);
                }
                $routes[$regex]['last_path'] = $path;
            }
            $this->routes = array_replace_recursive($this->routes, $routes);
        }
    }

    public function route_url($url)
    {
        // sort route array by length of keys
        uksort($this->routes, function ($a, $b) {return strlen($a) - strlen($b);});
        $no_route = true;
        foreach ($this->routes as $regex => $options) {
            $multi_regex = explode(",", $regex);
            foreach ($multi_regex as $pattern) {
                if (fnmatch($pattern, $url, FNM_CASEFOLD)) {
                    $no_route = false;
                    $this->parse_route($options, $url);
                    break;
                }
            }
        }
        if ($no_route) {
            /* no route found, either parse from url or goto 404 */
            /* TODO, make decision! */
            //$this->parse_all_from_url($url);
            header('location: ' . BASE_URL . '/404');
        } else {
            if (isset($this->modelFile)) {
                chdir($this->modelFile['path']);
                if (file_exists($this->modelFile['value'])) {
                    require_once $this->modelFile['value'];
                }
            }
            if (isset($this->controllerFile)) {
                chdir($this->controllerFile['path']);
                if (file_exists($this->controllerFile['value'])) {
                    require_once $this->controllerFile['value'];
                }
            }
            if ($this->page['value'] == "") {
                /* parse only the page from the url */
                $this->parse_page_from_url($url);
            }
        }
    }

    public function parse_route($route, $url)
    {
        $this->base_path = $route['last_path'];

        if(isset($route['clear'])) {
            $route['clear'] = $route['clear']['value'];
            $all = in_array('all', $route['clear']);
            if ($all || in_array('scripts', $route['clear'])) {
                $this->scripts_route = array();
            }
            if ($all || in_array('stylesheets', $route['clear'])) {
                $this->stylesheets_route = array();
            }
            if ($all || in_array('model', $route['clear'])) {
                $this->model = 'Model';
            }
            if ($all || in_array('modelFile', $route['clear'])) {
                $this->modelFile = null;
            }
            if ($all || in_array('controller', $route['clear'])) {
                $this->controller = 'Controller';
            }
            if ($all || in_array('controllerFile', $route['clear'])) {
                $this->controllerFile = null;
            }
            if ($all || in_array('template', $route['clear'])) {
                $this->template['path']  = ROOT . '/site/templates';
                $this->template['value'] = "default_page.php";
            }
            if ($all || in_array('page', $route['clear'])) {
                $this->page['path']      = ROOT . '/site/pages';
                $this->page['value']     = "";
            }
            if ($all || in_array('action', $route['clear'])) {
                $this->action = null;
            }
        }

        if (isset($route['model'])) {
            $this->model = $route['model']['value'];
            if (isset($route['modelFile'])) {
                $this->modelFile = $route['modelFile'];
                if ($this->modelFile['value'][0] == "/") {
                    $this->modelFile['value'] = ROOT . $this->modelFile['value'];
                }
            } else {
                $this->modelFile = array("value" => "models/" . $this->model . ".php", "path" => $route['model']['path']);
            }
        }
        if (isset($route['controller'])) {
            $this->controller = $route['controller']['value'];
            if (isset($route['controllerFile'])) {
                $this->controllerFile = $route['controllerFile'];
                if ($this->controllerFile['value'][0] == "/") {
                    $this->controllerFile['value'] = ROOT . $this->controllerFile['value'];
                }
            } else {
                $this->controllerFile = array("value" => "controllers/" . $this->controller . ".php", "path" => $route['controller']['path']);
            }
        }
        if (isset($route['page'])) {
            $this->page = $route['page'];
            if ($this->page['value'][0] == "/") {
                $this->page = ROOT . $this->page['value'];
            }
        }
        if (isset($route['template'])) {
            $this->template = $route['template'];
            if ($this->template['value'][0] == "/") {
                $this->template = ROOT . $this->template['value'];
            }
        }
        if (isset($route['action'])) {
            $this->action = $route['action']['value'];
        }
        if (isset($route['stylesheets'])) {
            $this->stylesheets_route[] = $route['stylesheets'];
        }
        if (isset($route['scripts'])) {
            $this->scripts_route[] = $route['scripts'];
        }
    }

    private function parse_page_from_url($url)
    {
        $this->page['value'] = "home";
        $args                = arg(NULL, $url);
        $page                = end($args);
        if ($page == "") {
            $page = $this->page['value'];
        }
        $this->page['path'] = $this->base_path . "/pages";
        $this->page['value'] = $page . ".php";
    }

    private function parse_all_from_url($url)
    {
        $this->page['value'] = "home.php";
        $args                = arg(NULL, $url);
        if (empty($args[0])) {
            $args = array();
        }
        $this->page['path'] = ROOT . '/site/pages/';
        $page               = array_shift($args);
        if ($page) {
            $this->page['value'] = $page . ".php";
            $action              = array_shift($args);
            if ($action) {
                $this->action = $action;
                $this->params = implode("/", $args);
            }
        }

        /* check if users didn't specify the default action in the url themselves */
        if ($this->action == 'default_action') {
            header('location: ' . URL . '/404');
        } else if (empty($this->action)) {
            $this->action = 'default_action';
        }
    }
}