<?php
namespace App\Services\Create\Planning;

interface Planner
{
    /** @return array{plan: array, provider: string, usage: array} */
    public function plan(array $context): array;
}
