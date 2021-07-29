# phprouter
php router
api inspried by https://github.com/bramus/router
but this router is not affected by https://github.com/bramus/router/issues/137 ... which is why i made this router

# installation
the router is just a standalone .php file, you can just copypaste it. 

another alternative is to use composer:
```
composer require 'divinity76/phprouter'
```
# usage

```
<?php
require_once(__DIR__ . '/vendor/autoload.php');
$router = new \Divinity76\Phprouter\Phprouter();
$router->get('/test', function() {
    echo 'test GET page';
});
$router->post("/test",function(){
    echo "test POST page";
});
$router->match("PATCH","/user/(\d+)/([^\\/])?",function(string $user_id, string $user_name_supplied){
    echo "you accessed user #".htmlentities($user_id). 
      ", you think his name is ".htmlentities($user_name)."...");
});
$router->set404(function(){
    echo "404 not found: ".htmlentities($_SERVER['REQUEST_URI']);
});
$router->run();
```
