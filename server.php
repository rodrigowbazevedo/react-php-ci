<?php

use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\LoopInterface;
use React\Http\Response;
use React\Http\Server;
use React\ChildProcess\Process;

require_once __DIR__ . '/vendor/autoload.php';

$container = require __DIR__ . '/container.php';

$config = $container['config'];
$loop = $container[LoopInterface::class];

$findProjects = findProjects($config->projects->toArray());
$commandQueue = commandQueue($loop);

$dispatcher = FastRoute\simpleDispatcher(function(FastRoute\RouteCollector $routes) use ($findProjects, $commandQueue) {
    $routes->addRoute('POST', '/', function(ServerRequestInterface $request) use ($findProjects, $commandQueue) {
        $data = json_decode((string)$request->getBody());

        $repository = $data->repository->full_name;
        $branch = $data->push->changes[0]->new->name;

        $projects = $findProjects($repository, $branch);

        foreach($projects as $project){
            foreach($project['actions'] as $commands){
                $commandQueue($commands, $project['path']);
            }
        }

        $body = <<<BODY
Repository: {$repository}
Branch: {$branch}
BODY;

        echo PHP_EOL, $body, PHP_EOL;

        return new Response(
            200,
            array(
                'Content-Type' => 'text/plain'
            ),
            $body
        );
    });
});

$server = new Server(function (ServerRequestInterface $request) use ($dispatcher) {
    $routeInfo = $dispatcher->dispatch($request->getMethod(), $request->getUri()->getPath());

    switch ($routeInfo[0]) {
        case FastRoute\Dispatcher::NOT_FOUND:
            return new Response(404, ['Content-Type' => 'text/plain'],  'Not found');
        case FastRoute\Dispatcher::FOUND:
            return $routeInfo[1]($request, ... array_values($routeInfo[2]));
    }
});

$server->on('error', function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
    if ($e->getPrevious() !== null) {
        $previousException = $e->getPrevious();
        echo $previousException->getMessage() . PHP_EOL;
    }
});

$socket = new React\Socket\Server($config->server->port, $loop);
$server->listen($socket);

$loop->run();


function findProjects(array $projects){
    return function(string $repository, string $branch) use ($projects){
        return array_filter($projects, function($project) use ($repository, $branch){
            return $project['repository'] == $repository && $project['branch'] == $branch;
        });
    };
};

function commandQueue(LoopInterface $loop){
    return function(array $commands, string $path) use ($loop){
        $queue = new SplQueue;

        foreach($commands as $command){
            $queue->enqueue($command);
        }

        runQueue($path, $queue, $loop);
    };
};

function runQueue(string $path, SplQueue $queue, LoopInterface $loop){

    $command = $queue->dequeue();

    commandPromise($command, $path, $loop)->then(
        function($data) use ($path, $queue, $loop){
            if(!$queue->isEmpty()){
                runQueue($path, $queue, $loop);
            }
        },
        function(){

        }
    );
}

function commandPromise(string $command, string $path, LoopInterface $loop)
{
    $deferred = new React\Promise\Deferred();

    $process = new Process($command, $path);
    $process->start($loop);

    echo PHP_EOL, "Started action: ${command}", PHP_EOL;

    $process->stdout->on('data', function ($chunk) {
        echo $chunk, PHP_EOL;
    });

    $process->stdout->on('error', function (Exception $e) use ($deferred, $command) {
        echo PHP_EOL, "Error on action: ${command}", PHP_EOL;
        echo 'Error message: ' . $e->getMessage(), PHP_EOL;
        $deferred->reject($e);
    });

    $process->stdout->on('end', function ()  use ($deferred, $command){
        $deferred->resolve();
    });

    $process->on('exit', function($exitCode, $termSignal) use ($command){
        echo PHP_EOL, "Finish action: ${command} with ecit code: ${exitCode}", PHP_EOL;
    });

    return $deferred->promise();
}
