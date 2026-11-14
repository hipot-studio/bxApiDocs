<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\AbstractRequisitePairDto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

/**
 * Translates between the contract of a requisite pair ({@see AbstractRequisitePairDto}) and the value
 * the domain keeps it in, in both directions: a stored pair becomes an answer, and a request becomes
 * a value to write.
 *
 * A group brings its own two types and nothing else: the DTO it publishes and the way to build its
 * pair out of two identifiers. Both pair values answer the same two accessors and share no type
 * saying so - each group keeps its own `final` value class in the public contract of V2, and a common
 * interface there would publish a type no consumer of that contract asked for.
 *
 * @template TEntity of object the value class of the group
 */
abstract class AbstractRequisitePairDtoMapper extends Mapper
{
	/** @return class-string<AbstractRequisitePairDto> the contract this group publishes the pair under */
	abstract protected function dtoClass(): string;

	/** @return TEntity */
	abstract protected function createEntity(?int $requisiteId, ?int $bankRequisiteId): object;

	/**
	 * @param TEntity[] $items
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$dtoClass = $this->dtoClass();
		$collection = new DtoCollection($dtoClass);
		foreach ($items as $item)
		{
			$dto = new $dtoClass();
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
	 *
	 * @return TEntity
	 */
	public function mapToEntity(AbstractRequisitePairDto $dto): object
	{
		$values = $dto->toArray(rawData: true);

		return $this->createEntity($values['requisiteId'] ?? null, $values['bankRequisiteId'] ?? null);
	}

	private function isSelected(string $field, array $fields): bool
	{
		return $fields === [] || in_array($field, $fields, true);
	}
}
