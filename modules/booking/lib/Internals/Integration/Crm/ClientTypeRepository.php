<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Crm;

use Bitrix\Main\Loader;

class ClientTypeRepository
{
	public function getAll(): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		return [
			\CCrmOwnerType::CompanyName,
			\CCrmOwnerType::ContactName,
		];
	}
}
