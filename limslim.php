<?php

define('LIMONADE', '0.5.0');
define('LIM_SESSION_NAME', '_lim_sess');
define('NOT_FOUND', 404);
define('SERVER_ERROR', 500);

define('CACHE_MAX_AGE', 5 * 60);             // Seconds to cache files for  

@clearstatcache();

function option($name = null, $values = null)
{
    static $options = array();
    $args = func_get_args();
    $name = array_shift($args);
    if (is_null($name))
        return $options;
    if (!empty($args))
    {
        $options[$name] = count($args) > 1 ? $args : $args[0];
    }
    if (array_key_exists($name, $options))
        return $options[$name];
    return;
}

function params($name_or_array_or_null = null, $value = null)
{
    static $params = array();
    $args = func_get_args();

    if (func_num_args() > 0)
    {
        $name = array_shift($args);
        if (is_null($name))
        {

            $params = array();
            return $params;
        }
        if (is_array($name))
        {
            $params = array_merge($params, $name);
            return $params;
        }
        $nargs = count($args);
        if ($nargs > 0)
        {
            $value = $nargs > 1 ? $args : $args[0];
            $params[$name] = $value;
        }
        return array_key_exists($name, $params) ? $params[$name] : null;
    }

    return $params;
}

function set($name = null, $values = null)
{
    static $vars = array();
    $args = func_get_args();
    $name = array_shift($args);
    if (is_null($name))
        return $vars;
    if (!empty($args))
    {
        $vars[$name] = count($args) > 1 ? $args : $args[0];
    }
    if (array_key_exists($name, $vars))
        return $vars[$name];
    return $vars;
}

function set_default($name, $value, $default)
{
    return set($name, value_default($value, $default));
}

function run($env = null)
{
    $root_dir = dirname(APP_FILE);
    $base_path = dirname(file_path($_SERVER['SCRIPT_NAME']));
    $base_file = basename($_SERVER['SCRIPT_NAME']);
    $base_uri = file_path($base_path, (($base_file == 'index.php') ? '' : $base_file . '?'));
    
    option('root_dir', $root_dir);
    option('base_path', $base_path);
    option('base_uri', $base_uri);
    option('session', LIM_SESSION_NAME);

    option('encoding', 'utf-8');
    option('gzip', false);
    option('autorender', false);
    option('x-sendfile', 0);
    
    option('cache_dir', $root_dir . '/cache/');
    option('route_cache_filename', option('cache_dir') . 'routes.dat');
    
    call_if_exists('configure');
    
    $cache_fname = option('route_cache_filename');    
    $cache_mtime = (@file_exists($cache_fname)) ? @filemtime($cache_fname) : 0;
    
    if (time() - CACHE_MAX_AGE > $cache_mtime)
    {
        file_put_contents($cache_fname, serialize(route()), LOCK_EX);
    }    
    
    if (is_bool(option('gzip')) && option('gzip'))
    {
        ini_set('zlib.output_compression', '1');
    }

    if (!defined('SID') && option('session'))
    {
        if (!is_bool(option('session')))
            session_name(option('session'));
        if (!session_start())
            trigger_error("An error occured while trying to start the session",
                E_USER_WARNING);
    }

    if (!function_exists('after'))
    {
        function after($output)
        {
            return $output;
        }
    }

    if (!function_exists('route_missing'))
    {
        function route_missing($request_method, $request_uri)
        {
            halt(NOT_FOUND, "($request_method) $request_uri");
        }
    }

    if ($rm = request_method())
    {
        if (request_is_head())
            ob_start();

        if (!request_method_is_allowed($rm))
            halt(HTTP_NOT_IMPLEMENTED, "The requested method <code>'$rm'</code> is not implemented");

        if ($route = route_find($rm, request_uri()))
        {
            params($route['params']);

            if (function_exists('autoload_controller'))
            {
                autoload_controller($route['function']);
            }

            if (is_callable($route['function']))
            {
                call_if_exists('before', $route);
                $output = call_user_func_array($route['function'], array_values($route['params']));
                if (is_null($output) && option('autorender'))
                    $output = call_if_exists('autorender', $route);
                echo after($output, $route);
            }
            else
                halt(SERVER_ERROR, "Routing error: undefined function '{$route['function']}'", $route);
        }
        else
            route_missing($rm, request_uri());
    }
    else
        halt(HTTP_NOT_IMPLEMENTED, "The requested method <code>'$rm'</code> is not implemented");
}

function request_method($env = null)
{
    $m = array_key_exists('REQUEST_METHOD', $_SERVER) ? $_SERVER['REQUEST_METHOD'] : null;

    if (!in_array(strtoupper($m), request_methods()))
    {
        trigger_error("'$m' request method is unkown or unavailable.", E_USER_WARNING);
        return false;
    }
    return $m;
}

function request_method_is_allowed($m = null)
{
    if (is_null($m))
        $m = request_method();
    return in_array(strtoupper($m), request_methods());
}

function request_is_get($env = null)
{
    return request_method($env) == "GET";
}

function request_is_post($env = null)
{
    return request_method($env) == "POST";
}

function request_is_head($env = null)
{
    return request_method($env) == "HEAD";
}

function request_methods()
{
    return array("GET", "POST", "HEAD");
}

function request_uri($env = null)
{
    static $uri = null;
    if (is_null($env))
    {
        if (!is_null($uri))
            return $uri;
    }

    if (array_key_exists('uri', $_GET))
    {
        $uri = $_GET['uri'];
    }
    else
        if (array_key_exists('u', $_GET))
        {
            $uri = $_GET['u'];
        }
        else
        {
            $app_file = APP_FILE;
            $path_info = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : @getenv('PATH_INFO');
            $query_string = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : @getenv('QUERY_STRING');

            if (trim($path_info, '/') != '' && $path_info != "/" . $app_file)
            {
                if (strpos($path_info, '&') !== 0)
                {

                    $params = explode('&', $path_info);
                    $path_info = array_shift($params);

                    foreach ($params as $param)
                    {
                        if (strpos($param, '=') > 0)
                        {
                            list($k, $v) = explode('=', $param);
                            $_GET[$k] = $v;
                        }
                    }
                }
                $uri = $path_info;
            } 
            elseif (trim($query_string, '/') != '')
            {
                $uri = $query_string;
                $get = $_GET;
                if (count($get) > 0)
                {

                    $first = array_shift(array_keys($get));
                    if (strpos($query_string, $first) === 0)
                        $uri = $first;
                }
            } 
            elseif (array_key_exists('REQUEST_URI', $_SERVER) && !empty($_SERVER['REQUEST_URI']))
            {
                $request_uri = rtrim(rawurldecode($_SERVER['REQUEST_URI']), '?/') . '/';
                $base_path = $_SERVER['SCRIPT_NAME'];

                if ($request_uri . "index.php" == $base_path)
                    $request_uri .= "index.php";
                $uri = str_replace($base_path, '', $request_uri);
            } 
            elseif ($_SERVER['argc'] > 1 && trim($_SERVER['argv'][1], '/') != '')
            {
                $uri = $_SERVER['argv'][1];
            }
        }

        $uri = rtrim($uri, "/");

    if (empty($uri))
    {
        $uri = '/';
    }
    elseif ($uri[0] != '/')
    {
            $uri = '/' . $uri;
    }
    
    return rawurldecode($uri);
}

function dispatch($path_or_array, $function, $options = array())
{
    dispatch_get($path_or_array, $function, $options);
}

function dispatch_get($path_or_array, $function, $options = array())
{
    route("GET", $path_or_array, $function, $options);
    route("HEAD", $path_or_array, $function, $options);
}

function dispatch_post($path_or_array, $function, $options = array())
{
    route("POST", $path_or_array, $function, $options);
}

function route()
{
    static $routes = array();
    static $cache_loaded = FALSE;
    
    if ($cache_loaded)
    {
        return $routes;
    }
    
    $cache_fname = option('route_cache_filename');
    if (@file_exists($cache_fname))
    {
        $data = unserialize(file_get_contents($cache_fname));
        if ($data === FALSE)
        {
            @unlink($cache_fname);
        }
        else
        {
            $routes = (array) $data;
            $cache_loaded = TRUE;
            return $routes;
        }
    }
    
    $nargs = func_num_args();
    if ($nargs > 0)
    {
        $args = func_get_args();
        if ($nargs === 1 && is_null($args[0]))
            $routes = array();
        else
            if ($nargs < 3)
                trigger_error("Missing arguments for route()", E_USER_ERROR);
            else
            {
                $method = $args[0];
                $path_or_array = $args[1];
                $func = $args[2];
                $options = $nargs > 3 ? $args[3] : array();

                $routes[] = route_build($method, $path_or_array, $func, $options);
            }
    }
    return $routes;
}

function route_build($method, $path_or_array, $func, $options = array())
{
    $method = strtoupper($method);
    if (!in_array($method, request_methods()))
        trigger_error("'$method' request method is unkown or unavailable.", E_USER_WARNING);

    if (is_array($path_or_array))
    {
        $path = array_shift($path_or_array);
        $names = $path_or_array[0];
    }
    else
    {
        $path = $path_or_array;
        $names = array();
    }

    $single_asterisk_subpattern = "(?:/([^\/]*))?";
    $double_asterisk_subpattern = "(?:/(.*))?";
    $optionnal_slash_subpattern = "(?:/*?)";
    $no_slash_asterisk_subpattern = "(?:([^\/]*))?";

    if ($path[0] == "^")
    {
        if ($path{strlen($path) - 1} != "$")
            $path .= "$";
        $pattern = "#" . $path . "#i";
    }
    elseif (empty($path) || $path == "/")
    {
            $pattern = "#^" . $optionnal_slash_subpattern . "$#";
    }
    else
    {
        $parsed = array();
        $elts = explode('/', $path);

        $parameters_count = 0;

        foreach ($elts as $elt)
        {
            if (empty($elt))
                continue;

            $name = null;

            if ($elt == "**")
            {
                $parsed[] = $double_asterisk_subpattern;
                $name = $parameters_count;
            } 
            elseif ($elt == "*")
            {
                $parsed[] = $single_asterisk_subpattern;
                $name = $parameters_count;
            }
            elseif ($elt[0] == ":")
            {
                if (preg_match('/^:([^\:]+)$/', $elt, $matches))
                {
                    $parsed[] = $single_asterisk_subpattern;
                    $name = $matches[1];
                }
            } 
            elseif (strpos($elt, '*') !== false)
            {
                $sub_elts = explode('*', $elt);
                $parsed_sub = array();
                foreach ($sub_elts as $sub_elt)
                {
                    $parsed_sub[] = preg_quote($sub_elt, "#");
                    $name = $parameters_count;
                }

                $parsed[] = "/" . implode($no_slash_asterisk_subpattern, $parsed_sub);
            }
            else
            {
                $parsed[] = "/" . preg_quote($elt, "#");
            }

            if (is_null($name))
                continue;
            if (!array_key_exists($parameters_count, $names) || is_null($names[$parameters_count]))
                $names[$parameters_count] = $name;
            $parameters_count++;
        }

        $pattern = "#^" . implode('', $parsed) . $optionnal_slash_subpattern . "?$#i";
    }

    return array("method" => $method, 
                 "pattern" => $pattern, 
                 "names" => $names, 
                 "function" => $func, 
                 "options" => $options);
}

function route_find($method, $path)
{
    $routes = route();
    $method = strtoupper($method);
    foreach ($routes as $route)
    {
        if ($method == $route["method"] && preg_match($route["pattern"], $path, $matches))
        {
            $options = $route["options"];
            $params = array_key_exists('params', $options) ? $options["params"] : array();
            if (count($matches) > 1)
            {
                array_shift($matches);
                $n_matches = count($matches);
                $names = array_values($route["names"]);
                $n_names = count($names);
                if ($n_matches < $n_names)
                {
                    $a = array_fill(0, $n_names - $n_matches, null);
                    $matches = array_merge($matches, $a);
                }
                elseif ($n_matches > $n_names)
                {
                    $names = range($n_names, $n_matches - 1);
                }
                $params = array_replace($params, array_combine($names, $matches));
            }
            $route["params"] = $params;
            return $route;
        }
    }
    return false;
}

function render($content_or_func, $layout = '', $locals = array())
{
    $args = func_get_args();
    $content_or_func = array_shift($args);
    $layout = count($args) > 0 ? array_shift($args) : layout();
    $view_path = file_path(option('views_dir'), $content_or_func);

    if (function_exists('before_render'))
        list($content_or_func, $layout, $locals, $view_path) = before_render($content_or_func, $layout, $locals, $view_path);

    $vars = array_merge(set(), $locals);

    if (function_exists($content_or_func))
    {
        ob_start();
        call_user_func($content_or_func, $vars);
        $content = ob_get_clean();
    } 
    elseif (file_exists($view_path))
    {
        ob_start();
        extract($vars);
        include $view_path;
        $content = ob_get_clean();
    }
    else
    {
        if (substr_count($content_or_func, '%') !== count($vars))
            $content = $content_or_func;
        else
            $content = vsprintf($content_or_func, $vars);
    }

    if (empty($layout))
        return $content;

    return render($layout, null, array('content' => $content));
}

function html($content_or_func, $layout = '', $locals = array())
{
    if (!headers_sent())
        header('Content-Type: text/html; charset=' . strtolower(option('encoding')));
    $args = func_get_args();
    return call_user_func_array('render', $args);
}

function layout($function_or_file = null)
{
    static $layout = null;
    if (func_num_args() > 0)
        $layout = $function_or_file;
    return $layout;
}

function url_for($params = null)
{
    $paths = array();
    $params = func_get_args();
    $get_params = array();
    foreach ($params as $param)
    {
        if (is_array($param))
        {
            $get_params = array_merge($get_params, $param);
            continue;
        }
        if (filter_var($param, FILTER_VALIDATE_URL))
        {
            $paths[] = urlencode($param);
            continue;
        }
        $p = explode('/', $param);
        foreach ($p as $v)
        {
            if (!empty($v))
                $paths[] = str_replace('%23', '#', rawurlencode($v));
        }
    }

    $path = rtrim(implode('/', $paths), '/');

    if (!empty($get_params))
    {
        foreach ($get_params as $k => $v)
            $path .= '&amp;' . rawurlencode($k) . '=' . rawurlencode($v);
    }

    if (!filter_var($path, FILTER_VALIDATE_URL))
    {

        $base_uri = option('base_uri');
        $path = file_path($base_uri, $path);
    }

    if (DIRECTORY_SEPARATOR != '/')
        $path = str_replace(DIRECTORY_SEPARATOR, '/', $path);

    return $path;
}

function h($str, $quote_style = ENT_NOQUOTES, $charset = null)
{
    if (is_null($charset))
        $charset = strtoupper(option('encoding'));
    return htmlspecialchars($str, $quote_style, $charset);
}

define('HTTP_CONTINUE', 100);
define('HTTP_SWITCHING_PROTOCOLS', 101);
define('HTTP_PROCESSING', 102);
define('HTTP_OK', 200);
define('HTTP_CREATED', 201);
define('HTTP_ACCEPTED', 202);
define('HTTP_NON_AUTHORITATIVE', 203);
define('HTTP_NO_CONTENT', 204);
define('HTTP_RESET_CONTENT', 205);
define('HTTP_PARTIAL_CONTENT', 206);
define('HTTP_MULTI_STATUS', 207);

define('HTTP_MULTIPLE_CHOICES', 300);
define('HTTP_MOVED_PERMANENTLY', 301);
define('HTTP_MOVED_TEMPORARILY', 302);
define('HTTP_SEE_OTHER', 303);
define('HTTP_NOT_MODIFIED', 304);
define('HTTP_USE_PROXY', 305);
define('HTTP_TEMPORARY_REDIRECT', 307);

define('HTTP_BAD_REQUEST', 400);
define('HTTP_UNAUTHORIZED', 401);
define('HTTP_PAYMENT_REQUIRED', 402);
define('HTTP_FORBIDDEN', 403);
define('HTTP_NOT_FOUND', 404);
define('HTTP_METHOD_NOT_ALLOWED', 405);
define('HTTP_NOT_ACCEPTABLE', 406);
define('HTTP_PROXY_AUTHENTICATION_REQUIRED', 407);
define('HTTP_REQUEST_TIME_OUT', 408);
define('HTTP_CONFLICT', 409);
define('HTTP_GONE', 410);
define('HTTP_LENGTH_REQUIRED', 411);
define('HTTP_PRECONDITION_FAILED', 412);
define('HTTP_REQUEST_ENTITY_TOO_LARGE', 413);
define('HTTP_REQUEST_URI_TOO_LARGE', 414);
define('HTTP_UNSUPPORTED_MEDIA_TYPE', 415);
define('HTTP_RANGE_NOT_SATISFIABLE', 416);
define('HTTP_EXPECTATION_FAILED', 417);
define('HTTP_UNPROCESSABLE_ENTITY', 422);
define('HTTP_LOCKED', 423);
define('HTTP_FAILED_DEPENDENCY', 424);
define('HTTP_UPGRADE_REQUIRED', 426);

define('HTTP_INTERNAL_SERVER_ERROR', 500);
define('HTTP_NOT_IMPLEMENTED', 501);
define('HTTP_BAD_GATEWAY', 502);
define('HTTP_SERVICE_UNAVAILABLE', 503);
define('HTTP_GATEWAY_TIME_OUT', 504);
define('HTTP_VERSION_NOT_SUPPORTED', 505);
define('HTTP_VARIANT_ALSO_VARIES', 506);
define('HTTP_INSUFFICIENT_STORAGE', 507);
define('HTTP_NOT_EXTENDED', 510);

function status($code = 500)
{
    if (!headers_sent())
    {
        $str = http_response_status_code($code);
        header($str);
    }
}

function redirect_to($params)
{
    if (!headers_sent())
    {
        $status = HTTP_MOVED_TEMPORARILY;

        $params = func_get_args();
        $n_params = array();

        foreach ($params as $param)
        {
            if (is_array($param))
            {
                if (array_key_exists('status', $param))
                {
                    $status = $param['status'];
                    unset($param['status']);
                }
            }
            $n_params[] = $param;
        }
        $uri = call_user_func_array('url_for', $n_params);
        header('Location: ' . $uri, true, $status);
        exit;
    }
}

function http_response_status($num = null)
{
    $status = array(100 => 'Continue', 101 => 'Switching Protocols', 102 =>
        'Processing', 200 => 'OK', 201 => 'Created', 202 => 'Accepted', 203 =>
        'Non-Authoritative Information', 204 => 'No Content', 205 => 'Reset Content',
        206 => 'Partial Content', 207 => 'Multi-Status', 226 => 'IM Used', 300 =>
        'Multiple Choices', 301 => 'Moved Permanently', 302 => 'Found', 303 =>
        'See Other', 304 => 'Not Modified', 305 => 'Use Proxy', 306 => 'Reserved', 307 =>
        'Temporary Redirect', 400 => 'Bad Request', 401 => 'Unauthorized', 402 =>
        'Payment Required', 403 => 'Forbidden', 404 => 'Not Found', 405 =>
        'Method Not Allowed', 406 => 'Not Acceptable', 407 =>
        'Proxy Authentication Required', 408 => 'Request Timeout', 409 => 'Conflict',
        410 => 'Gone', 411 => 'Length Required', 412 => 'Precondition Failed', 413 =>
        'Request Entity Too Large', 414 => 'Request-URI Too Long', 415 =>
        'Unsupported Media Type', 416 => 'Requested Range Not Satisfiable', 417 =>
        'Expectation Failed', 422 => 'Unprocessable Entity', 423 => 'Locked', 424 =>
        'Failed Dependency', 426 => 'Upgrade Required', 500 => 'Internal Server Error',
        501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
        504 => 'Gateway Timeout', 505 => 'HTTP Version Not Supported', 506 =>
        'Variant Also Negotiates', 507 => 'Insufficient Storage', 510 => 'Not Extended');
    if (is_null($num))
        return $status;
    return array_key_exists($num, $status) ? $status[$num] : '';
}

function http_response_status_is_valid($num)
{
    $r = http_response_status($num);
    return !empty($r);
}

function http_response_status_code($num)
{
    if ($str = http_response_status($num))
        return "HTTP/1.1 $num $str";
}

function file_path($path)
{
    $args = func_get_args();
    $ds = '/';
    $win_ds = '\\';
    $n_path = count($args) > 1 ? implode($ds, $args) : $path;
    if (strpos($n_path, $win_ds) !== false)
        $n_path = str_replace($win_ds, $ds, $n_path);
    $n_path = preg_replace('/' . preg_quote($ds, $ds) . '{2,}' . '/', $ds, $n_path);
    return $n_path;
}

if (!function_exists('array_replace'))
{
    function array_replace(array & $array, array & $array1)
    {
        $args = func_get_args();
        $count = func_num_args();

        for ($i = 0; $i < $count; ++$i)
        {
            if (is_array($args[$i]))
            {
                foreach ($args[$i] as $key => $val)
                    $array[$key] = $val;
            }
            else
            {
                trigger_error(__function__ . '(): Argument #' . ($i + 1) . ' is not an array',
                    E_USER_WARNING);
                return null;
            }
        }
        return $array;
    }
}

function call_if_exists($func)
{
    $args = func_get_args();
    $func = array_shift($args);
    if (is_callable($func))
        return call_user_func_array($func, $args);
    return;
}

function value_default($value, $default)
{
    return empty($value) ? $default : $value;
}

function halt($errno = SERVER_ERROR, $msg = '')
{
    $args = func_get_args();
    $error = array_shift($args);

    if (is_string($errno))
    {
        $msg = $errno;
        $oldmsg = array_shift($args);
        $errno = empty($oldmsg) ? SERVER_ERROR : $oldmsg;
    }
    else
        if (!empty($args))
            $msg = array_shift($args);

    if (empty($msg) && $errno == NOT_FOUND)
        $msg = request_uri();
    if (empty($msg))
        $msg = "";

    error_handler_dispatcher($errno, $msg, null, null);
    exit(1);
}

function error_handler_dispatcher($errno, $errstr, $errfile, $errline)
{
    $back_trace = debug_backtrace();
    while ($trace = array_shift($back_trace))
    {
        if ($trace['function'] == 'halt')
        {
            $errfile = $trace['file'];
            $errline = $trace['line'];
            break;
        }
    }

    echo error_default_handler($errno, $errstr, $errfile, $errline);
}

function error_default_handler($errno, $errstr, $errfile, $errline)
{
    $is_http_err = http_response_status_is_valid($errno);
    $http_error_code = $is_http_err ? $errno : SERVER_ERROR;

    status($http_error_code);

    return $http_error_code == NOT_FOUND ? error_not_found_output($errno, $errstr, $errfile,
        $errline) : error_server_error_output($errno, $errstr, $errfile, $errline);
}

function error_not_found_output($errno, $errstr, $errfile, $errline)
{
    if (!function_exists('not_found'))
    {
        function not_found($errno, $errstr, $errfile = null, $errline = null)
        {
            $msg = h(rawurldecode($errstr));
            return html("<h1>404 Page not found</h1><p>The following page was not found:</p><code>{$msg}</code>", null);
        }
    }

    return not_found($errno, $errstr, $errfile, $errline);
}

function error_server_error_output($errno, $errstr, $errfile, $errline)
{
    if (!function_exists('server_error'))
    {
        function server_error($errno, $errstr, $errfile = null, $errline = null)
        {
            $is_http_error = http_response_status_is_valid($errno);

            $msg_status = h(error_http_status($errno));
            $msg_errorstr = $is_http_error ? h($errstr) : '';
            $args = compact('msg_status', 'msg_errorstr');
            $link = '<a href="' . url_for('/') . '">Home</a>';
            $html = <<< EOT
<html>
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  <meta name="robots" content="noindex,nofollow,noarchive,noodp,nosnippets,noydir" />
<title>{$msg_status}</title>
<style>body{font-family:Verdana,Arial,Helvetica,sans-serif;font-size:12px;}p{margin:8px 8px 8px 8px;}.container{margin:2% 15%;background-color:#fafafa;border:1px solid #ccc;padding:15px;}h1{margin:8px 8px 8px 8px;font-size:24px;color:#333;font-family:"Arial Black",Gadget,sans-serif;line-height:36px;}.footer{text-align:center;}</style>
</head>
<body>
  <div class="container">
    <h1>{$msg_status}</h1>
    <p>{$msg_errorstr}</p>
  </div>
  <p class="footer">{$link}</p>
</body>
</html>            
EOT;
            return html($html, null, $args);
        }
    }

    return server_error($errno, $errstr, $errfile, $errline);
}