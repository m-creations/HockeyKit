<?php
    require_once('config.php');
    require(constant('HOCKEY_INCLUDE_DIR'));
    
    $router = Router::get(array('appDirectory' => dirname(__FILE__).DIRECTORY_SEPARATOR));

    $page = new Renderer($router->app, $router);
    $page->setDevice(Device::currentDevice());
    
    echo $page;
?>