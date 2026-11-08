<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\Infrastructure\Rest\Dto\PriceDto;
use Bitrix\Catalog\Infrastructure\Rest\Repository\CatalogOrmRepository;
use Bitrix\Catalog\Infrastructure\Rest\Support\DtoFieldsConverter;
use Bitrix\Catalog\Model\Price as PriceModel;
use Bitrix\Catalog\PriceTable;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;

/**
 * Product prices.
 *
 * Reading is gated by the module permission only: prices are visible without rights on the
 * iblock element, same as in Engine `catalog.price.*`. Writing and deletion additionally require
 * the price rights on the element, and the check runs before the row is touched: Engine `modify`
 * deleted first and checked afterwards.
 *
 * Writes go through the domain model: it owns the price in the base currency, the modification
 * time of the iblock element and the price recalculation of the parent product of a trade offer.
 */
final readonly class PriceService
{
	/** Fields of a stored row needed to write over it. */
	private const ROW_SELECT = ['ID', 'PRODUCT_ID', 'CATALOG_GROUP_ID', 'QUANTITY_FROM', 'QUANTITY_TO'];

	/** Columns whose empty value is NULL, while the read path renders it as zero. */
	private const EMPTY_AS_NULL_FIELDS = ['EXTRA_ID', 'QUANTITY_FROM', 'QUANTITY_TO'];

	private CatalogOrmRepository $repository;
	private PriceSetValidator $setValidator;

	public function __construct(
		private AccessSupport $accessSupport,
	)
	{
		$this->repository = new CatalogOrmRepository(PriceDto::class);
		$this->setValidator = new PriceSetValidator();
	}

	public function get(int $id, ?SelectStructure $select = null): Result
	{
		$access = $this->accessSupport->ensurePriceRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$entity = $this->repository->getEntityById($id, $select);
		if ($entity === null)
		{
			return ServiceResult::notFound($id);
		}

		return ServiceResult::success(['item' => $entity]);
	}

	public function list(
		?SelectStructure $select,
		?FilterStructure $filter,
		?OrderStructure $order,
		?PaginationStructure $pagination,
	): Result
	{
		$access = $this->accessSupport->ensurePriceRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		return ServiceResult::success([
			'items' => $this->repository->getCollectionWithExtraFilters(
				$select,
				$filter,
				$order,
				$pagination,
			),
		]);
	}

	public function add(PriceDto $dto): Result
	{
		$access = $this->accessSupport->ensurePriceModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$fields = $this->nullifyEmptyValues(DtoFieldsConverter::toOrmFields($dto));
		$productId = (int)($fields['PRODUCT_ID'] ?? 0);
		$priceTypeId = (int)($fields['CATALOG_GROUP_ID'] ?? 0);

		$elementAccess = $this->accessSupport->ensureIblockElementPriceModify($productId);
		if (!$elementAccess->isSuccess())
		{
			return $elementAccess;
		}

		$setValidation = $this->validateSet($productId, $priceTypeId, $fields, null);
		if (!$setValidation->isSuccess())
		{
			return ServiceResult::validation($setValidation->getErrors());
		}

		$addResult = PriceModel::add($fields);
		if (!$addResult->isSuccess())
		{
			return ServiceResult::validation($addResult->getErrors());
		}

		return $this->get((int)$addResult->getId());
	}

	/**
	 * Partial update: only the submitted fields reach the domain model. The stored row is never
	 * merged into them: that merge is what made Engine `update` keep a stale price in the base
	 * currency and fail on an extra already removed from the reference book.
	 *
	 * `productId` and `catalogGroupId` are overwritten with the stored ones: a price cannot be
	 * moved to another product or another price type (parity with Engine, where both fields are
	 * immutable), while the domain model still needs them to nullify the extra of a base price
	 * type row and to recalculate the prices of the parent product of a trade offer.
	 */
	public function update(int $id, PriceDto $dto): Result
	{
		$access = $this->accessSupport->ensurePriceModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$row = PriceTable::getRow([
			'select' => self::ROW_SELECT,
			'filter' => ['=ID' => $id],
		]);
		if (!$row)
		{
			return ServiceResult::notFound($id);
		}

		$productId = (int)$row['PRODUCT_ID'];
		$priceTypeId = (int)$row['CATALOG_GROUP_ID'];

		$elementAccess = $this->accessSupport->ensureIblockElementPriceModify($productId);
		if (!$elementAccess->isSuccess())
		{
			return $elementAccess;
		}

		$fields = DtoFieldsConverter::toOrmFields($dto);
		$fields['PRODUCT_ID'] = $productId;
		$fields['CATALOG_GROUP_ID'] = $priceTypeId;
		$fields = $this->nullifyEmptyValues($this->completeQuantityRange($fields, $row));

		$setValidation = $this->validateSet($productId, $priceTypeId, array_merge($row, $fields), $id);
		if (!$setValidation->isSuccess())
		{
			return ServiceResult::validation($setValidation->getErrors());
		}

		$updateResult = PriceModel::update($id, $fields);
		if (!$updateResult->isSuccess())
		{
			return ServiceResult::validation($updateResult->getErrors());
		}

		return $this->get($id);
	}

	/**
	 * Deletion goes through the domain model: it updates the modification time of the iblock
	 * element and recalculates the prices of the parent product of a trade offer.
	 */
	public function delete(int $id): Result
	{
		$access = $this->accessSupport->ensurePriceModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$row = PriceTable::getRow([
			'select' => ['ID', 'PRODUCT_ID'],
			'filter' => ['=ID' => $id],
		]);
		if (!$row)
		{
			return ServiceResult::notFound($id);
		}

		$elementAccess = $this->accessSupport->ensureIblockElementPriceModify((int)$row['PRODUCT_ID']);
		if (!$elementAccess->isSuccess())
		{
			return $elementAccess;
		}

		$deleteResult = PriceModel::delete($id);
		if (!$deleteResult->isSuccess())
		{
			return ServiceResult::validation($deleteResult->getErrors());
		}

		return ServiceResult::success(['result' => true]);
	}

	/**
	 * The set under validation is every stored price of the same product and the same price type,
	 * with the written row put in place of its stored version (or appended on create).
	 *
	 * @param array<string, mixed> $item written row
	 * @param int|null $id primary key of the written row, null on create
	 */
	private function validateSet(int $productId, int $priceTypeId, array $item, ?int $id): Result
	{
		$stored = PriceTable::getList([
			'select' => self::ROW_SELECT,
			'filter' => [
				'=PRODUCT_ID' => $productId,
				'=CATALOG_GROUP_ID' => $priceTypeId,
			],
		])->fetchAll();

		$set = array_column($stored, null, 'ID');
		if ($id === null)
		{
			$set[] = $item;
		}
		else
		{
			$set[$id] = $item;
		}

		return $this->setValidator->validate(array_values($set));
	}

	/**
	 * The quantity bounds are a pair for the domain model: when only one of them is submitted,
	 * the other one is taken from the stored row. The submitted bound may still be an empty
	 * zero here; the pair is nullified afterwards.
	 *
	 * @param array<string, mixed> $fields
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function completeQuantityRange(array $fields, array $row): array
	{
		$hasLowerBound = array_key_exists('QUANTITY_FROM', $fields);
		$hasHigherBound = array_key_exists('QUANTITY_TO', $fields);
		if ($hasLowerBound === $hasHigherBound)
		{
			return $fields;
		}

		$missing = $hasLowerBound ? 'QUANTITY_TO' : 'QUANTITY_FROM';
		$storedBound = $row[$missing] ?? null;
		$fields[$missing] = $storedBound === null ? null : (int)$storedBound;

		return $fields;
	}

	/**
	 * Keeps an entity read from the API writable as it is: the read path renders an empty
	 * extra or quantity bound as zero, which the domain model would take for a real extra and a
	 * real range. Engine normalized the same three columns before every write.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function nullifyEmptyValues(array $fields): array
	{
		foreach (self::EMPTY_AS_NULL_FIELDS as $field)
		{
			if (array_key_exists($field, $fields) && (int)$fields[$field] === 0)
			{
				$fields[$field] = null;
			}
		}

		return $fields;
	}
}
