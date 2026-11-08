<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\MyCompany;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\MultifieldDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\MultifieldValueTypeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\MyCompany\BasicCompanyDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite\BankRequisiteDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite\RequisiteDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\RequisiteDto;
use Bitrix\Crm\V2\Public\Entity\Item\Company;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

final class BasicCompanyDtoMapper extends Mapper
{
	private const FIELDS = ['id', 'title', 'email', 'phone', 'requisites', 'bankRequisites'];

	/** @var array<string, array{name: ?string, nameShort: ?string}> */
	private array $multifieldValueTypeNames = [];

	public function mapCollection(array $items, array $fields = [], array $nestedFields = []): DtoCollection
	{
		$fields = $fields === [] ? self::FIELDS : array_values(array_unique($fields));
		$collection = new DtoCollection(BasicCompanyDto::class);

		foreach ($items as $item)
		{
			$collection->add($this->mapCompany($item, $fields, $nestedFields));
		}

		return $collection;
	}

	/**
	 * @param array{company: Company, requisites?: array<int, object>, bankRequisites?: array<int, object>}|Company $item
	 * @param string[] $fields
	 */
	private function mapCompany(array|Company $item, array $fields, array $nestedFields): BasicCompanyDto
	{
		$company = $item instanceof Company ? $item : $item['company'];
		$dto = new BasicCompanyDto();

		if (in_array('id', $fields, true))
		{
			$dto->id = (int)$company->getId();
		}
		if (in_array('title', $fields, true))
		{
			$dto->title = $company->getTitle();
		}
		if (in_array('email', $fields, true))
		{
			$dto->email = $this->mapMultifields(
				$company->getEmails(),
				'EMAIL',
				$nestedFields['email'] ?? [],
			);
		}
		if (in_array('phone', $fields, true))
		{
			$dto->phone = $this->mapMultifields(
				$company->getPhones(),
				'PHONE',
				$nestedFields['phone'] ?? [],
			);
		}
		if (in_array('requisites', $fields, true))
		{
			$dto->requisites = (new RequisiteDtoMapper())->getDtoCollectionByRequisites(
				$item instanceof Company ? [] : ($item['requisites'] ?? []),
				$nestedFields['requisites'] ?? $this->getRequisiteFields(),
			);
		}
		if (in_array('bankRequisites', $fields, true))
		{
			$dto->bankRequisites = (new BankRequisiteDtoMapper())->mapCollection(
				$item instanceof Company ? [] : ($item['bankRequisites'] ?? []),
				$nestedFields['bankRequisites'] ?? [],
			);
		}

		return $dto;
	}

	private function mapMultifields(?iterable $values, string $typeId, array $fields): DtoCollection
	{
		$values = $values === null ? [] : (is_array($values) ? $values : iterator_to_array($values));
		usort(
			$values,
			static fn($left, $right): int => $left->getId() <=> $right->getId(),
		);
		$collection = new DtoCollection(MultifieldDto::class);
		foreach ($values as $value)
		{
			$dto = new MultifieldDto();
			if ($fields === [] || in_array('id', $fields, true))
			{
				$dto->id = $value->getId();
			}
			if ($fields === [] || in_array('valueTypeId', $fields, true))
			{
				$dto->valueTypeId = $value->getValueType();
			}
			if ($fields === [] || in_array('valueType', $fields, true))
			{
				$dto->valueType = $this->mapMultifieldValueType($typeId, $value->getValueType());
			}
			if ($fields === [] || in_array('value', $fields, true))
			{
				$dto->value = $value->getValue();
			}
			$collection->add($dto);
		}

		return $collection;
	}

	private function mapMultifieldValueType(string $typeId, string $valueTypeId): MultifieldValueTypeDto
	{
		$cacheKey = $typeId . '_' . $valueTypeId;
		if (!isset($this->multifieldValueTypeNames[$cacheKey]))
		{
			$complexId = $typeId . '_' . $valueTypeId;
			$this->multifieldValueTypeNames[$cacheKey] = [
				'name' => \CCrmFieldMulti::GetEntityNameByComplex($complexId, true) ?: null,
				'nameShort' => \CCrmFieldMulti::GetEntityNameByComplex($complexId, false) ?: null,
			];
		}

		$dto = new MultifieldValueTypeDto();
		$dto->id = $valueTypeId;
		$dto->name = $this->multifieldValueTypeNames[$cacheKey]['name'];
		$dto->nameShort = $this->multifieldValueTypeNames[$cacheKey]['nameShort'];

		return $dto;
	}

	/** @return string[] */
	private function getRequisiteFields(): array
	{
		static $fields = null;
		if ($fields !== null)
		{
			return $fields;
		}

		$fields = [];
		foreach ((new RequisiteDto())->getFields() as $field)
		{
			$fields[] = $field->getPropertyName();
		}

		return $fields;
	}
}
