<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\Infrastructure\Rest\Dto\SectionDto;
use Bitrix\Catalog\Infrastructure\Rest\Support\ApplicationErrors;
use Bitrix\Catalog\Infrastructure\Rest\Support\FilterStructureSupport;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldsInRequestFilterPropertyException;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;
use CIBlock;
use CIBlockSection;
use ReflectionProperty;

/**
 * Catalog sections over the \CIBlockSection domain: reads go through GetList,
 * writes through the domain class so the section tree side effects stay in iblock.
 */
final class SectionService
{
	use ApplicationErrors;

	/** DTO property => \CIBlockSection field */
	private const DOMAIN_FIELDS = [
		'id' => 'ID',
		'iblockId' => 'IBLOCK_ID',
		'name' => 'NAME',
		'iblockSectionId' => 'IBLOCK_SECTION_ID',
		'active' => 'ACTIVE',
		'code' => 'CODE',
		'xmlId' => 'XML_ID',
		'sort' => 'SORT',
		'description' => 'DESCRIPTION',
		'descriptionType' => 'DESCRIPTION_TYPE',
	];

	/**
	 * \CIBlockSection::GetList silently ignores IBLOCK_SECTION_ID in the filter;
	 * only SECTION_ID works and it matches direct children.
	 */
	private const FILTER_FIELD_OVERRIDES = [
		'iblockSectionId' => 'SECTION_ID',
	];

	/**
	 * \CIBlockSection::GetFilter passes these columns to \CIBlock::FilterCreate as `string_equal`,
	 * and that branch reads the bare `N` operation only: the `!=` prefix arrives as `NI` and still
	 * builds the positive comparison. `!` is the prefix that negates them.
	 *
	 * @see \CIBlock::FilterCreateEx
	 */
	private const STRING_EQUAL_FILTER_PROPERTIES = [
		'active',
	];

	private const CODE_LOCK_TIMEOUT = 5;

	public function __construct(
		private readonly AccessSupport $accessSupport,
	)
	{
	}

	/**
	 * @throws RequiredFieldsInRequestFilterPropertyException when the filter names no iblock
	 */
	public function list(
		?SelectStructure $select,
		?FilterStructure $filter,
		?OrderStructure $order,
		?PaginationStructure $pagination,
	): Result
	{
		$access = $this->accessSupport->ensureSectionEntityRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$unsupportedFilter = FilterStructureSupport::findUnsupportedSemantics($filter);
		if ($unsupportedFilter !== null)
		{
			return ServiceResult::validation([$unsupportedFilter]);
		}

		$unsupportedIblockId = $this->findUnsupportedIblockIdCondition($filter);
		if ($unsupportedIblockId !== null)
		{
			return ServiceResult::validation([$unsupportedIblockId]);
		}

		$unsupportedParent = $this->findUnsupportedIblockSectionIdCondition($filter);
		if ($unsupportedParent !== null)
		{
			return ServiceResult::validation([$unsupportedParent]);
		}

		$domainFilter = $this->filterToDomain($filter);
		if ($domainFilter instanceof Result)
		{
			return $domainFilter;
		}

		// the gate and the query must read the iblock from the same place
		$iblockId = $this->requireFilterIblockId($domainFilter);
		$check = $this->accessSupport->ensureIblockSectionList($iblockId);
		if (!$check->isSuccess())
		{
			return $check;
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

		$items = [];
		$iterator = CIBlockSection::GetList(
			$this->orderToDomain($order),
			$domainFilter,
			false,
			$this->selectToDomain($select),
			$nav,
		);
		while ($row = $iterator->Fetch())
		{
			$items[] = $row;
		}

		return ServiceResult::success(['items' => $items]);
	}

	public function get(int $id, ?SelectStructure $select = null): Result
	{
		$access = $this->accessSupport->ensureSectionEntityRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		// rights are answered before existence: an unknown id resolves to iblock 0, which is
		// denied to everyone but an admin, so a caller without rights on the iblock gets the
		// same answer for a closed section and for a missing one
		$section = $this->findSection($id);

		$check = $this->accessSupport->ensureIblockSectionGet($id, $section['IBLOCK_ID'] ?? 0);
		if (!$check->isSuccess())
		{
			return $check;
		}

		if ($section === null)
		{
			return ServiceResult::notFound($id);
		}

		$row = CIBlockSection::GetList([], ['ID' => $id], false, $this->selectToDomain($select))->Fetch();
		if (!$row)
		{
			return ServiceResult::notFound($id);
		}

		return ServiceResult::success(['item' => $row]);
	}

	public function add(SectionDto $dto): Result
	{
		$access = $this->accessSupport->ensureSectionAdd();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$fields = $this->dtoToDomainFields($dto, ['id']);
		$iblockId = (int)($fields['IBLOCK_ID'] ?? 0);

		$check = $this->accessSupport->ensureIblockSectionAdd($iblockId);
		if (!$check->isSuccess())
		{
			return $check;
		}

		$check = $this->ensureParentBindAllowed($fields);
		if (!$check->isSuccess())
		{
			return $check;
		}

		return $this->withUniqueCodeGuard(
			$iblockId,
			$fields,
			null,
			fn(): Result => $this->addSection($fields),
		);
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function addSection(array $fields): Result
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		$section = new CIBlockSection();
		try
		{
			$id = (int)$section->Add($fields);
		}
		catch (SqlQueryException)
		{
			$connection->rollbackTransaction();

			return ServiceResult::validationFailed('Internal error adding section. Try adding again.');
		}

		$error = (string)$section->getLastError();
		if ($id <= 0 || $error !== '')
		{
			$connection->rollbackTransaction();

			return ServiceResult::validationFailed($error !== '' ? $error : 'Add section error');
		}

		$connection->commitTransaction();

		return $this->get($id);
	}

	public function update(int $id, SectionDto $dto): Result
	{
		$access = $this->accessSupport->ensureSectionUpdate();
		if (!$access->isSuccess())
		{
			return $access;
		}

		// \CIBlockSection::Update returns false with an empty getLastError() for a missing section
		$section = $this->findSection($id);

		// rights before existence, see self::get()
		$check = $this->accessSupport->ensureIblockSectionUpdate($id, $section['IBLOCK_ID'] ?? 0);
		if (!$check->isSuccess())
		{
			return $check;
		}

		if ($section === null)
		{
			return ServiceResult::notFound($id);
		}

		$fields = $this->dtoToDomainFields($dto, ['id']);
		// the domain silently drops IBLOCK_ID on update: a section cannot change its iblock
		if (array_key_exists('IBLOCK_ID', $fields))
		{
			return ServiceResult::fieldValidationFailed('iblockId', 'Field iblockId cannot be changed');
		}

		if ($fields === [])
		{
			return $this->get($id);
		}

		$check = $this->ensureParentBindAllowed($fields);
		if (!$check->isSuccess())
		{
			return $check;
		}

		return $this->withUniqueCodeGuard(
			(int)$section['IBLOCK_ID'],
			$fields,
			$id,
			fn(): Result => $this->updateSection($id, $fields),
		);
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function updateSection(int $id, array $fields): Result
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		$domainSection = new CIBlockSection();
		try
		{
			$updated = $domainSection->Update($id, $fields);
		}
		catch (SqlQueryException)
		{
			$connection->rollbackTransaction();

			return ServiceResult::validationFailed('Internal error updating section. Try updating again.');
		}

		$error = (string)$domainSection->getLastError();
		if ($updated === false || $error !== '')
		{
			$connection->rollbackTransaction();

			return ServiceResult::validationFailed($error !== '' ? $error : 'Update section error');
		}

		$connection->commitTransaction();

		return $this->get($id);
	}

	/**
	 * The domain delete is recursive: it removes child sections and physically removes
	 * the products that have no other section binding.
	 */
	public function delete(int $id): Result
	{
		$access = $this->accessSupport->ensureSectionDelete();
		if (!$access->isSuccess())
		{
			return $access;
		}

		// \CIBlockSection::Delete reports success for a missing or inaccessible section
		$section = $this->findSection($id);

		// rights before existence, see self::get()
		$check = $this->accessSupport->ensureIblockSectionDelete($id, $section['IBLOCK_ID'] ?? 0);
		if (!$check->isSuccess())
		{
			return $check;
		}

		if ($section === null)
		{
			return ServiceResult::notFound($id);
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$deleted = CIBlockSection::Delete($id);
		}
		catch (SqlQueryException)
		{
			$connection->rollbackTransaction();

			return ServiceResult::validationFailed('Internal error deleting section. Try deleting again.');
		}

		if (!$deleted)
		{
			$connection->rollbackTransaction();

			return ServiceResult::validationFailed(
				$this->getLastApplicationErrorText() ?? 'Delete section error',
			);
		}

		// the domain looks the section up by section_read and reports success without deleting
		// one the caller cannot read, e.g. with section_delete alone in the extended rights mode
		if ($this->findSection($id) !== null)
		{
			$connection->rollbackTransaction();

			return ServiceResult::accessDenied();
		}

		$connection->commitTransaction();

		return ServiceResult::success(['result' => true]);
	}

	/**
	 * @return array{ID: int, IBLOCK_ID: int}|null
	 */
	private function findSection(int $id): ?array
	{
		if ($id <= 0)
		{
			return null;
		}

		$row = SectionTable::getRow([
			'select' => ['ID', 'IBLOCK_ID'],
			'filter' => ['=ID' => $id],
		]);

		if ($row === null)
		{
			return null;
		}

		return [
			'ID' => (int)$row['ID'],
			'IBLOCK_ID' => (int)$row['IBLOCK_ID'],
		];
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function ensureParentBindAllowed(array $fields): Result
	{
		$parentId = (int)($fields['IBLOCK_SECTION_ID'] ?? 0);
		if ($parentId <= 0)
		{
			return ServiceResult::success();
		}

		return $this->accessSupport->ensureIblockSectionBind($parentId);
	}

	/**
	 * \CIBlockSection checks CODE uniqueness inside CheckFields(), before it takes the iblock
	 * lock, and (IBLOCK_ID, CODE) carries a non unique index only: two parallel writes both pass
	 * the check and both reach the insert. Hold a named lock over check and write together.
	 *
	 * @param array<string, mixed> $fields
	 * @param callable(): Result $write
	 */
	private function withUniqueCodeGuard(int $iblockId, array $fields, ?int $excludeId, callable $write): Result
	{
		if (!$this->isUniqueCodeEnforced($iblockId, $fields))
		{
			return $write();
		}

		$connection = Application::getConnection();
		$lockName = 'catalog_section_code_' . $iblockId;
		if (!$connection->lock($lockName, self::CODE_LOCK_TIMEOUT))
		{
			return ServiceResult::validationFailed('Section codes of the iblock are busy. Try again.');
		}

		try
		{
			$check = $this->ensureUniqueCode($iblockId, $fields, $excludeId);
			if (!$check->isSuccess())
			{
				return $check;
			}

			return $write();
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function isUniqueCodeEnforced(int $iblockId, array $fields): bool
	{
		if (!isset($fields['CODE']) || (string)$fields['CODE'] === '')
		{
			return false;
		}

		$iblock = CIBlock::GetArrayByID($iblockId);

		return ($iblock['FIELDS']['SECTION_CODE']['DEFAULT_VALUE']['UNIQUE'] ?? 'N') === 'Y';
	}

	/**
	 * Same condition the domain uses, unlike the V1 controller which also demanded TRANSLITERATION.
	 * Call under self::withUniqueCodeGuard(): the lookup means nothing outside the lock.
	 *
	 * @param array<string, mixed> $fields
	 */
	private function ensureUniqueCode(int $iblockId, array $fields, ?int $excludeId): Result
	{
		$code = (string)$fields['CODE'];

		$filter = [
			'=IBLOCK_ID' => $iblockId,
			'=CODE' => $code,
		];
		if ($excludeId !== null)
		{
			$filter['!=ID'] = $excludeId;
		}

		$row = SectionTable::getRow([
			'select' => ['ID'],
			'filter' => $filter,
		]);

		if ($row === null)
		{
			return ServiceResult::success();
		}

		// the conflicting section is looked up without row level rights: naming its id would
		// let a caller who cannot read it discover the id by guessing the code
		$existingId = (int)$row['ID'];
		$readAccess = $this->accessSupport->ensureIblockSectionGet($existingId, $iblockId);
		if (!$readAccess->isSuccess())
		{
			return ServiceResult::validationFailed('Duplicate entry for key [code]');
		}

		return ServiceResult::alreadyExists($existingId, 'Duplicate entry for key [code]');
	}

	/**
	 * Sparse write: non initialized DTO properties must not reach the domain array,
	 * \CIBlockSection::Update takes only isset() keys.
	 *
	 * @param list<string> $skipProperties
	 * @return array<string, mixed>
	 */
	private function dtoToDomainFields(SectionDto $dto, array $skipProperties): array
	{
		$fields = [];
		foreach (self::DOMAIN_FIELDS as $property => $column)
		{
			if (in_array($property, $skipProperties, true))
			{
				continue;
			}

			$reflection = new ReflectionProperty($dto, $property);
			if (!$reflection->isInitialized($dto))
			{
				continue;
			}

			$fields[$column] = $dto->{$property};
		}

		return $fields;
	}

	/**
	 * The mapper cuts the response down to the client select but loads nothing:
	 * an unselected column would come back as 0 / "".
	 * Without a client select the projection is the response contract: an empty arSelect
	 * would make the domain read `BS.*` plus the iblock columns.
	 *
	 * @return list<string>
	 */
	private function selectToDomain(?SelectStructure $select): array
	{
		$fields = $select?->getList() ?? [];
		if ($fields === [])
		{
			return array_values(self::DOMAIN_FIELDS);
		}

		$result = [];
		foreach ($fields as $field)
		{
			$column = self::DOMAIN_FIELDS[$field] ?? null;
			if ($column !== null)
			{
				$result[$column] = true;
			}
		}

		return array_keys($result);
	}

	/**
	 * @return array<string, mixed>|Result
	 */
	private function filterToDomain(?FilterStructure $filter): array|Result
	{
		if ($filter === null)
		{
			return [];
		}

		$result = [];
		$error = $this->appendFilterConditions($filter, $result);
		if ($error !== null)
		{
			return ServiceResult::validation([$error]);
		}

		return $result;
	}

	/**
	 * Conditions collapsing into the same domain key would silently overwrite each other,
	 * so a collision is reported instead of a last-wins filter.
	 *
	 * @param array<string, mixed> $result
	 */
	private function appendFilterConditions(FilterStructure $filter, array &$result): ?Error
	{
		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				$error = $this->appendFilterConditions($condition, $result);
				if ($error !== null)
				{
					return $error;
				}

				continue;
			}

			if (!$condition instanceof Condition)
			{
				continue;
			}

			$property = (string)$condition->getLeftOperand();
			$column = self::FILTER_FIELD_OVERRIDES[$property] ?? self::DOMAIN_FIELDS[$property] ?? null;
			if ($column === null)
			{
				continue;
			}

			$operator = $condition->getOperator();
			$value = $condition->getRightOperand();
			if ($property === 'iblockSectionId')
			{
				[$operator, $value] = $this->rewriteRootParentCondition($operator, $value);
			}

			$key = $this->operatorPrefix($operator, $property) . $column;
			if (array_key_exists($key, $result))
			{
				return new Error(
					sprintf('Filter has conflicting conditions for the field "%s"', $property),
				);
			}

			$result[$key] = $this->valueToDomain($value);
		}

		return null;
	}

	/**
	 * \CIBlockSection::GetFilter knows only the prefixes of \CIBlock::MkOperationFilter:
	 * there is no `@`, an array value under `=` already becomes an IN list.
	 *
	 * @see \CIBlock::FilterCreateEx
	 */
	private function operatorPrefix(Operator $operator, string $property): string
	{
		return match ($operator)
		{
			Operator::Equal, Operator::In => '=',
			Operator::NotEqual => in_array($property, self::STRING_EQUAL_FILTER_PROPERTIES, true) ? '!' : '!=',
			Operator::Greater => '>',
			Operator::GreaterOrEqual => '>=',
			Operator::Less => '<',
			Operator::LessOrEqual => '<=',
			Operator::Between => '><',
		};
	}

	/**
	 * \CIBlock::FilterCreateEx drops an empty string value and \CIBlockSection::GetFilter leaves
	 * its `bSkipEmpty` at the default, so such a condition would disappear from the query.
	 * `false` is the only value that guard lets through as empty.
	 *
	 * @see \CIBlock::FilterCreateEx
	 */
	private function valueToDomain(mixed $value): mixed
	{
		if (is_array($value))
		{
			return array_map(
				static fn (mixed $item): mixed => $item === '' ? false : $item,
				$value,
			);
		}

		return $value === '' ? false : $value;
	}

	/**
	 * The parent is filterable through SECTION_ID alone, and \CIBlockSection::GetFilter answers
	 * "no parent" for a non positive scalar: it empties the value and the number branch then
	 * builds `IS NULL` / `IS NOT NULL` from the operation, ignoring an array or a comparison.
	 * The root conditions kept by self::findUnsupportedIblockSectionIdCondition() are rewritten
	 * onto that scalar pair; everything else is already expressible.
	 *
	 * @return array{0: Operator, 1: mixed}
	 */
	private function rewriteRootParentCondition(Operator $operator, mixed $value): array
	{
		if ($operator === Operator::In && is_array($value) && count($value) === 1 && (int)reset($value) === 0)
		{
			return [Operator::Equal, 0];
		}

		if ($operator === Operator::Greater && is_scalar($value) && (int)$value === 0)
		{
			return [Operator::NotEqual, 0];
		}

		return [$operator, $value];
	}

	/**
	 * @return array<string, string> UPPER => ASC|DESC
	 */
	private function orderToDomain(?OrderStructure $order): array
	{
		$items = $order?->getItems() ?? [];
		if ($items === [])
		{
			return ['ID' => 'ASC'];
		}

		$result = [];
		foreach ($items as $item)
		{
			$column = self::DOMAIN_FIELDS[$item->getProperty()] ?? null;
			if ($column !== null)
			{
				$result[$column] = $item->getOrder()->value;
			}
		}

		return $result === [] ? ['ID' => 'ASC'] : $result;
	}

	/**
	 * The rights gate reads a single iblock, and the domain filter collapses `=` and `in`
	 * into the same `=IBLOCK_ID` key: a list of iblocks would leave the gate and the query
	 * out of sync, so the required condition is limited to equality with one iblock.
	 *
	 * Operand expressions are already rejected by FilterStructureSupport, so the left operand
	 * here is always a property name.
	 */
	private function findUnsupportedIblockIdCondition(?FilterStructure $filter): ?Error
	{
		if ($filter === null)
		{
			return null;
		}

		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				$error = $this->findUnsupportedIblockIdCondition($condition);
				if ($error !== null)
				{
					return $error;
				}

				continue;
			}

			if (!$condition instanceof Condition || (string)$condition->getLeftOperand() !== 'iblockId')
			{
				continue;
			}

			if ($condition->getOperator() !== Operator::Equal || !is_scalar($condition->getRightOperand()))
			{
				return new Error(
					'Filter condition for "iblockId" supports only equality with a single iblock',
					'filter.iblockId',
				);
			}
		}

		return null;
	}

	/**
	 * SECTION_ID is the only parent key of the domain filter and it expresses "no parent" through
	 * a single non positive value: \CIBlockSection::GetFilter empties such a value, and the number
	 * branch of the filter builder then drops the array and the comparison operator. Conditions
	 * that the key cannot carry are rejected instead of answering with the wrong sections.
	 *
	 * @see \CIBlockSection::GetFilter
	 */
	private function findUnsupportedIblockSectionIdCondition(?FilterStructure $filter): ?Error
	{
		if ($filter === null)
		{
			return null;
		}

		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				$error = $this->findUnsupportedIblockSectionIdCondition($condition);
				if ($error !== null)
				{
					return $error;
				}

				continue;
			}

			if (!$condition instanceof Condition || (string)$condition->getLeftOperand() !== 'iblockSectionId')
			{
				continue;
			}

			if (in_array($condition->getOperator(), [Operator::Less, Operator::LessOrEqual], true))
			{
				return new Error(
					'Filter condition for "iblockSectionId" does not support less than comparisons',
					'filter.iblockSectionId',
				);
			}

			if (!$this->isSupportedParentCondition($condition->getOperator(), $condition->getRightOperand()))
			{
				return new Error(
					'Filter condition for "iblockSectionId" supports the root value 0 only'
					. ' with "=", "!=", ">" or a single value "in"',
					'filter.iblockSectionId',
				);
			}
		}

		return null;
	}

	/**
	 * Positive ids are plain SECTION_ID comparisons; a non positive one means the root and fits
	 * a single value condition only.
	 *
	 * "<" and "<=" are rejected before this check: a root is stored as NULL, which a SQL
	 * comparison never matches, although the response shows it as 0 below any positive bound.
	 */
	private function isSupportedParentCondition(Operator $operator, mixed $value): bool
	{
		$values = is_array($value) ? $value : [$value];
		$nonPositive = array_filter(
			$values,
			static fn (mixed $item): bool => !is_scalar($item) || (int)$item <= 0,
		);
		if ($nonPositive === [])
		{
			return true;
		}

		if (count($values) !== 1 || (int)reset($values) !== 0)
		{
			return false;
		}

		return in_array(
			$operator,
			[Operator::Equal, Operator::NotEqual, Operator::Greater, Operator::In],
			true,
		);
	}

	/**
	 * \CIBlock::FilterCreateEx builds an empty predicate for an empty value list and the domain
	 * drops it from the WHERE clause, so the condition would answer with the whole iblock.
	 */
	private function hasEmptyInCondition(?FilterStructure $filter): bool
	{
		if ($filter === null)
		{
			return false;
		}

		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				if ($this->hasEmptyInCondition($condition))
				{
					return true;
				}

				continue;
			}

			if (
				$condition instanceof Condition
				&& $condition->getOperator() === Operator::In
				&& $condition->getRightOperand() === []
			)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $domainFilter
	 * @throws RequiredFieldsInRequestFilterPropertyException
	 */
	private function requireFilterIblockId(array $domainFilter): int
	{
		$value = $domainFilter['=IBLOCK_ID'] ?? null;
		$iblockId = is_scalar($value) ? (int)$value : 0;
		if ($iblockId <= 0)
		{
			throw new RequiredFieldsInRequestFilterPropertyException('filter', ['iblockId']);
		}

		return $iblockId;
	}

	/**
	 * \CIBlockSection pagination is page based; reject offsets that are not page aligned.
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
