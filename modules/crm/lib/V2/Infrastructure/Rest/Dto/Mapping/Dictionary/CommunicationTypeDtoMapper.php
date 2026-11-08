<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Dictionary;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary\CommunicationTypeDto;
use Bitrix\Crm\V2\Public\Entity\Dictionary\CommunicationType;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

final class CommunicationTypeDtoMapper extends Mapper
{
	/**
	 * The published set of an entry, declared once: CommunicationTypeListRequestMapper filters and
	 * sorts by this very list, so an entry cannot carry a field that is answered but not filtered.
	 */
	public const FIELDS = ['id', 'name', 'nameShort', 'isDeprecated'];

	/**
	 * @param list<CommunicationType> $items
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(CommunicationTypeDto::class);

		foreach ($items as $item)
		{
			$collection->add($this->mapItem($item, $fields));
		}

		return $collection;
	}

	/**
	 * The only binding of a published field to the entity. A field added to FIELDS and forgotten here
	 * fails at once with an unhandled match rather than answering silently.
	 */
	public static function getFieldValue(CommunicationType $item, string $field): string|bool|null
	{
		return match ($field)
		{
			'id' => $item->getId(),
			'name' => $item->getName(),
			'nameShort' => $item->getNameShort(),
			'isDeprecated' => $item->isDeprecated(),
		};
	}

	private function mapItem(CommunicationType $item, array $selectedFields): CommunicationTypeDto
	{
		$dto = new CommunicationTypeDto();
		$allFieldsSelected = $selectedFields === [];

		foreach (self::FIELDS as $field)
		{
			if ($allFieldsSelected || in_array($field, $selectedFields, true))
			{
				$dto->{$field} = self::getFieldValue($item, $field);
			}
		}

		return $dto;
	}
}
