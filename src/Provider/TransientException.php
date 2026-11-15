<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Provider;

/**
 * Timeout, connection error or 5xx. Retried with backoff.
 */
class TransientException extends ProviderException
{
    private bool $is_timeout = false;

    public static function timeout(string $message = 'The provider timed out.'): self
    {
        $e = new self($message);
        $e->is_timeout = true;
        return $e;
    }

    public function isTimeout(): bool
    {
        return $this->is_timeout;
    }
}
