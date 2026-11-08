<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Catalog\Infrastructure\Rest\Dto\ProductImageDto;
use Bitrix\Catalog\Infrastructure\Rest\Dto\ProductImageType;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;
use CFile;

final class ProductImageMapper extends Mapper
{
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(ProductImageDto::class);
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
	private function mapItem(array $rawItem, array $fields, bool $emptyFields): ProductImageDto
	{
		$dto = new ProductImageDto();

		if ($this->shouldMap('id', $fields, $emptyFields))
		{
			$id = $rawItem['ID'] ?? null;
			$dto->id = $id !== null ? (int)$id : null;
		}
		if ($this->shouldMap('productId', $fields, $emptyFields))
		{
			$dto->productId = (int)($rawItem['PRODUCT_ID'] ?? 0);
		}
		if ($this->shouldMap('type', $fields, $emptyFields))
		{
			$type = ProductImageType::tryFrom((string)($rawItem['TYPE'] ?? ''));
			if ($type !== null)
			{
				$dto->type = $type;
			}
		}
		if ($this->shouldMap('name', $fields, $emptyFields))
		{
			$name = $rawItem['ORIGINAL_NAME'] ?? null;
			if ($name === null || $name === '')
			{
				$name = $rawItem['FILE_NAME'] ?? null;
			}
			$dto->name = $name !== null ? (string)$name : null;
		}
		if ($this->shouldMap('createTime', $fields, $emptyFields))
		{
			$dto->createTime = $this->resolveCreateTime($rawItem);
		}
		// Deliberately the same source for both fields: a REST download link needs the server
		// context the mapper has no access to.
		$mapDetailUrl = $this->shouldMap('detailUrl', $fields, $emptyFields);
		$mapDownloadUrl = $this->shouldMap('downloadUrl', $fields, $emptyFields);
		if ($mapDetailUrl || $mapDownloadUrl)
		{
			$source = $this->resolveSource($rawItem);
			if ($mapDetailUrl)
			{
				$dto->detailUrl = $source;
			}
			if ($mapDownloadUrl)
			{
				$dto->downloadUrl = $source;
			}
		}

		return $dto;
	}

	/**
	 * @param array<string, mixed> $rawItem
	 */
	private function resolveCreateTime(array $rawItem): ?DateTime
	{
		$value = $rawItem['TIMESTAMP_X'] ?? null;
		if ($value instanceof DateTime)
		{
			return $value;
		}

		return $value === null ? null : DateTime::createFromUserTime((string)$value);
	}

	/**
	 * @param array<string, mixed> $rawItem
	 */
	private function resolveSource(array $rawItem): ?string
	{
		$source = CFile::getFileSRC($rawItem);

		return $source === false || $source === '' ? null : (string)$source;
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
