<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\Controller\ErrorCode;
use Bitrix\Catalog\Infrastructure\Rest\Dto\ProductDto;
use Bitrix\Catalog\Infrastructure\Rest\Support\ProductFieldsMapper;
use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\RestView;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Parent product of an offer being created: the parent travels as the value of the sku binding
 * property of the iblock (key PROPERTY_{id}), and it decides whether the offer is bound or free.
 * Only creation resolves the parent, in parity with V1 {@see \Bitrix\Catalog\Controller\Product\Offer}.
 */
final class ProductOfferParentResolver
{
	private const ALLOWED_PARENT_TYPES = [
		ProductTable::TYPE_PRODUCT,
		ProductTable::TYPE_SKU,
		ProductTable::TYPE_EMPTY_SKU,
	];

	/**
	 * @return Result data on success: {type: int, properties: array<string, mixed>}
	 *         - the property set with the resolved parent applied.
	 */
	public function resolveForAdd(ProductDto $dto): Result
	{
		$fields = get_object_vars($dto);
		$iblockId = (int)($fields['iblockId'] ?? 0);
		$properties = $this->normalizePropertyKeys($fields['properties'] ?? null);

		$description = (new RestView\Product())->getCatalogDescription($iblockId);
		if (!$description->isSuccess())
		{
			return ServiceResult::validation($description->getErrors());
		}
		$catalog = $description->getData();

		$parentKey = 'PROPERTY_' . $catalog['SKU_PROPERTY_ID'];
		$parent = $this->resolveParentId(
			$properties[$parentKey] ?? null,
			(int)$catalog['PRODUCT_IBLOCK_ID'],
		);
		if (!$parent->isSuccess())
		{
			return $parent;
		}

		$parentId = $parent->getData()['parentId'];
		if ($parentId === null)
		{
			unset($properties[$parentKey]);
		}
		else
		{
			$properties[$parentKey] = $parentId;
		}

		return ServiceResult::success([
			'type' => $parentId === null ? ProductTable::TYPE_FREE_OFFER : ProductTable::TYPE_OFFER,
			'properties' => $properties,
		]);
	}

	/**
	 * The key of a property may arrive as a bare identifier or with leading zeros: it is brought to
	 * the one spelling PROPERTY_{id} on the way to storage by {@see ProductFieldsMapper::dtoToDomainFields}.
	 * Applying the same rule here makes the resolver read the binding the write is going to store,
	 * so the parent it checks is the parent it saves. Several spellings of one key are refused
	 * before the resolver runs.
	 *
	 * @return array<string, mixed>
	 */
	private function normalizePropertyKeys(mixed $properties): array
	{
		if (!is_array($properties))
		{
			return [];
		}

		$result = [];
		foreach ($properties as $key => $value)
		{
			$result[ProductFieldsMapper::normalizePropertyKey((string)$key)] = $value;
		}

		return $result;
	}

	/**
	 * @param mixed $value raw property value in any form the write accepts: an identifier, {VALUE: id},
	 *        or a list of those, plain or keyed like n0; the binding is single, so a list may carry
	 *        one identifier at most
	 * @return Result data on success: {parentId: int|null}
	 */
	private function resolveParentId(mixed $value, int $productIblockId): Result
	{
		$candidates = $this->extractParentIds($value);
		if (count($candidates) > 1)
		{
			return ServiceResult::validation(new Error('Parent product must be a single value.'));
		}

		$parentId = $candidates[0] ?? 0;
		if ($parentId <= 0)
		{
			return ServiceResult::success(['parentId' => null]);
		}

		$row = ProductTable::getRow([
			'select' => [
				'ID',
				'TYPE',
				'IBLOCK_ID' => 'IBLOCK_ELEMENT.IBLOCK_ID',
			],
			'filter' => ['=ID' => $parentId],
		]);
		if ($row === null)
		{
			return ServiceResult::validation(new Error(
				'Parent product not found.',
				ErrorCode::PRODUCT_OFFER_PARENT_NOT_FOUND,
			));
		}

		if (!in_array((int)$row['TYPE'], self::ALLOWED_PARENT_TYPES, true))
		{
			return ServiceResult::validation(new Error(
				'Invalid parent product type.',
				ErrorCode::PRODUCT_OFFER_BAD_PARENT_TYPE,
			));
		}

		if ((int)$row['IBLOCK_ID'] !== $productIblockId)
		{
			return ServiceResult::validation(new Error(
				'Invalid information block of the parent product.',
				ErrorCode::PRODUCT_OFFER_BAD_PARENT_IBLOCK_ID,
			));
		}

		return ServiceResult::success(['parentId' => (int)$row['ID']]);
	}

	/**
	 * @return list<int> the non-empty identifiers the value carries
	 */
	private function extractParentIds(mixed $value): array
	{
		if (!is_array($value))
		{
			$items = [$value];
		}
		elseif (array_key_exists('VALUE', $value))
		{
			$items = [$value['VALUE']];
		}
		else
		{
			$items = array_map(
				static fn(mixed $item): mixed => is_array($item) ? ($item['VALUE'] ?? null) : $item,
				array_values($value),
			);
		}

		$result = [];
		foreach ($items as $item)
		{
			$id = is_scalar($item) ? (int)$item : 0;
			if ($id > 0)
			{
				$result[] = $id;
			}
		}

		return $result;
	}
}
