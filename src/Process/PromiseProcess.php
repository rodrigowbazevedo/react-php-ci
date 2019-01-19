<?php

namespace Process;

use React\EventLoop\LoopInterface;
use React\ChildProcess\Process;
use React\Promise\Promise;
use React\Promise\Deferred;

class PromiseProcess
{
    private $command;
    private $process;
    private $deferred;
    private $output;

    public function __construct(string $command, string $path)
    {
        $this->command = $command;
        $this->process = new Process($command, $path);
        $this->deferred = new Deferred;
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function run(LoopInterface $loop): Promise
    {
        $this->output = new ProcessOutput;
        $this->process->start($loop);

        $this->process->on('exit', function($exitCode){
            $this->output->exitCode = $exitCode;

            if($exitCode == 0){
                $this->deferred->resolve($this->output);
            }else{
                $this->deferred->reject($this->output);
            }
        });

        $this->process->stdout->on('data', function ($chunk) {
            $this->output->stdout .= $chunk;
        });

        $this->process->stdout->on('error', function (\Exception $e){
            $this->output->stderr .= $e->getMessage();
        });

        return $this->deferred->promise();
    }
}