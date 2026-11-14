<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Services\AiSearch;

use Bitrix\Main\Loader;
use Bitrix\Market\PricePolicy;
use Bitrix\Rest\Marketplace\Client;

final class AppsDecorator
{
	public function decorate(array $apps): array
	{
		$subscriptionAvailable = Loader::includeModule('rest') && Client::isSubscriptionAvailable();

		foreach ($apps as &$appItem)
		{
			$appItem['PRICE_POLICY'] = PricePolicy::getByApp($appItem);
			$appItem['PRICE_POLICY_NAME'] = PricePolicy::getName($appItem['PRICE_POLICY']);
			$appItem['PRICE_POLICY_BLUE'] = ($appItem['PRICE_POLICY'] == PricePolicy::SUBSCRIPTION);
			$appItem['IS_SUBSCRIPTION_AVAILABLE'] = $subscriptionAvailable ? 'Y' : 'N';
		}
		unset($appItem);

		return $apps;
	}
}
