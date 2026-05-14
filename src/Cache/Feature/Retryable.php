<?php

namespace Utopia\Cache\Feature;

interface Retryable
{
    const MIN_RETRIES = 0;

    const MAX_RETRIES = 10;

    /**
     * @param  int  $maxRetries (0-10)
     * @return self
     */
    public function setMaxRetries(int $maxRetries): self;

    /**
     * @param  int  $retryDelay time in milliseconds
     * @return self
     */
    public function setRetryDelay(int $retryDelay): self;

    /**
     * @return int
     */
    public function getMaxRetries(): int;

    /**
     * @return int
     */
    public function getRetryDelay(): int;
}
