<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Application\Status;

use Bitrix\Main\Type\Date;
use Bitrix\Rest\Marketplace\Client;
use Bitrix\Rest\Public\Contract\Application\Status\MarketSubscriptionStateInterface;

final class MarketSubscriptionState implements MarketSubscriptionStateInterface
{
	public function isAvailable(): bool
	{
		return (bool)Client::isSubscriptionAvailable();
	}

	public function getFinalDate(): ?Date
	{
		return Client::getSubscriptionFinalDate();
	}
}
