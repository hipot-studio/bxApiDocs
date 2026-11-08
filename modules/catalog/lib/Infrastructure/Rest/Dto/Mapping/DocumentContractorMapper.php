<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Catalog\Infrastructure\Rest\Dto\DocumentContractorDto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

final class DocumentContractorMapper extends Mapper
{
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(DocumentContractorDto::class);
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
	private function mapItem(array $rawItem, array $fields, bool $emptyFields): DocumentContractorDto
	{
		$dto = new DocumentContractorDto();

		if ($this->shouldMap('id', $fields, $emptyFields))
		{
			$id = $rawItem['ID'] ?? $rawItem['id'] ?? null;
			$dto->id = $id !== null ? (int)$id : null;
		}
		if ($this->shouldMap('documentId', $fields, $emptyFields))
		{
			$documentId = $rawItem['DOCUMENT_ID'] ?? $rawItem['documentId'] ?? null;
			$dto->documentId = $documentId !== null ? (int)$documentId : 0;
		}
		if ($this->shouldMap('entityTypeId', $fields, $emptyFields))
		{
			$entityTypeId = $rawItem['ENTITY_TYPE_ID'] ?? $rawItem['entityTypeId'] ?? null;
			$dto->entityTypeId = $entityTypeId !== null ? (int)$entityTypeId : 0;
		}
		if ($this->shouldMap('entityId', $fields, $emptyFields))
		{
			$entityId = $rawItem['ENTITY_ID'] ?? $rawItem['entityId'] ?? null;
			$dto->entityId = $entityId !== null ? (int)$entityId : 0;
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
