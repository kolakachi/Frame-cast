<?php
namespace App\Services\Developer;

class OperationBudgetExceeded extends \Symfony\Component\HttpKernel\Exception\HttpException
{
    public function __construct()
    {
        parent::__construct(402, 'The authorized operation budget or available balance no longer covers this action. Inspect the operation before authorizing more work.');
    }
}
