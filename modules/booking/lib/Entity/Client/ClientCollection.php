<?php

declare(strict_types=1);

namespace Bitrix\Booking\Entity\Client;

use Bitrix\Booking\Entity\BaseEntityCollection;

/**
 * @method \Bitrix\Booking\Entity\Client\Client|null getFirstCollectionItem()
 * @method Client[] getIterator()
 */
class ClientCollection extends BaseEntityCollection
{
	public function __construct(Client ...$clients)
	{
		foreach ($clients as $client)
		{
			$this->collectionItems[] = $client;
		}
	}

	public function getPrimaryClient(): Client|null
	{
		foreach ($this->collectionItems as $client)
		{
			if ($client->getType()?->getCode() === ClientType::CODE_CONTACT)
			{
				return $client;
			}
		}

		return $this->getFirstCollectionItem();
	}

	public static function mapFromArray(array $props): self
	{
		$clients = array_map(
			static function ($client)
			{
				return Client::mapFromArray($client);
			},
			$props
		);

		return new ClientCollection(...$clients);
	}

	public function diff(ClientCollection $collectionToCompare): ClientCollection
	{
		return new ClientCollection(...$this->baseDiff($collectionToCompare));
	}
}
