<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt\Tests\Support;

use IndianOpinion\FeaturedFromPrompt\Provider\ImageProvider;
use IndianOpinion\FeaturedFromPrompt\Provider\ImageRequest;
use IndianOpinion\FeaturedFromPrompt\Provider\ImageResult;
use IndianOpinion\FeaturedFromPrompt\Provider\ProviderCapabilities;

/**
 * An in-memory provider double. Queue results or throwables; it records
 * every request it receives.
 */
final class FakeProvider implements ImageProvider
{
    /** @var list<ImageResult|\Throwable> */
    public array $queue = [];
    /** @var list<ImageRequest> */
    public array $requests = [];
    public ProviderCapabilities $caps;
    public bool $configured = true;

    public function __construct(public string $slug = 'fake', ?ProviderCapabilities $caps = null)
    {
        $this->caps = $caps ?? new ProviderCapabilities();
    }

    public function id(): string
    {
        return $this->slug;
    }

    public function label(): string
    {
        return 'Fake provider';
    }

    public function capabilities(): ProviderCapabilities
    {
        return $this->caps;
    }

    public function settings_fields(): array
    {
        return [
            ['key' => 'model', 'label' => 'Model', 'type' => 'text', 'default' => 'fake-model'],
            ['key' => 'api_key', 'label' => 'Key', 'type' => 'secret'],
        ];
    }

    public function is_configured(array $settings): bool
    {
        return $this->configured;
    }

    public function generate(ImageRequest $request, array $settings): ImageResult
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue) ?? new ImageResult(Img::png(1536, 864), null, 'image/png', 0, 0, '', 'fake-model');
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }

    public function push(ImageResult|\Throwable ...$items): self
    {
        foreach ($items as $item) {
            $this->queue[] = $item;
        }
        return $this;
    }
}
