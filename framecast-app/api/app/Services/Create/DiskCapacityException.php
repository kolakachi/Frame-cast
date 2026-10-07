<?php

namespace App\Services\Create;

class DiskCapacityException extends \Symfony\Component\HttpKernel\Exception\HttpException
{
    public function __construct()
    {
        parent::__construct(503, 'Creation is temporarily waiting for storage capacity. Your saved work is safe. Please try again later.');
    }
}
