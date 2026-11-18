<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Provider;

/**
 * The provider answered but the answer is unusable (other 4xx, malformed body).
 */
class BadResponseException extends ProviderException
{
}
