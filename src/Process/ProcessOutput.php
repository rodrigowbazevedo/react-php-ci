<?php

namespace Process;

class ProcessOutput{
    public $exitCode;
    public $stdout;
    public $stderr;

    public function toArray(): array
    {
        return [
            'Exit Code' => $this->exitCode,
            'Output' => $this->stdout,
            'Error' => $this->stderr,
        ];
    }
}