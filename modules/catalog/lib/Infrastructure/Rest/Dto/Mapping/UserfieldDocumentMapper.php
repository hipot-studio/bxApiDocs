<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Catalog\Infrastructure\Rest\Dto\UserfieldDocumentDto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

final class UserfieldDocumentMapper extends Mapper
{
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(UserfieldDocumentDto::class);
		$emptyFields = $fields === [];

		foreach ($items as $rawItem)
		{
			if (!is_array($rawItem))
			{
				continue;
			}

			$collection->add($this->mapItem($rawItem, $fields, $emptyFields));
		}

		return $collection;
	}

	/**
	 * @param array<string, mixed> $rawItem
	 * @param list<string>|array<string, mixed> $fields
	 */
	private function mapItem(array $rawItem, array $fields, bool $emptyFields): UserfieldDocumentDto
	{
		$dto = new UserfieldDocumentDto();

		if ($this->shouldMap('id', $fields, $emptyFields))
		{
			$id = $rawItem['ID'] ?? $rawItem['id'] ?? null;
			$dto->id = $id !== null ? (int)$id : null;
		}

		if ($this->shouldMap('docType', $fields, $emptyFields))
		{
			$docType = $rawItem['DOC_TYPE'] ?? $rawItem['docType'] ?? null;
			$dto->docType = $docType !== null ? (string)$docType : null;
		}

		if ($this->shouldMap('userFields', $fields, $emptyFields))
		{
			if (isset($rawItem['userFields']) && is_array($rawItem['userFields']))
			{
				$dto->userFields = $rawItem['userFields'];
			}
			else
			{
				$userFields = $rawItem;
				unset($userFields['ID'], $userFields['DOC_TYPE'], $userFields['id'], $userFields['docType']);
				$dto->userFields = $userFields === [] ? null : $userFields;
			}
		}

		return $dto;
	}

	/**
	 * @param list<string>|array<string, mixed> $fields
	 */
	private function shouldMap(string $fieldName, array $fields, bool $emptyFields): bool
	{
		if ($emptyFields)
		{
			return true;
		}

		return in_array($fieldName, $fields, true) || array_key_exists($fieldName, $fields);
	}
}
