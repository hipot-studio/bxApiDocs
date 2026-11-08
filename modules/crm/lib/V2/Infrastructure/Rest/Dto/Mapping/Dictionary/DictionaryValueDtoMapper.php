<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Dictionary;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary\DictionaryValueDto;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryValue;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

/**
 * Between a value of a dictionary as the domain hands it upwards and the contract a client sees.
 *
 * `select` is honoured here and nowhere else: the domain always answers with all four fields, so a value
 * of the contract carries the fields a client asked for because this mapper leaves the rest
 * uninitialised. An empty set of selected fields means all of them, as it does for every mapper.
 */
final class DictionaryValueDtoMapper extends Mapper
{
	/** The published set of a value, in the order the contract lists it. */
	private const FIELDS = ['id', 'name', 'sort', 'isSystem'];

	/**
	 * @param list<DictionaryValue> $items
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(DictionaryValueDto::class);

		foreach ($items as $item)
		{
			$collection->add($this->mapItem($item, $fields));
		}

		return $collection;
	}

	/**
	 * The only binding of a published field to the value of the domain: a field added to FIELDS and
	 * forgotten here fails at once with an unhandled match rather than answering silently.
	 */
	private function mapItem(DictionaryValue $item, array $selectedFields): DictionaryValueDto
	{
		$dto = new DictionaryValueDto();
		$allFieldsSelected = $selectedFields === [];

		foreach (self::FIELDS as $field)
		{
			if ($allFieldsSelected || in_array($field, $selectedFields, true))
			{
				$dto->{$field} = match ($field)
				{
					'id' => $item->id,
					'name' => $item->name,
					'sort' => $item->sort,
					'isSystem' => $item->isSystem,
				};
			}
		}

		return $dto;
	}
}
