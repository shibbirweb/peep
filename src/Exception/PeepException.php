<?php

namespace ShibbirWeb\Peep\Exception;

use RuntimeException;

/**
 * Base class for exceptions thrown inside Peep. They never leave Peep's public API,
 * except StopExecutionException, which ends a request on purpose.
 */
class PeepException extends RuntimeException
{
}
