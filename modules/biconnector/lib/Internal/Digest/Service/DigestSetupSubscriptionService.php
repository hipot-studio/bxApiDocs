<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Service;

use Bitrix\Main\Loader;
use Bitrix\Rest\Marketplace\Client;

final class DigestSetupSubscriptionService
{
	public const PROMOTER_CODE = 'limit_subscription_market_access_buy_marketplus';

	private readonly \Closure $isSubscriptionAccess;

	private readonly \Closure $isSubscriptionAvailable;

	public function __construct(
		?\Closure $isSubscriptionAccess = null,
		?\Closure $isSubscriptionAvailable = null,
	)
	{
		$this->isSubscriptionAccess = $isSubscriptionAccess
			?? static fn(): bool => Loader::includeModule('rest') && Client::isSubscriptionAccess();
		$this->isSubscriptionAvailable = $isSubscriptionAvailable
			?? static fn(): bool => Loader::includeModule('rest') && Client::isSubscriptionAvailable();
	}

	public function isRequired(): bool
	{
		return ($this->isSubscriptionAccess)() && !($this->isSubscriptionAvailable)();
	}
}
