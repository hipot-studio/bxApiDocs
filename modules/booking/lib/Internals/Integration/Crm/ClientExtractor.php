<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Crm;

use Bitrix\Booking\Entity\Client\Client;

class ClientExtractor
{
	private const MODULE_ID = 'crm';

	public function getCrmEntityType(Client $client): string|null
	{
		if (!$this->isCrmClient($client))
		{
			return null;
		}

		return $client->getType()?->getCode();
	}

	public function getCrmEntityId(Client $client): int|null
	{
		if (!$this->isCrmClient($client))
		{
			return null;
		}

		return $client->getId();
	}

	private function isCrmClient(Client $client): bool
	{
		return $client->getType()?->getModuleId() === self::MODULE_ID;
	}
}
