<?php
namespace App\Services\Create;

/**
 * The provider finished, but its output could not be downloaded (the network failed after retries). The outcome is
 * known, not in doubt: the user is not charged for a file they never got, and the item can be bought again.
 */
class OutputUnavailable extends \RuntimeException {}
