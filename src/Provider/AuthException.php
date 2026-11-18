<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Provider;

/**
 * Bad key or forbidden (401, 403). Pauses the provider until an administrator resumes it.
 */
class AuthException extends ProviderException
{
}
