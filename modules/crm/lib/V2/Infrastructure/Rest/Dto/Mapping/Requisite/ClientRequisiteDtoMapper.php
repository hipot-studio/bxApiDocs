<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\ClientRequisiteDto;
use Bitrix\Crm\V2\Public\Entity\Requisite\ClientRequisite;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

final class ClientRequisiteDtoMapper extends Mapper
{
	/**
	 * @param ClientRequisite[] $items
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(ClientRequisiteDto::class);
		foreach ($items as $item)
		{
			$dto = new ClientRequisiteDto();
			if ($this->isSelected('requisiteId', $fields))
			{
				$dto->requisiteId = $item->getRequisiteId();
			}
			if ($this->isSelected('bankRequisiteId', $fields))
			{
				$dto->bankRequisiteId = $item->getBankRequisiteId();
			}

			$collection->add($dto);
		}

		return $collection;
	}

	/**
	 * A field the client left out and a field the client sent as null are both read back as null:
	 * the difference belongs to the request layer, which turns either of them into zero.
	 */
	public function mapToEntity(ClientRequisiteDto $dto): ClientRequisite
	{
		$values = $dto->toArray(rawData: true);

		return new ClientRequisite($values['requisiteId'] ?? null, $values['bankRequisiteId'] ?? null);
	}

	private function isSelected(string $field, array $fields): bool
	{
		return $fields === [] || in_array($field, $fields, true);
	}
}
