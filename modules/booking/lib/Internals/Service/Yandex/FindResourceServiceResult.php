<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Yandex;

use Bitrix\Booking\Entity\DatePeriod;
use Bitrix\Booking\Entity\Resource\Resource;

class FindResourceServiceResult
{
	public function __construct(
		public readonly Resource $resource,
		public readonly DatePeriod $datePeriod,
	)
	{
	}
}
