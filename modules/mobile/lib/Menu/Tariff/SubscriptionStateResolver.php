<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\Tariff;

final class SubscriptionStateResolver
{
	public function resolve(
		bool $isPaid,
		bool $isDemo,
		bool $isDemoUsed,
		bool $isExpired,
		bool $isAlmostExpired,
		bool $isDemoAvailable,
	): SubscriptionState
	{
		if (!$isPaid && !$isDemo)
		{
			// A never-used trial still may be unavailable: e.g. a region without the subscription model.
			if (!$isDemoUsed && $isDemoAvailable)
			{
				return SubscriptionState::DemoAvailable;
			}

			return SubscriptionState::None;
		}

		if ($isExpired)
		{
			return SubscriptionState::Expired;
		}

		if ($isDemo)
		{
			return SubscriptionState::DemoActive;
		}

		if ($isAlmostExpired)
		{
			return SubscriptionState::Expiring;
		}

		return SubscriptionState::Purchased;
	}
}
