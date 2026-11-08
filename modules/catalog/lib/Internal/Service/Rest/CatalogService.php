<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\Infrastructure\Rest\Dto\CatalogDto;
use Bitrix\Catalog\Infrastructure\Rest\Support\ApplicationErrors;
use Bitrix\Catalog\Infrastructure\Rest\Support\DtoFieldsConverter;
use Bitrix\Catalog\Infrastructure\Rest\Support\FilterStructureSupport;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Text\StringHelper;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;
use CCatalog;
use Generator;

/**
 * Trade catalog (iblock registered in b_catalog_iblock) domain service for REST V3.
 *
 * Field validation is delegated to \CCatalog::CheckFields, which \CCatalog::Add / ::Update
 * call internally; failures arrive as an application exception and are reported as one error.
 */
final class CatalogService
{
	use ApplicationErrors;

	/**
	 * \CCatalog::GetList columns behind the response contract.
	 *
	 * @see \Bitrix\Catalog\Infrastructure\Rest\Dto\Mapping\CatalogMapper
	 */
	private const DOMAIN_FIELDS = [
		'ID',
		'IBLOCK_ID',
		'YANDEX_EXPORT',
		'SUBSCRIPTION',
		'VAT_ID',
		'PRODUCT_IBLOCK_ID',
		'SKU_PROPERTY_ID',
		'IBLOCK_TYPE_ID',
		'LID',
		'NAME',
	];

	public function __construct(
		private readonly AccessSupport $accessSupport,
	)
	{
	}

	public function list(
		?SelectStructure $select,
		?FilterStructure $filter,
		?OrderStructure $order,
		?PaginationStructure $pagination,
	): Result
	{
		$access = $this->accessSupport->ensureTradeCatalogRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$unsupportedFilter = FilterStructureSupport::findUnsupportedSemantics($filter);
		if ($unsupportedFilter !== null)
		{
			return ServiceResult::validation([$unsupportedFilter]);
		}

		$domainFilter = $this->buildDomainFilter($filter);
		if ($domainFilter instanceof Result)
		{
			return $domainFilter;
		}

		// the filter is one conjunction - FilterStructureSupport rejects OR and NOT - so an empty
		// IN list leaves it unsatisfiable; this shortcut has to go when OR groups get supported
		if ($this->hasEmptyInCondition($filter))
		{
			return ServiceResult::success(['items' => []]);
		}

		$nav = $this->buildNavData($pagination);
		if ($nav instanceof Result)
		{
			return $nav;
		}

		$iterator = CCatalog::GetList(
			$this->buildDomainOrder($order),
			$domainFilter,
			false,
			$nav,
			$this->buildDomainSelect($select),
		);

		$items = [];
		while ($row = $iterator->Fetch())
		{
			$items[] = $row;
		}

		return ServiceResult::success(['items' => $items]);
	}

	public function get(int $id): Result
	{
		$access = $this->accessSupport->ensureTradeCatalogRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$row = $this->findRow($id);
		if ($row === null)
		{
			return ServiceResult::notFound($id);
		}

		return ServiceResult::success(['item' => $row]);
	}

	public function add(CatalogDto $dto): Result
	{
		$access = $this->accessSupport->ensureTradeCatalogModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$fields = DtoFieldsConverter::toOrmFields($dto);
		$iblockId = (int)($fields['IBLOCK_ID'] ?? 0);
		if ($iblockId > 0 && $this->findRow($iblockId) !== null)
		{
			return ServiceResult::alreadyExists($iblockId, 'The iblock is already registered as a catalog');
		}

		try
		{
			$added = CCatalog::Add($fields);
		}
		catch (DuplicateEntryException)
		{
			// the lookup above and the insert are not atomic, and IBLOCK_ID is the only key of
			// b_catalog_iblock: a duplicate means a parallel request registered the iblock first
			return ServiceResult::alreadyExists($iblockId, 'The iblock is already registered as a catalog');
		}
		catch (SqlQueryException)
		{
			return ServiceResult::validationFailed('Internal error adding catalog. Try adding again.');
		}

		if (!$added)
		{
			return $this->applicationFailure('Validate catalog error');
		}

		return $this->get($iblockId);
	}

	public function update(int $id, CatalogDto $dto): Result
	{
		$access = $this->accessSupport->ensureTradeCatalogModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		if ($this->findRow($id) === null)
		{
			return ServiceResult::notFound($id);
		}

		$fields = DtoFieldsConverter::toOrmFields($dto);
		if (array_key_exists('IBLOCK_ID', $fields))
		{
			return ServiceResult::fieldValidationFailed('iblockId', 'Field iblockId cannot be changed');
		}

		if (!CCatalog::Update($id, $fields))
		{
			return $this->applicationFailure('Validate catalog error');
		}

		return $this->get($id);
	}

	/**
	 * Domain delete keeps the OnCatalogDelete events and the sku / catalog cache resets.
	 */
	public function delete(int $id): Result
	{
		$access = $this->accessSupport->ensureTradeCatalogModify();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$row = $this->findRow($id);
		if ($row === null)
		{
			return ServiceResult::notFound($id);
		}

		if ($this->isOffersRow($row))
		{
			return ServiceResult::validation([
				new Error('Catalog is offers and cannot be deleted'),
			]);
		}

		if (!CCatalog::Delete($id))
		{
			return $this->applicationFailure('delete catalog error');
		}

		return ServiceResult::success(['result' => true]);
	}

	public function isOffers(int $id): Result
	{
		$access = $this->accessSupport->ensureTradeCatalogRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$row = $this->findRow($id);
		if ($row === null)
		{
			return ServiceResult::notFound($id);
		}

		return ServiceResult::success(['result' => $this->isOffersRow($row)]);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function findRow(int $id): ?array
	{
		if ($id <= 0)
		{
			return null;
		}

		$row = CCatalog::GetByID($id);

		return is_array($row) ? $row : null;
	}

	/**
	 * A catalog bound to a product iblock is the offers catalog of that iblock.
	 *
	 * @param array<string, mixed> $row
	 */
	private function isOffersRow(array $row): bool
	{
		return (int)($row['PRODUCT_IBLOCK_ID'] ?? 0) !== 0;
	}

	private function applicationFailure(string $fallbackMessage): Result
	{
		return ServiceResult::validationFailed(
			$this->getLastApplicationErrorText() ?? $fallbackMessage,
		);
	}

	/**
	 * The mapper trims the response by the client select but loads nothing:
	 * requested fields have to reach \CCatalog::GetList as domain columns.
	 * Without a client select the projection is the response contract: `*` would additionally
	 * join the offers row of \CCatalog::GetList for columns the response has no place for.
	 *
	 * @return list<string>
	 */
	private function buildDomainSelect(?SelectStructure $select): array
	{
		$fields = $select?->getList() ?? [];
		if ($fields === [])
		{
			return self::DOMAIN_FIELDS;
		}

		$result = [];
		foreach ($fields as $field)
		{
			$result[] = $this->toDomainField((string)$field);
		}

		return $result;
	}

	/**
	 * @return array<string, string>
	 */
	private function buildDomainOrder(?OrderStructure $order): array
	{
		$items = $order?->getItems() ?? [];
		if ($items === [])
		{
			return ['ID' => 'ASC'];
		}

		$result = [];
		foreach ($items as $item)
		{
			$result[$this->toDomainField($item->getProperty())] = $item->getOrder()->value;
		}

		return $result;
	}

	/**
	 * @return array<string, mixed>|Result
	 */
	private function buildDomainFilter(?FilterStructure $filter): array|Result
	{
		if ($filter === null)
		{
			return [];
		}

		$result = [];
		foreach ($this->flattenConditions($filter) as $condition)
		{
			$operator = $condition->getOperator();
			$prefix = $this->operatorPrefix($operator);
			if ($prefix === null)
			{
				return ServiceResult::validation([
					new Error(sprintf('Filter operator "%s" is not supported', $operator->value)),
				]);
			}

			$property = (string)$condition->getLeftOperand();
			$key = $prefix . $this->toDomainField($property);
			if (array_key_exists($key, $result))
			{
				return ServiceResult::validation([
					new Error(sprintf('Filter has conflicting conditions for the field "%s"', $property)),
				]);
			}

			$result[$key] = $condition->getRightOperand();
		}

		return $result;
	}

	/**
	 * \CCatalog::PrepareSql skips an empty IN list before it reaches its own `(1 = 2)` guard,
	 * so the condition would leave the WHERE clause and the answer would be the whole list.
	 */
	private function hasEmptyInCondition(?FilterStructure $filter): bool
	{
		if ($filter === null)
		{
			return false;
		}

		foreach ($this->flattenConditions($filter) as $condition)
		{
			if ($condition->getOperator() === Operator::In && $condition->getRightOperand() === [])
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * OR / NOT / duplicate conditions are rejected beforehand, so nested AND groups flatten safely.
	 *
	 * @return Generator<Condition>
	 */
	private function flattenConditions(FilterStructure $filter): Generator
	{
		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				yield from $this->flattenConditions($condition);
			}
			elseif ($condition instanceof Condition)
			{
				yield $condition;
			}
		}
	}

	/**
	 * \CCatalog::GetFilterOperation has no BETWEEN counterpart.
	 */
	private function operatorPrefix(Operator $operator): ?string
	{
		return match ($operator)
		{
			Operator::Equal => '=',
			Operator::NotEqual => '!=',
			Operator::Greater => '>',
			Operator::GreaterOrEqual => '>=',
			Operator::Less => '<',
			Operator::LessOrEqual => '<=',
			Operator::In => '@',
			Operator::Between => null,
		};
	}

	private function toDomainField(string $property): string
	{
		return strtoupper(StringHelper::camel2snake($property));
	}

	/**
	 * \CCatalog::GetList paginates by page, so the offset has to be page aligned.
	 * `bShowAll` keeps the request parameter SHOWALL_N from disabling the page size,
	 * `checkOutOfRange` makes a page past the end an empty list instead of the first page.
	 *
	 * @return array{nPageSize: int, iNumPage: int, bShowAll: false, checkOutOfRange: true}|Result
	 */
	private function buildNavData(?PaginationStructure $pagination): array|Result
	{
		$limit = max(1, $pagination?->getLimit() ?? PaginationStructure::DEFAULT_LIMIT);
		$offset = $pagination?->getOffset() ?? 0;

		if ($offset < 0 || $offset % $limit !== 0)
		{
			return ServiceResult::validation([
				new Error('pagination.offset must be a non-negative multiple of pagination.limit'),
			]);
		}

		return [
			'nPageSize' => $limit,
			'iNumPage' => (int)($offset / $limit) + 1,
			'bShowAll' => false,
			'checkOutOfRange' => true,
		];
	}
}
