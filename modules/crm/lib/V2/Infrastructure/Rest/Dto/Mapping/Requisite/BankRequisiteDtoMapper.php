<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\BankRequisiteDto;
use Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisite;
use Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisiteField;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

final class BankRequisiteDtoMapper extends Mapper
{
	/**
	 * @param BankRequisite[] $items
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(BankRequisiteDto::class);
		foreach ($items as $item)
		{
			$template = $item->getTemplate();
			if ($template === null)
			{
				throw new SystemException('A readable bank requisite must contain its template.');
			}

			$dto = new BankRequisiteDto();
			if ($this->isSelected('id', $fields))
			{
				$dto->id = (int)$item->getId();
			}
			if ($this->isSelected('requisiteId', $fields))
			{
				$dto->requisiteId = (int)$item->getRequisiteId();
			}
			if ($this->isSelected('name', $fields))
			{
				$dto->name = (string)$item->getName();
			}
			if ($this->isSelected('comments', $fields))
			{
				$dto->comments = $item->getComments();
			}

			foreach (BankRequisiteField::cases() as $field)
			{
				if ($template->has($field) && $this->isSelected($field->value, $fields))
				{
					$dto->{$field->value} = $item->getField($field);
				}
			}

			$collection->add($dto);
		}

		return $collection;
	}

	public function mapToEntity(BankRequisiteDto $dto, ?int $id = null): BankRequisite
	{
		$values = $dto->toArray(rawData: true);
		$bankValues = [];
		foreach (BankRequisiteField::cases() as $field)
		{
			$value = $values[$field->value] ?? null;
			$bankValues[$field->value] = $value === '' ? null : $value;
		}

		return new BankRequisite(...[
			'id' => $id,
			'requisiteId' => $values['requisiteId'] ?? null,
			'name' => $values['name'] ?? null,
			'comments' => ($values['comments'] ?? null) === '' ? null : ($values['comments'] ?? null),
			...$bankValues,
		]);
	}

	/** @return string[] */
	public function getModifiedFields(BankRequisiteDto $dto): array
	{
		return array_values(array_filter(
			array_keys($dto->toArray(rawData: true)),
			static fn (string $field): bool => !in_array($field, ['id', 'requisiteId'], true),
		));
	}

	private function isSelected(string $field, array $fields): bool
	{
		return $fields === [] || in_array($field, $fields, true);
	}
}
