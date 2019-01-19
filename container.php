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

$container[Psr\Log\LoggerInterface::class] =  function($c){
	$config = $c['config']['log'];

	$logger = new Monolog\Logger('deploy');
	$logger->pushHandler(new Monolog\Handler\StreamHandler($config['file'], Monolog\Logger::DEBUG));

	if($config['slack'] !== null){
		$logger->pushHandler($c[Webthink\MonologSlack\Handler\SlackWebhookHandler::class]);
	}

	return $logger;
};

$container[Webthink\MonologSlack\Handler\SlackWebhookHandler::class] = function($c){
	$config = $c['config']['log'];

	$handler = new Webthink\MonologSlack\Handler\SlackWebhookHandler($config['slack'], null, null, Monolog\Logger::DEBUG);
	$handler->setFormatter(new Webthink\MonologSlack\Formatter\SlackLongAttachmentFormatter);

	return $handler;
};

return $container;
