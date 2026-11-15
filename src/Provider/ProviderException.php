<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Provider;

/**
 * Base of every exception an adapter may throw.
 *
 * Messages must never contain secrets; adapters redact header values.
 */
class ProviderException extends \RuntimeException
{
}
