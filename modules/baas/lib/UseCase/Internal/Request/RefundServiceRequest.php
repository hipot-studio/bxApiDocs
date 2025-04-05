<?php

namespace Bitrix\Baas\UseCase\Internal\Request;

use \Bitrix\Baas;

class RefundServiceRequest
{
	public function __construct(
		public Baas\Entity\Service $service,
		public string $consumptionId,
	)
	{
	}
}
