<?php
use Pimple\Container;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;

$container = new Container();

$container['config'] = function($c){
    $configPath = __DIR__ . '/config.php';
	$localConfigPath = __DIR__ . '/config.local.php';

	$config = new Zend\Config\Config(require $configPath, true);

	if(file_exists($localConfigPath)){
        $config->merge(new Zend\Config\Config(require $localConfigPath));
	}

	return $config;
};

$container[LoopInterface::class] =  function(){
    return Factory::create();
};

return $container;