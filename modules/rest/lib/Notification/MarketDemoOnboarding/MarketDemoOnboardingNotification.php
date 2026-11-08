<?php

declare(strict_types=1);

namespace Bitrix\Rest\Notification\MarketDemoOnboarding;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Rest\Infrastructure\Market\MarketSubscription;
use Bitrix\Rest\Integration\Market\Label;
use Bitrix\Rest\Internal\Integration\UI\CopilotService;
use Bitrix\Rest\Service\RestUserOption;
use Closure;

final class MarketDemoOnboardingNotification
{
	private const USER_OPTION_NAME = 'marketDemoOnboardingPopupShown';

	public function __construct(
		private readonly RestUserOption $userOption,
		private readonly MarketSubscription $marketSubscription,
		private readonly Closure $userIdProvider,
		private readonly Closure $variantProvider,
		private readonly Closure $configFactory,
	)
	{
	}

	public static function createByDefault(): self
	{
		return new self(
			new RestUserOption(),
			MarketSubscription::createByDefault(),
			static fn(): int => (int)CurrentUser::get()->getId(),
			static fn(): string => Option::get('market', 'onboardingDemoPopup', ''),
			static fn(MarketDemoOnboardingVariant $variant): MarketDemoOnboardingConfig => new MarketDemoOnboardingConfig(
					$variant,
					Label::isRenamedMarket(),
					CopilotService::getName(),
				),
		);
	}

	public function getConfig(): ?MarketDemoOnboardingConfig
	{
		$userId = ($this->userIdProvider)();
		if ($userId <= 0)
		{
			return null;
		}

		$rawVariant = ($this->variantProvider)();
		if (!is_string($rawVariant))
		{
			return null;
		}

		$variant = MarketDemoOnboardingVariant::tryFrom($rawVariant);
		if ($variant === null)
		{
			return null;
		}

		if ($this->userOption->get(self::USER_OPTION_NAME, 'N') === 'Y')
		{
			return null;
		}

		if (!$this->marketSubscription->canBuySubscription())
		{
			return null;
		}

		if (!$this->marketSubscription->isActive() || !$this->marketSubscription->isDemo())
		{
			return null;
		}

		return ($this->configFactory)($variant);
	}
}
