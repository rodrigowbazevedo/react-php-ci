<?php

namespace Process;

use React\EventLoop\LoopInterface;
use React\ChildProcess\Process;
use React\Promise\Promise;
use React\Promise\Deferred;
use Psr\Log\LoggerInterface;

class ProcessQueue
{
    private $path;
    private $commands;
    private $loop;
    private $logger;
    private $queue;
    private $deferred;

    public function __construct(string $path, array $commands, LoopInterface $loop, LoggerInterface $logger)
    {
        $this->path = $path;
        $this->commands = $commands;
        $this->loop = $loop;
        $this->logger = $logger;
        $this->deferred = new Deferred;
        $this->promise = $this->deferred->promise();

        $this->queue = new \SplQueue;
        foreach ($commands as $command) {
            $this->queue->enqueue(new PromiseProcess($command, $path));
        }
    }

    public function run()
    {
        $process = $this->queue->dequeue();

        $this->logger->info('Starting Command', [
            'Command' => $process->getCommand(),
        ]);

        $process->run($this->loop)->then(
            function(ProcessOutput $output) use ($process){

                $chunks = str_split($output->stdout, 1800);
                $chunksCount = sizeof($chunks);

                foreach($chunks as $i => $chunk){
                    $context = [
                        'Command' => $process->getCommand(),
                        'Output' => $chunk
                    ];

                    if($chunksCount > 1){
                        $part = $i + 1;

                        $context['Part'] = "{$part} de {$chunksCount}";
                    }

                    $this->logger->info('Command Output', $context);
                }

                if(!$this->queue->isEmpty()){
                    $this->run();
                }else{
                    $this->deferred->resolve();
                }
            },
            function(ProcessOutput $output) use ($process){
                $this->logger->error('Command Output', [
                    'Command' => $process->getCommand(),
                    'Exit Code' => $output->exitCode,
                    'Error' => $output->stderr,
                    'Output' => $output->stdout
                ]);

                $this->deferred->reject($output);
            }
        );
    }

    public function promise(): Promise
    {
        return $this->promise;
    }
}