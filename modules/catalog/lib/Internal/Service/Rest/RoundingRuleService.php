<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\GroupTable;
use Bitrix\Catalog\Infrastructure\Rest\Dto\RoundingRuleDto;
use Bitrix\Catalog\Infrastructure\Rest\Repository\CatalogOrmRepository;
use Bitrix\Catalog\Infrastructure\Rest\Support\DtoFieldsConverter;
use Bitrix\Catalog\RoundingTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;

/**
 * Rounding rules of price types.
 *
 * Writes always go through RoundingTable: its handlers own authorship, dates,
 * the `catalog_price_round_{priceTypeId}` cache invalidation and the REST events.
 */
final readonly class RoundingRuleService
{
	/**
	 * Validation errors carry the offending DTO property as their code: the V3 transport
	 * renders Error::getCode() as the `field` key of a validation item.
	 */
	private const FIELD_PRICE_TYPE = 'catalogGroupId';
	private const FIELD_PRICE = 'price';
	private const FIELD_ROUND_TYPE = 'roundType';
	private const FIELD_ROUND_PRECISION = 'roundPrecision';

	private CatalogOrmRepository $repository;

	public function __construct(
		private AccessSupport $accessSupport,
	)
	{
		$this->repository = new CatalogOrmRepository(RoundingRuleDto::class);
	}

	public function get(int $id, ?SelectStructure $select = null): Result
	{
		$access = $this->accessSupport->ensurePriceTypeRead();
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
		$access = $this->accessSupport->ensurePriceTypeRead();
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

	public function add(RoundingRuleDto $dto): Result
	{
		$access = $this->accessSupport->ensurePriceTypeModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$fields = DtoFieldsConverter::toOrmFields($dto);
		$checkFieldsResult = $this->checkFields($fields);
		if (!$checkFieldsResult->isSuccess())
		{
			return ServiceResult::validation($checkFieldsResult->getErrors());
		}

		$addResult = RoundingTable::add($fields);
		if (!$addResult->isSuccess())
		{
			return ServiceResult::validation($addResult->getErrors());
		}

		return $this->get((int)$addResult->getId());
	}

	/**
	 * Partial update: only the submitted fields reach the ORM, while validation runs
	 * against the submitted fields merged over the stored row.
	 */
	public function update(int $id, RoundingRuleDto $dto): Result
	{
		$access = $this->accessSupport->ensurePriceTypeModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$row = RoundingTable::getByPrimary($id)->fetch();
		if (!$row)
		{
			return ServiceResult::notFound($id);
		}

		$fields = DtoFieldsConverter::toOrmFields($dto);
		$checkFieldsResult = $this->checkFields(array_merge($row, $fields), $id);
		if (!$checkFieldsResult->isSuccess())
		{
			return ServiceResult::validation($checkFieldsResult->getErrors());
		}

		$updateResult = RoundingTable::update($id, $fields);
		if (!$updateResult->isSuccess())
		{
			return ServiceResult::validation($updateResult->getErrors());
		}

		return $this->get($id);
	}

	public function delete(int $id): Result
	{
		$access = $this->accessSupport->ensurePriceTypeModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$row = RoundingTable::getByPrimary($id)->fetch();
		if (!$row)
		{
			return ServiceResult::notFound($id);
		}

		$deleteResult = RoundingTable::delete($id);
		if (!$deleteResult->isSuccess())
		{
			return ServiceResult::validation($deleteResult->getErrors());
		}

		return ServiceResult::success(['result' => true]);
	}

	/**
	 * @param array<string, mixed> $fields full field set of the resulting row
	 * @param int|null $excludeId row excluded from the uniqueness check on update
	 */
	private function checkFields(array $fields, ?int $excludeId = null): Result
	{
		$result = new Result();

		$priceTypeId = (int)($fields['CATALOG_GROUP_ID'] ?? 0);
		if (!isset(GroupTable::getTypeList()[$priceTypeId]))
		{
			// Without an existing price type the uniqueness check below is meaningless.
			$result->addError(new Error(
				'The specified price type does not exist',
				self::FIELD_PRICE_TYPE,
			));

			return $result;
		}

		$roundTypes = RoundingTable::getRoundTypes();
		if (!in_array((int)($fields['ROUND_TYPE'] ?? 0), $roundTypes, true))
		{
			$result->addError(new Error(
				'Invalid rounding type provided. The available values are: ' . implode(', ', $roundTypes),
				self::FIELD_ROUND_TYPE,
			));
		}

		$precisionList = RoundingTable::getPresetRoundingValues();
		$presets = array_map(static fn ($preset): float => (float)$preset, $precisionList);
		if (!in_array((float)($fields['ROUND_PRECISION'] ?? 0), $presets, true))
		{
			$result->addError(new Error(
				'Invalid rounding precision provided. The available values are: ' . implode(', ', $precisionList),
				self::FIELD_ROUND_PRECISION,
			));
		}

		$price = (float)($fields['PRICE'] ?? 0);
		if ($price < 0)
		{
			$result->addError(new Error(
				'The rounding rule price threshold cannot be negative',
				self::FIELD_PRICE,
			));
		}
		elseif ($this->isDuplicate($priceTypeId, $price, $excludeId))
		{
			$result->addError(new Error(
				'A rounding rule with the same price threshold already exists for this price type',
				self::FIELD_PRICE,
			));
		}

		return $result;
	}

	private function isDuplicate(int $priceTypeId, float $price, ?int $excludeId): bool
	{
		$filter = [
			'=CATALOG_GROUP_ID' => $priceTypeId,
			'=PRICE' => $price,
		];
		if ($excludeId !== null)
		{
			$filter['!=ID'] = $excludeId;
		}

		return (bool)RoundingTable::getRow([
			'select' => ['ID'],
			'filter' => $filter,
		]);
	}
}
