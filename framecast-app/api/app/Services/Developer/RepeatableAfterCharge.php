<?php
namespace App\Services\Developer;

/**
 * A queued job that charges per unit it delivers and, run again, skips the units already delivered. Throwing after a
 * charge is then safe to retry: the repeat charges only for what it newly makes, so its operation is not fenced.
 */
interface RepeatableAfterCharge {}
