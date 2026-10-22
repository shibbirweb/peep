<?php

namespace ShibbirWeb\Peep\Limiter;

/**
 * Counts calls per call site, for once() and limit($n).
 */
final class CallLimiter
{
    /**
     * @var array<string, int> call site => calls so far
     */
    private $calls = [];

    /**
     * Records one call and says whether it is still within the limit.
     */
    public function allow(string $callSite, int $limit): bool
    {
        if ($limit <= 0) {
            return true;
        }

        $calls = isset($this->calls[$callSite]) ? $this->calls[$callSite] : 0;
        $this->calls[$callSite] = $calls + 1;

        return $calls < $limit;
    }

    public function reset(): void
    {
        $this->calls = [];
    }
}
