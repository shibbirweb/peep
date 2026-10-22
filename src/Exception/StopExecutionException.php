<?php

namespace ShibbirWeb\Peep\Exception;

/**
 * Thrown by pause() when "Stop" is clicked in the viewer, to end the request on purpose.
 */
final class StopExecutionException extends PeepException
{
}
