<?php

use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\LoopInterface;
use React\Http\Response;
use React\Http\Server;
use React\ChildProcess\Process;
use React\Promise\Promise;
use Psr\Log\LoggerInterface;
use Process\ProcessQueue;

require_once __DIR__ . '/vendor/autoload.php';

$container = require __DIR__ . '/container.php';

$config = $container['config'];
$loop = $container[LoopInterface::class];
$logger = $container[LoggerInterface::class];

$findProjects = findProjects($config->projects->toArray());
$commandQueue = commandQueue($loop, $logger);

$dispatcher = FastRoute\simpleDispatcher(function(FastRoute\RouteCollector $routes) use ($findProjects, $commandQueue, $logger) {
    $routes->addRoute('POST', '/', function(ServerRequestInterface $request) use ($findProjects, $commandQueue, $logger) {
        $data = json_decode((string)$request->getBody());

        $repository = $data->repository->full_name;
        $branch = $data->push->changes[0]->new->name;
        $author = $data->push->changes[0]->new->target->author->raw;
        $commit = $data->push->changes[0]->new->target->message;

        $logger->info('Web Hook Received', [
            'Repository' => $repository,
            'Branch' => $branch,
            'Commit' => $commit,
            'Autor' => $author
        ]);

        $projects = $findProjects($repository, $branch);
        foreach($projects as $project){
            $logger->info('Starting to deploy Project', (array)$project);

            foreach($project['actions'] as $commands){
                $queue = $commandQueue($commands, $project['path']);
                $queue->promise()->then(
                    function() use ($project, $logger){
                        $logger->info('Deploy project completed', [
                            'Project' => $project['name']
                        ]);
                    }
                );
                $queue->run();
            }
        }

        $body = <<<BODY
Weebhook received
Repository: {$repository}
Branch: {$branch}
BODY;
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

$server->on('error', function (Exception $e) use ($logger){
    $error = 'Server Error'. PHP_EOL;

    $data = [
        'Error' => $e->getMessage()
    ];

    if ($e->getPrevious() !== null) {
        $previousException = $e->getPrevious();

        $data['Previus Error'] = $previousException->getMessage();
    }

    $logger->error('Server Error', $data);
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

function commandQueue(LoopInterface $loop, LoggerInterface $logger){
    return function(array $commands, string $path) use ($loop, $logger){
        return new ProcessQueue($path, $commands, $loop, $logger);
    };
};
