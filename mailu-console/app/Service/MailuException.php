<?php

namespace App\Service;

class MailuException extends \RuntimeException
{
    private $status;
    private $endpoint;

    public function __construct($message, $status = 0, $endpoint = '')
    {
        parent::__construct($message, (int) $status);
        $this->status = (int) $status;
        $this->endpoint = (string) $endpoint;
    }

    public function status()
    {
        return $this->status;
    }

    public function endpoint()
    {
        return $this->endpoint;
    }
}