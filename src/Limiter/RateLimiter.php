<?php

namespace ShibbirWeb\Peep\Limiter;

/**
 * Process-wide limits on how much is sent: a total and a per-second rate.
 */
final class RateLimiter
{
    /**
     * @var int|null
     */
    private $max;

    /**
     * @var int|null
     */
    private $perSecond;

    /**
     * @var int
     */
    private $total = 0;

    /**
     * @var int
     */
    private $currentSecond = 0;

    /**
     * @var int
     */
    private $inCurrentSecond = 0;

    /**
     * @var bool
     */
    private $limitReported = false;

    public function max(int $maxCalls): self
    {
        $this->max = $maxCalls > 0 ? $maxCalls : null;

        return $this;
    }

    public function perSecond(int $maxCalls): self
    {
        $this->perSecond = $maxCalls > 0 ? $maxCalls : null;

        return $this;
    }

    public function clear(): self
    {
        $this->max = null;
        $this->perSecond = null;
        $this->total = 0;
        $this->inCurrentSecond = 0;
        $this->limitReported = false;

        return $this;
    }

    /**
     * Records one send and says whether it may go out.
     */
    public function allow(): bool
    {
        if ($this->max === null && $this->perSecond === null) {
            return true;
        }

        $second = time();
        if ($second !== $this->currentSecond) {
            $this->currentSecond = $second;
            $this->inCurrentSecond = 0;
        }

        if (($this->max !== null && $this->total >= $this->max) || ($this->perSecond !== null && $this->inCurrentSecond >= $this->perSecond)) {
            return false;
        }

        $this->total++;
        $this->inCurrentSecond++;

        return true;
    }

    /**
     * True only the first time it is asked after a limit was hit, so the viewer gets one notice.
     */
    public function shouldReportLimit(): bool
    {
        if ($this->limitReported) {
            return false;
        }

        $this->limitReported = true;

        return true;
    }
}
