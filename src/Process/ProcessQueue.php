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

        $process->run($this->loop)->then(
            function(ProcessOutput $output) use ($process){
                $this->logger->info('Command Output', [
                    'Command' => $process->getCommand(),
                    'Output' => $output->stdout
                ]);

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