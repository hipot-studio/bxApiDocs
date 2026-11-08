<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Address;

use Bitrix\Crm\V2\Internal\Service\Address\EntityAddressService;
use Bitrix\Crm\V2\Public\Entity\Address\Address;
use Bitrix\Crm\V2\Public\Entity\Address\AddressIdentifier;
use Bitrix\Crm\V2\Public\Entity\Address\AddressOwner;

final class EntityAddressProvider
{
	private ?EntityAddressService $entityAddressService = null;

	public function get(AddressIdentifier $identifier, AddressOwner $owner, int $userId): Address
	{
		$this->getEntityAddressService()->assertIdentifierBelongsToOwner($identifier, $owner);
		$this->getEntityAddressService()->assertCanRead($owner, $userId);

		return $this->getEntityAddressService()->read($identifier);
	}

	private function getEntityAddressService(): EntityAddressService
	{
		return $this->entityAddressService ??= new EntityAddressService();
	}
}
