<?php

namespace App\Services\Roster;

use RuntimeException;

/**
 * A roster change that can't be made. The message is shown to the examinator.
 */
class RosterException extends RuntimeException
{
}
