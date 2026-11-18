<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Provider;

/**
 * The provider refused the prompt under its content policy. Final.
 */
class PromptRefusedException extends ProviderException
{
    public function __construct(private readonly string $provider_message = '')
    {
        parent::__construct('Prompt refused by the provider.');
    }

    public function providerMessage(): string
    {
        return $this->provider_message;
    }
}
