<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Contract\Application\Status;

use Bitrix\Main\Type\Date;

interface MarketSubscriptionStateInterface
{
	public function isAvailable(): bool;

	public function getFinalDate(): ?Date;
}
