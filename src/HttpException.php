<?php

class HttpException extends Exception
{
    private $response;

    public function __construct(int $status, $response = null)
    {
        parent::__construct("HTTP error {$status}", $status);
        $this->response = $response;
    }

    public function getResponse()
    {
        return $this->response;
    }
}