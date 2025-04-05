<?php

declare(strict_types=1);

namespace Bitrix\Baas\UseCase\Internal\Request;

class PackAvailablePurchaseInfoRequest
{
	public function __construct(
		public ?string $packageCode = null,
		public ?string $purchaseCode = null,
		public ?bool $onlyEnabled = true,
	)
	{
	}
}
