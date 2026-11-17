<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Market;

use Bitrix\Main\Loader;
use Bitrix\Market\Subscription\Trial;

final class SubscriptionTrialProvider
{
	public function isAvailable(): bool
	{
		return Loader::includeModule('market') && Trial::isAvailable();
	}
}
