<?php

define('APP_FILE', __FILE__);
require_once 'limslim.php';

function configure()
{
  option('env', ENV_DEVELOPMENT);
}

function before($route)
{
  header("X-LIM-route-function: ".$route['function']);
  layout('html_my_layout');
}

dispatch('/', 'hello_world');
  function hello_world()
  {
    return "Hello world!";
  }

dispatch('/i', 'info');
  function info()
  {
    return phpinfo();
  }

dispatch('/hello/:who', 'hello');
  function hello()
  {
    set_default('name', params('who'), "anonymous");
    return html("Hello %s!");
  }
  
dispatch('/welcome/:name', 'welcome');
  function welcome()
  {
    set_default('name', params('name'), "everybody");    
    return html("html_welcome");
  }

dispatch('/are_you_ok/:name', 'are_you_ok');
  function are_you_ok($name = null)
  {
    if(is_null($name))
    {
      $name = params('name');
      if(empty($name)) halt(NOT_FOUND, "Undefined name.");
    }
    
    set('name', $name);
    return html("Are you ok $name ?");
  }
  
dispatch(array('/greet/*/to/*', array("fname", "lname")), 'greet_fn');
  function greet_fn($fname = null, $lname = null)
  {
    if(is_null($fname))
    {
      $fname = params('fname');
      if(empty($fname)) halt(NOT_FOUND, "Undefined first name.");
    }
    
    if(is_null($lname))
    {
      $lname = params('lname');
      if(empty($lname)) halt(NOT_FOUND, "Undefined last name.");
    }
    set('name', $fname . ' ' . $lname);
	set('fname', $fname);
	set('lname', $lname);
    return html("F: $fname L: $lname");
  }
  
    
dispatch('/how_are_you/:name', 'how_are_you');
  function how_are_you()
  {
    $name = params('name');
    if(empty($name)) halt(NOT_FOUND, "Undefined name.");
    # you can call an other controller function if you want
    if(strlen($name) < 4) return are_you_ok($name);
    set('name', $name);
    return html("I hope you are fine, $name.");
  }
  

function after($output, $route)
{
  $time = number_format( (float)substr(microtime(), 0, 10) - LIM_START_MICROTIME, 6);
  $output .= "\n<!-- page rendered in $time sec., on ".date(DATE_RFC822)." -->\n";
  $output .= "<!-- for route\n";
  $output .= print_r($route, true);
  $output .= "-->";
  return $output;
}


run();

# HTML Layouts and templates

function html_my_layout($vars){ extract($vars);?> 
<html>
<head>
    <title>Limonde first example</title>
</head>
<body>
  <h1>Limonde first example</h1>
    <?=$content?>
    <hr>
    <a href="<?=url_for('/')?>">Home</a> |
    <a href="<?=url_for('/hello/', $name)?>">Hello</a> | 
    <a href="<?=url_for('/welcome/', $name)?>">Welcome !</a> | 
    <a href="<?=url_for('/are_you_ok/', $name)?>">Are you ok ?</a> | 
    <a href="<?=url_for('/how_are_you/', $name)?>">How are you ?</a>
</body>
</html>
<?}

function html_welcome($vars){ extract($vars);?> 
<h3>Hello <?=$name?>!</h3>
<p><a href="<?=url_for('/how_are_you/', $name)?>">How are you <?=$name?>?</a></p>
<hr>
<p><a href="<?=url_for('public/soda_glass.jpg')?>">
   <img src="<?=url_for('public/soda_glass.thb.jpg')?>"></a></p>
<?php
}