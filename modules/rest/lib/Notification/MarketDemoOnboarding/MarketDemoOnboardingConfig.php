<?php

declare(strict_types=1);

namespace Bitrix\Rest\Notification\MarketDemoOnboarding;

final readonly class MarketDemoOnboardingConfig
{
	public function __construct(
		public MarketDemoOnboardingVariant $variant,
		public bool $isMarketRenamed,
		public string $copilotName,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'variant' => $this->variant->value,
			'isMarketRenamed' => $this->isMarketRenamed,
			'copilotName' => $this->copilotName,
		];
	}
}
