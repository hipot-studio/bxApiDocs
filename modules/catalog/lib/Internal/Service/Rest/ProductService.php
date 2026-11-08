<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\Access\ActionDictionary;
use Bitrix\Catalog\Config\State;
use Bitrix\Catalog\Infrastructure\Rest\Dto\ProductDto;
use Bitrix\Catalog\Infrastructure\Rest\Dto\ProductPropertyDto;
use Bitrix\Catalog\Infrastructure\Rest\Support\ApplicationErrors;
use Bitrix\Catalog\Infrastructure\Rest\Support\FilterStructureSupport;
use Bitrix\Catalog\Infrastructure\Rest\Support\ProductFieldsMapper;
use Bitrix\Catalog\Infrastructure\Rest\Support\SkuDeferredCalculations;
use Bitrix\Catalog\Internal\Service\RestValidator\Entity\ProductFilterValidator;
use Bitrix\Catalog\Internal\Service\RestValidator\Entity\ProductValidator;
use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\RestView;
use Bitrix\Catalog\VatTable;
use Bitrix\Catalog\v2\Internal\ProductInternalService;
use Bitrix\Iblock;
use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\Text\StringHelper;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;
use CCatalogSku;
use CFile;
use CIBlock;
use CIBlockElement;
use CIBlockElementRights;
use CIBlockProperty;
use CIBlockRights;
use CIBlockSection;
use CIBlockSectionRights;
use CUser;
use CCurrency;
use IRestService;

final class ProductService
{
	use ApplicationErrors;

	private const TYPE = ProductTable::TYPE_PRODUCT;

	/**
	 * Operator prefixes a TYPE filter condition may carry: equality, negation and "in".
	 */
	private const TYPE_FILTER_OPERATORS = ['', '=', '!', '!=', '@'];

	private const IBLOCK_READ = 'iblock_admin_display';
	private const IBLOCK_ELEMENT_READ = 'element_read';
	private const IBLOCK_ELEMENT_EDIT = 'element_edit';
	private const IBLOCK_ELEMENT_DELETE = 'element_delete';
	private const IBLOCK_ELEMENT_SECTION_BIND = 'section_element_bind';
	private const IBLOCK_EDIT = 'iblock_edit';

	public function __construct(
		private readonly AccessSupport $accessSupport,
	)
	{
	}

	public function get(int $id, ?SelectStructure $select = null, ?ProductTypeScope $typeScope = null): Result
	{
		$access = $this->accessSupport->ensureProductEntityRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$elementResult = $this->ensureExistingElement($id);
		if (!$elementResult->isSuccess())
		{
			return $elementResult;
		}
		$element = $elementResult->getData()['element'];

		$check = $this->ensureIblockElementGet($element['IBLOCK_ID'], $element['ID']);
		if (!$check->isSuccess())
		{
			return $check;
		}

		$row = $this->loadProductRow($id, $select);
		if ($row === null || !$this->isTypeInScope($row['TYPE'] ?? null, $typeScope))
		{
			return ServiceResult::notFound($id);
		}

		return ServiceResult::success(['item' => $row]);
	}

	public function list(
		?SelectStructure $select,
		?FilterStructure $filter,
		?OrderStructure $order,
		?PaginationStructure $pagination,
		?ProductTypeScope $typeScope = null,
	): Result {
		$access = $this->accessSupport->ensureProductEntityRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$unsupportedFilter = FilterStructureSupport::findUnsupportedSemantics($filter);
		if ($unsupportedFilter !== null)
		{
			return ServiceResult::validation([$unsupportedFilter]);
		}

		$camelFilter = ProductFieldsMapper::filterToCamelArray($filter);
		if ($camelFilter === [] && $filter !== null && $filter->getConditions() !== [])
		{
			return ServiceResult::validation([new Error('Incorrect filter format')]);
		}

		$iblockId = (int)($camelFilter['iblockId'] ?? $camelFilter['=iblockId'] ?? 0);
		$validator = new ProductFilterValidator($iblockId);
		$validation = $validator->run($this->stripOperatorPrefixes($camelFilter));
		if (!$validation->isSuccess())
		{
			return ServiceResult::validation($validation->getErrors());
		}

		$domainFilter = ProductFieldsMapper::filterToDomainArray($filter);
		$domainIblockId = (int)($domainFilter['IBLOCK_ID'] ?? $domainFilter['=IBLOCK_ID'] ?? $iblockId);
		$check = $this->ensureIblockElementList($domainIblockId);
		if (!$check->isSuccess())
		{
			return $check;
		}

		if ($typeScope !== null)
		{
			$check = $this->ensureTypeAllowedByIblock($typeScope->getWriteType(), $domainIblockId);
			if (!$check->isSuccess())
			{
				return $check;
			}

			$scoped = $this->applyTypeScopeToFilter($domainFilter, $typeScope);
			if (!$scoped->isSuccess())
			{
				return $scoped;
			}

			$scopedFilter = $scoped->getData()['filter'] ?? null;
			if ($scopedFilter === null)
			{
				return ServiceResult::success(['items' => []]);
			}

			$domainFilter = $scopedFilter;
		}

		$domainSelect = ProductFieldsMapper::selectToDomainList($select);
		if ($domainSelect === [])
		{
			$domainSelect = array_merge(['*'], $this->getAllowedFieldsProduct());
		}
		$domainOrder = ProductFieldsMapper::orderToDomainArray($order);

		$groupFields = $this->splitFieldsByEntity(array_flip($domainSelect));
		$allProperties = isset($groupFields['elementFields']['PROPERTY_*']);
		if ($allProperties)
		{
			unset($groupFields['elementFields']['PROPERTY_*']);
		}

		$productFields = array_keys($groupFields['productFields']);
		$elementFields = array_keys($groupFields['elementFields']);
		$propertyFields = $this->preparePropertyFields($groupFields['propertyFields']);
		$propertyIds = array_keys($propertyFields);

		$domainFilter = $this->normalizePropertyFilter($domainFilter);
		$nav = $this->buildNavData($pagination);
		if ($nav instanceof Result)
		{
			return $nav;
		}

		// the filter is one conjunction - FilterStructureSupport rejects OR and NOT - so an empty
		// IN list leaves it unsatisfiable; this shortcut has to go when OR groups get supported.
		// CIBlockElement drops an empty IN list from the query and would answer with the whole iblock.
		// Unlike the catalog and section lists, the shortcut follows pagination, keeping its error.
		if (FilterStructureSupport::hasEmptyInCondition($filter))
		{
			return ServiceResult::success(['items' => []]);
		}

		$items = $this->perfGetList(
			array_merge($productFields, $elementFields),
			$domainFilter,
			$domainOrder,
			$nav,
		);
		$list = $items['ROWS'];

		if ($list === [])
		{
			return ServiceResult::success(['items' => []]);
		}

		if ($allProperties || $propertyIds !== [])
		{
			$this->attachPropertyValues($list, $domainIblockId, $propertyIds);
		}

		return ServiceResult::success(['items' => array_values($list)]);
	}

	/**
	 * @param ProductOfferParentResolver|null $parentResolver resolves the parent product of the
	 *        record and its type. Only the offer resource needs it. It runs after the rights are
	 *        checked: no domain record may be read before the caller is allowed to write here.
	 */
	public function add(
		ProductDto $dto,
		?ProductTypeScope $typeScope = null,
		?ProductOfferParentResolver $parentResolver = null,
	): Result
	{
		$access = $this->accessSupport->ensureProductEntityRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$check = $this->ensurePropertyKeys($dto);
		if (!$check->isSuccess())
		{
			return $check;
		}

		$camelFields = ProductFieldsMapper::dtoToCamelFields($dto);
		$iblockId = (int)($camelFields['iblockId'] ?? 0);

		$validator = new ProductValidator($iblockId);
		$validation = $validator->run($camelFields);
		if (!$validation->isSuccess())
		{
			return ServiceResult::validation($validation->getErrors());
		}

		$check = $this->ensurePermissionAdd($iblockId);
		if (!$check->isSuccess())
		{
			return $check;
		}

		$recordType = null;
		if ($parentResolver !== null)
		{
			$parent = $parentResolver->resolveForAdd($dto);
			if (!$parent->isSuccess())
			{
				return $parent;
			}

			$dto->properties = $parent->getData()['properties'];
			$recordType = $parent->getData()['type'];
		}

		$fields = ProductFieldsMapper::dtoToDomainFields($dto);
		if ((int)($fields['IBLOCK_ID'] ?? 0) !== $iblockId)
		{
			return ServiceResult::validation([
				new Error('The iblock of the product does not match the iblock the rights were checked for'),
			]);
		}
		$fields['TYPE'] = $recordType ?? $typeScope?->getWriteType() ?? $fields['TYPE'] ?? self::TYPE;

		if ($typeScope !== null)
		{
			$check = $this->ensureTypeAllowedByIblock((int)$fields['TYPE'], (int)$fields['IBLOCK_ID']);
			if (!$check->isSuccess())
			{
				return $check;
			}
		}

		$sectionId = (int)($fields['IBLOCK_SECTION_ID'] ?? 0);
		if ($sectionId > 0)
		{
			$check = $this->ensureSectionBind((int)$fields['IBLOCK_ID'], $sectionId);
			if (!$check->isSuccess())
			{
				return $check;
			}
		}

		$check = $this->ensureAddValidate($fields);
		if (!$check->isSuccess())
		{
			return $check;
		}

		$groupFields = $this->splitFieldsByEntity($fields);
		$productFields = $this->prepareProductFields($groupFields['productFields']);
		$propertyFields = $this->preparePropertyFields($groupFields['propertyFields']);
		$elementFields = $groupFields['elementFields'];
		$elementFieldsAdd = $propertyFields !== []
			? array_merge($elementFields, ['PROPERTY_VALUES' => $propertyFields])
			: $elementFields;

		$id = 0;
		SkuDeferredCalculations::enable();
		try
		{
			$productService = new ProductInternalService(true);
			$conn = Application::getConnection();
			$conn->startTransaction();
			try
			{
				$result = $productService->add(array_merge($productFields, $elementFieldsAdd));
			}
			catch (SqlQueryException)
			{
				$result = new Result();
				$result->addError(new Error('Internal error adding product. Try adding again.'));
			}

			if (!$result->isSuccess())
			{
				$conn->rollbackTransaction();

				return ServiceResult::validation($result->getErrors());
			}

			$conn->commitTransaction();
			$id = (int)$result->getData()['ID'];
		}
		finally
		{
			SkuDeferredCalculations::disableAndSchedule();
		}

		return $this->get($id, null, $typeScope);
	}

	/**
	 * The stored type is never rewritten here: for a variation parent it is a computed value,
	 * and the membership of the record in the resource is already checked before the write.
	 */
	public function update(int $id, ProductDto $dto, ?ProductTypeScope $typeScope = null): Result
	{
		$access = $this->accessSupport->ensureProductEntityRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$check = $this->ensurePropertyKeys($dto);
		if (!$check->isSuccess())
		{
			return $check;
		}

		$elementResult = $this->ensureExistingElement($id);
		if (!$elementResult->isSuccess())
		{
			return $elementResult;
		}
		$element = $elementResult->getData()['element'];

		$camelFields = ProductFieldsMapper::dtoToCamelFields($dto);
		$iblockIdOrigin = isset($camelFields['iblockId']) ? (int)$camelFields['iblockId'] : null;
		if ($iblockIdOrigin !== null && $iblockIdOrigin !== $element['IBLOCK_ID'])
		{
			return ServiceResult::validation([
				new Error(sprintf(
					'Product - %d is not exists in catalog - %d',
					$id,
					$iblockIdOrigin,
				)),
			]);
		}

		if (!isset($camelFields['iblockId']))
		{
			$camelFields['iblockId'] = $element['IBLOCK_ID'];
		}

		$validator = new ProductValidator($element['IBLOCK_ID']);
		$validation = $validator->run($camelFields);
		if (!$validation->isSuccess())
		{
			return ServiceResult::validation($validation->getErrors());
		}

		$fields = ProductFieldsMapper::dtoToDomainFields($dto);
		$fields['IBLOCK_ID'] ??= $element['IBLOCK_ID'];

		$check = $this->ensurePermissionUpdate($element['IBLOCK_ID'], $element['ID']);
		if (!$check->isSuccess())
		{
			return $check;
		}

		$check = $this->ensureRecordTypeInScope($id, $typeScope);
		if (!$check->isSuccess())
		{
			return $check;
		}

		if ($typeScope !== null)
		{
			$check = $this->ensureTypeAllowedByIblock($typeScope->getWriteType(), $element['IBLOCK_ID']);
			if (!$check->isSuccess())
			{
				return $check;
			}
		}

		$sectionId = (int)($fields['IBLOCK_SECTION_ID'] ?? 0);
		if ($sectionId > 0)
		{
			$check = $this->ensureSectionBind($element['IBLOCK_ID'], $sectionId);
			if (!$check->isSuccess())
			{
				return $check;
			}
		}

		$groupFields = $this->splitFieldsByEntity($fields);
		$productFields = $this->prepareProductFields($groupFields['productFields']);
		$propertyFields = $this->preparePropertyFields($groupFields['propertyFields']);
		$elementFields = $groupFields['elementFields'];

		$propertyFields = $this->fillPropertyFieldsDefaultPropertyValues($id, (int)$fields['IBLOCK_ID'], $propertyFields);
		$propertyFields = $this->preparePropertyFieldsUpdate($propertyFields);

		$elementFieldsUpdate = $propertyFields !== []
			? array_merge($elementFields, ['PROPERTY_VALUES' => $propertyFields])
			: $elementFields;

		if ($elementFieldsUpdate !== [])
		{
			$check = $this->ensureUpdateValidate($elementFieldsUpdate + ['ID' => $id]);
			if (!$check->isSuccess())
			{
				return $check;
			}
		}

		if ($productFields !== [] || $elementFieldsUpdate !== [])
		{
			SkuDeferredCalculations::enable();
			try
			{
				$productService = new ProductInternalService(true);
				$conn = Application::getConnection();
				$conn->startTransaction();
				try
				{
					$result = $productService->update(
						$id,
						array_merge($productFields, $elementFieldsUpdate),
					);
				}
				catch (SqlQueryException)
				{
					$result = new Result();
					$result->addError(new Error('Internal error updating product. Try updating again.'));
				}

				if (!$result->isSuccess())
				{
					$conn->rollbackTransaction();

					return ServiceResult::validation($result->getErrors());
				}

				$conn->commitTransaction();
			}
			finally
			{
				SkuDeferredCalculations::disableAndSchedule();
			}
		}

		return $this->get($id, null, $typeScope);
	}

	public function delete(int $id, ?ProductTypeScope $typeScope = null): Result
	{
		$access = $this->accessSupport->ensureProductEntityRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$elementResult = $this->ensureExistingElement($id);
		if (!$elementResult->isSuccess())
		{
			return $elementResult;
		}
		$element = $elementResult->getData()['element'];

		$check = $this->ensurePermissionDelete($element['IBLOCK_ID'], $element['ID']);
		if (!$check->isSuccess())
		{
			return $check;
		}

		$check = $this->ensureRecordTypeInScope($id, $typeScope);
		if (!$check->isSuccess())
		{
			return $check;
		}

		SkuDeferredCalculations::enable();
		try
		{
			$result = new Result();
			$conn = Application::getConnection();
			$conn->startTransaction();
			try
			{
				if (!CIBlockElement::Delete($id))
				{
					$error = $this->getLastApplicationError();
					if ($error !== null)
					{
						$result->addError($error);
					}
					else
					{
						$result->addError(new Error('delete iBlockElement error'));
					}
				}
			}
			catch (SqlQueryException)
			{
				$result = new Result();
				$result->addError(new Error('Internal error deleting product. Try deleting again.'));
			}

			if (!$result->isSuccess())
			{
				$conn->rollbackTransaction();

				return ServiceResult::validation($result->getErrors());
			}

			$conn->commitTransaction();
		}
		finally
		{
			SkuDeferredCalculations::disableAndSchedule();
		}

		return ServiceResult::success(['result' => true]);
	}

	public function download(array $fields, ?ProductTypeScope $typeScope = null): Result
	{
		$access = $this->accessSupport->ensureProductEntityRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$normalized = $this->normalizeIncomingFilter($fields);
		$productId = (int)($normalized['PRODUCT_ID'] ?? 0);
		$fieldName = (string)($normalized['FIELD_NAME'] ?? '');
		$fileId = (int)($normalized['FILE_ID'] ?? 0);

		$elementResult = $this->ensureExistingElement($productId);
		if (!$elementResult->isSuccess())
		{
			return $elementResult;
		}
		$element = $elementResult->getData()['element'];

		$check = $this->ensureIblockElementGet($element['IBLOCK_ID'], $element['ID']);
		if (!$check->isSuccess())
		{
			return $check;
		}

		$check = $this->ensureRecordTypeInScope($productId, $typeScope);
		if (!$check->isSuccess())
		{
			return $check;
		}

		if (!$this->checkFieldsDownload([
			'NAME' => $fieldName,
			'IBLOCK_ID' => (int)$element['IBLOCK_ID'],
		]))
		{
			return ServiceResult::validation([new Error('Name file field is not available')]);
		}

		$files = [];
		$iterator = CIBlockElement::GetList(
			[],
			[
				'IBLOCK_ID' => $element['IBLOCK_ID'],
				'=ID' => $element['ID'],
				'CHECK_PERMISSIONS' => 'N',
			],
			false,
			false,
			['ID', 'IBLOCK_ID', $fieldName],
		);
		while ($res = $iterator->GetNext())
		{
			if (isset($res[$fieldName]))
			{
				$files[] = (int)$res[$fieldName];
			}
			elseif (isset($res[$fieldName . '_VALUE']))
			{
				if (is_array($res[$fieldName . '_VALUE']))
				{
					$list = $res[$fieldName . '_VALUE'];
					\Bitrix\Main\Type\Collection::normalizeArrayValuesByInt($list);
					if ($list !== [])
					{
						$files = array_merge($files, $list);
					}
				}
				else
				{
					$files[] = (int)$res[$fieldName . '_VALUE'];
				}
			}
		}

		if (!in_array($fileId, $files, true))
		{
			return ServiceResult::validation([new Error('Product file wrong')]);
		}

		$file = CFile::GetFileArray($fileId);
		if (!is_array($file))
		{
			return ServiceResult::validation([new Error('Product is empty')]);
		}

		return ServiceResult::success([
			'file' => [
				'id' => (int)$file['ID'],
				'fileName' => $file['FILE_NAME'] ?? null,
				'contentType' => $file['CONTENT_TYPE'] ?? null,
				'src' => $file['SRC'] ?? null,
			],
		]);
	}

	public function addProperty(ProductPropertyDto $dto): Result
	{
		$iblockId = $dto->iblockId ?? 0;
		$check = $this->ensureIblockModify($iblockId);
		if (!$check->isSuccess())
		{
			return $check;
		}

		$iblockProperty = new CIBlockProperty();
		$propertyFields = [
			'ACTIVE' => 'Y',
			'IBLOCK_ID' => $iblockId,
			'NAME' => $dto->name ?? '',
			'SORT' => $dto->sort ?? 100,
			'CODE' => $dto->code ?? '',
			'MULTIPLE' => (($dto->multiple ?? 'N') === 'Y') ? 'Y' : 'N',
			'IS_REQUIRED' => (($dto->isRequired ?? 'N') === 'Y') ? 'Y' : 'N',
			'SECTION_PROPERTY' => 'N',
		];

		$newId = (int)$iblockProperty->Add($propertyFields);
		if ($newId === 0)
		{
			return ServiceResult::validation([
				new Error($iblockProperty->getLastError()),
			]);
		}

		return ServiceResult::success([
			'item' => [
				'id' => $newId,
				'controlId' => 'PROPERTY_' . $newId,
			],
		]);
	}

	private function isTypeInScope(mixed $type, ?ProductTypeScope $typeScope): bool
	{
		return $typeScope === null || $typeScope->isAllowedType((int)$type);
	}

	/**
	 * A record outside the resource type scope does not exist for that resource:
	 * the type is not a right, so the answer is "not found", not "access denied".
	 * Callers check the rights on the element first: the type of a record the caller
	 * may not touch is never disclosed.
	 */
	private function ensureRecordTypeInScope(int $id, ?ProductTypeScope $typeScope): Result
	{
		if ($typeScope === null)
		{
			return ServiceResult::success();
		}

		$row = ProductTable::getRow([
			'select' => ['TYPE'],
			'filter' => ['=ID' => $id],
		]);

		if ($row === null || !$typeScope->isAllowedType((int)$row['TYPE']))
		{
			return ServiceResult::notFound($id);
		}

		return ServiceResult::success();
	}

	private function ensureTypeAllowedByIblock(int $productType, int $iblockId): Result
	{
		$check = (new RestView\Product())->isAllowedProductTypeByIBlockId(
			$productType,
			$iblockId,
		);
		if (!$check->isSuccess())
		{
			return ServiceResult::validation($check->getErrors());
		}

		return ServiceResult::success();
	}

	/**
	 * Every client type predicate is intersected with the resource scope instead of being dropped
	 * with its operator: V1 keeps the client condition next to the server one, so "!X" means
	 * "not X, within the scope".
	 *
	 * An empty intersection is reported back as a null filter and must answer with an empty list.
	 * It may never reach the query: an empty TYPE value is not a condition for
	 * {@see CIBlockElement::GetList} and would drop the type scope of the resource entirely.
	 *
	 * @param array<string, mixed> $filter
	 * @return Result data: ['filter' => array<string, mixed>|null]
	 */
	private function applyTypeScopeToFilter(array $filter, ProductTypeScope $typeScope): Result
	{
		$typeConditions = [];
		$result = [];
		foreach ($filter as $key => $value)
		{
			[$operator, $field] = $this->splitFilterKey((string)$key);
			if ($field === 'TYPE')
			{
				$typeConditions[] = [$operator, $value];

				continue;
			}

			$result[$key] = $value;
		}

		$scopedTypes = $this->intersectTypesWithScope($typeConditions, $typeScope);
		if (!$scopedTypes->isSuccess())
		{
			return $scopedTypes;
		}

		$types = $scopedTypes->getData()['types'];
		if ($types === [])
		{
			return ServiceResult::success(['filter' => null]);
		}

		$result['TYPE'] = count($types) === 1 ? $types[0] : $types;

		return ServiceResult::success(['filter' => $result]);
	}

	/**
	 * Types the resource may select: every client predicate applied in turn to the scope list.
	 * The operators are checked before any value is applied, so an unsupported one is refused
	 * wherever it stands in the filter.
	 *
	 * @param list<array{0: string, 1: mixed}> $conditions operator and value of each TYPE predicate
	 * @return Result data: ['types' => list<int>], an empty list meaning nothing to select
	 */
	private function intersectTypesWithScope(array $conditions, ProductTypeScope $typeScope): Result
	{
		foreach ($conditions as [$operator])
		{
			if (!in_array($operator, self::TYPE_FILTER_OPERATORS, true))
			{
				return ServiceResult::validation([
					new Error('The type field supports only the =, != and in filter operators'),
				]);
			}
		}

		$types = $typeScope->getAllowedTypes();
		foreach ($conditions as [$operator, $value])
		{
			if ($types === [])
			{
				break;
			}

			$types = $this->narrowTypesByCondition($types, $operator, $value, $typeScope);
		}

		return ServiceResult::success(['types' => $types]);
	}

	/**
	 * A type outside the scope narrows nothing on equality - that is the V1 outcome, where the
	 * server condition simply wins over the client one. Membership is therefore decided by the
	 * scope, while the narrowing itself applies to the types left by the previous conditions:
	 * predicates of one filter combine as a conjunction, whatever their order.
	 *
	 * @param list<int> $types
	 * @return list<int>
	 */
	private function narrowTypesByCondition(
		array $types,
		string $operator,
		mixed $value,
		ProductTypeScope $typeScope,
	): array
	{
		$clientTypes = $this->normalizeTypeValues($value);

		if ($operator === '!' || $operator === '!=')
		{
			return $clientTypes === []
				? $types
				: array_values(array_diff($types, $clientTypes))
			;
		}

		if ($operator === '@')
		{
			return array_values(array_intersect($types, $clientTypes));
		}

		return count($clientTypes) === 1 && $typeScope->isAllowedType($clientTypes[0])
			? array_values(array_intersect($types, $clientTypes))
			: $types
		;
	}

	/**
	 * @return list<int>
	 */
	private function normalizeTypeValues(mixed $value): array
	{
		$values = is_array($value) ? $value : [$value];
		$result = [];
		foreach ($values as $item)
		{
			if (is_numeric($item))
			{
				$result[] = (int)$item;
			}
		}

		return array_values(array_unique($result));
	}

	/**
	 * Operator prefix {@see ProductFieldsMapper} put in front of a domain filter key,
	 * and the field name itself.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function splitFilterKey(string $key): array
	{
		if (preg_match('/^(!=|><|>=|<=|[=!><@])/', $key, $matches) === 1)
		{
			return [$matches[1], substr($key, strlen($matches[1]))];
		}

		return ['', $key];
	}

	private function ensureExistingElement(int $id): Result
	{
		$row = null;
		if ($id > 0)
		{
			$row = ElementTable::getRow([
				'select' => ['ID', 'IBLOCK_ID'],
				'filter' => ['=ID' => $id],
			]);
		}

		if ($row === null || CCatalogSku::GetInfoByIBlock((int)$row['IBLOCK_ID']) === false)
		{
			return ServiceResult::notFound($id);
		}

		return ServiceResult::success([
			'element' => [
				'ID' => (int)$row['ID'],
				'IBLOCK_ID' => (int)$row['IBLOCK_ID'],
			],
		]);
	}

	private function loadProductRow(int $id, ?SelectStructure $select = null): ?array
	{
		$row = CIBlockElement::GetList(
			[],
			['ID' => $id],
			false,
			false,
			array_merge(['*'], $this->getAllowedFieldsProduct()),
		)->Fetch();

		if (!$row)
		{
			return null;
		}

		$result = [$row['ID'] => $row];
		$selectFields = $select?->getList() ?? [];
		$loadAllRelated = $selectFields === [];
		$needSections = $loadAllRelated
			|| in_array('iblockSection', $selectFields, true)
			|| in_array('iblockSectionId', $selectFields, true);
		$needProperties = $loadAllRelated
			|| in_array('properties', $selectFields, true)
			|| (bool)array_filter(
				$selectFields,
				static fn(mixed $field): bool => is_string($field) && str_starts_with($field, 'PROPERTY_'),
			);

		if ($needSections)
		{
			$this->attachIblockSections($result);
		}
		if ($needProperties)
		{
			$this->attachPropertyValues($result, (int)$row['IBLOCK_ID']);
		}

		return $result[$row['ID']];
	}

	/**
	 * @return list<string>
	 */
	private function getAllowedFieldsProduct(): array
	{
		$result = [
			'TYPE',
			'AVAILABLE',
			'BUNDLE',
			'QUANTITY',
			'QUANTITY_RESERVED',
			'QUANTITY_TRACE',
			'CAN_BUY_ZERO',
			'SUBSCRIBE',
			'VAT_ID',
			'VAT_INCLUDED',
			'BARCODE_MULTI',
			'WEIGHT',
			'LENGTH',
			'WIDTH',
			'HEIGHT',
			'MEASURE',
			'RECUR_SCHEME_LENGTH',
			'RECUR_SCHEME_TYPE',
			'TRIAL_PRICE_ID',
			'WITHOUT_ORDER',
			'QUANTITY_TRACE_RAW',
			'PAYMENT_TYPE',
			'SUBSCRIBE_RAW',
			'CAN_BUY_ZERO_RAW',
		];

		if ($this->accessSupport->getAccessController()->check(ActionDictionary::ACTION_PRODUCT_PURCHASE_INFO_VIEW))
		{
			array_push($result, 'PURCHASING_PRICE', 'PURCHASING_CURRENCY');
		}

		return $result;
	}

	/**
	 * The property set carries iblock property values only: any other key would be written as an
	 * element or product field past the DTO, including the iblock and the type of the record.
	 * Each property is named by one key: of several spellings the write keeps one value, so the value
	 * checked before the write could differ from the value stored.
	 */
	private function ensurePropertyKeys(ProductDto $dto): Result
	{
		$properties = get_object_vars($dto)['properties'] ?? null;

		$foreignKeys = ProductFieldsMapper::findForeignPropertyKeys($properties);
		if ($foreignKeys !== [])
		{
			return ServiceResult::validation([
				new Error(sprintf(
					'The properties field accepts only the property keys PROPERTY_{id}, unknown keys: %s',
					implode(', ', $foreignKeys),
				)),
			]);
		}

		$duplicateKeys = ProductFieldsMapper::findDuplicatePropertyKeys($properties);
		if ($duplicateKeys !== [])
		{
			return ServiceResult::validation([
				new Error(sprintf(
					'The properties field names one property by several keys: %s',
					implode('; ', array_map(static fn(array $keys): string => implode(', ', $keys), $duplicateKeys)),
				)),
			]);
		}

		return ServiceResult::success();
	}

	/**
	 * @param array<array-key, mixed> $fields
	 * @return array{productFields: array, propertyFields: array, elementFields: array}
	 */
	private function splitFieldsByEntity(array $fields): array
	{
		$productFields = [];
		$elementFields = [];
		$propertyFields = [];
		$allowed = $this->getAllowedFieldsProduct();

		foreach ($fields as $name => $value)
		{
			if (in_array($name, $allowed, true))
			{
				$productFields[$name] = $value;
			}
			elseif (preg_match('/^(PROPERTY_\d+)$/', (string)$name))
			{
				$propertyFields[$name] = $value;
			}
			else
			{
				$elementFields[$name] = $value;
			}
		}

		return [
			'productFields' => $productFields,
			'propertyFields' => $propertyFields,
			'elementFields' => $elementFields,
		];
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function prepareProductFields(array $fields): array
	{
		$result = $fields;
		if (State::isUsedInventoryManagement())
		{
			unset(
				$result['QUANTITY'],
				$result['QUANTITY_RESERVED'],
				$result['QUANTITY_TRACE'],
				$result['PURCHASING_PRICE'],
				$result['PURCHASING_CURRENCY'],
			);
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<int, mixed>
	 */
	private function preparePropertyFields(array $fields): array
	{
		$result = [];
		foreach ($fields as $name => $value)
		{
			if (preg_match('/^(PROPERTY_)(\d+)$/', $name, $matches))
			{
				$result[(int)$matches[2]] = $value;
			}
		}

		return $result;
	}

	/**
	 * @param array<int, mixed> $fields
	 * @return array<int, mixed>
	 */
	private function preparePropertyFieldsUpdate(array $fields): array
	{
		$result = [];
		if ($fields === [])
		{
			return $result;
		}

		foreach ($fields as $propertyId => $value)
		{
			$property = [];
			if (isset($value['VALUE']))
			{
				if (isset($value['VALUE_ID']))
				{
					$valueId = $value['VALUE_ID'];
					unset($value['VALUE_ID']);
					$property[$valueId] = $value;
				}
				else
				{
					$property[] = $value;
				}
			}
			elseif (is_array($value) && $value !== [])
			{
				foreach ($value as $item)
				{
					if (isset($item['VALUE_ID']))
					{
						$valueId = $item['VALUE_ID'];
						unset($item['VALUE_ID']);
						$property[$valueId] = $item;
					}
					else
					{
						$property[] = $item;
					}
				}
			}

			if ($property !== [])
			{
				$result[$propertyId] = $property;
			}
		}

		return $result;
	}

	/**
	 * @param array<int, mixed> $propertyValues
	 * @return array<int, mixed>
	 */
	private function fillPropertyFieldsDefaultPropertyValues(int $id, int $iblockId, array $propertyValues): array
	{
		$fields = $propertyValues;
		if ($id <= 0 || $iblockId <= 0 || $propertyValues === [])
		{
			return $fields;
		}

		$r = CIBlockElement::GetProperty(
			$iblockId,
			$id,
			'SORT',
			'ASC',
			[
				'CHECK_PERMISSIONS' => 'N',
				'EMPTY' => 'N',
			],
		);
		while ($property = $r->Fetch())
		{
			if (
				$property['PROPERTY_TYPE'] !== Iblock\PropertyTable::TYPE_FILE
				&& !array_key_exists($property['ID'], $propertyValues)
			)
			{
				$fields[$property['ID']] ??= [];
				$fields[$property['ID']][] = [
					'VALUE_ID' => $property['PROPERTY_VALUE_ID'],
					'VALUE' => $property['VALUE'],
					'DESCRIPTION' => $property['DESCRIPTION'],
				];
			}
		}

		return $fields;
	}

	/**
	 * @param array<string, mixed> $filter
	 * @return array<string, mixed>
	 */
	private function normalizePropertyFilter(array $filter): array
	{
		foreach (array_keys($filter) as $index)
		{
			$field = CIBlock::MkOperationFilter((string)$index);
			if (!preg_match('/^PROPERTY_[0-9]*$/', $field['FIELD']))
			{
				continue;
			}
			if (!is_array($filter[$index]))
			{
				continue;
			}
			if (isset($filter[$index]['VALUE']))
			{
				$filter[$index] = $filter[$index]['VALUE'];
			}
		}

		return $filter;
	}

	/**
	 * @param list<string> $select
	 * @param array<string, mixed> $filter
	 * @param array<string, string> $order
	 * @return array{ROWS: array<int|string, array>, COUNT: ?int}
	 */
	private function perfGetList(array $select, array $filter, array $order, ?array $pageNavigation): array
	{
		$count = null;
		$rawRows = [];
		$elementIds = [];

		$iterator = CIBlockElement::GetList(
			$order,
			$filter,
			false,
			$pageNavigation ?? false,
			['ID', 'IBLOCK_ID'],
		);
		while ($row = $iterator->Fetch())
		{
			$rawRows[$row['ID']] = $row;
			$elementIds[] = $row['ID'];
		}
		if ($pageNavigation)
		{
			$count = (int)$iterator->NavRecordCount;
		}

		$loadSections
			= in_array('IBLOCK_SECTION', $select, true)
			|| in_array('*', $select, true);

		foreach (array_chunk($elementIds, IRestService::LIST_LIMIT) as $pageIds)
		{
			$elementFilter = [
				'IBLOCK_ID' => $filter['IBLOCK_ID'] ?? $filter['=IBLOCK_ID'] ?? null,
				'ID' => $pageIds,
			];
			$iterator = CIBlockElement::GetList([], $elementFilter, false, false, $select);
			while ($row = $iterator->Fetch())
			{
				$rawRows[$row['ID']] += $row;
			}

			if ($loadSections)
			{
				$this->attachIblockSections($rawRows);
			}
		}

		return [
			'ROWS' => $rawRows,
			'COUNT' => $count,
		];
	}

	/**
	 * @param array<int|string, array> $result
	 */
	private function attachIblockSections(array &$result): void
	{
		if ($result === [])
		{
			return;
		}

		$listIds = array_keys($result);
		foreach ($listIds as $id)
		{
			$result[$id]['IBLOCK_SECTION'] = [];
		}

		$pageSize = defined('CATALOG_PAGE_SIZE') ? (int)CATALOG_PAGE_SIZE : 100;
		foreach (array_chunk($listIds, $pageSize) as $pageIds)
		{
			$pageFilter = Query::filter()
				->whereNull('ADDITIONAL_PROPERTY_ID')
				->whereIn('IBLOCK_ELEMENT_ID', $pageIds)
			;
			$iterator = Iblock\SectionElementTable::getList([
				'select' => ['IBLOCK_ELEMENT_ID', 'IBLOCK_SECTION_ID'],
				'filter' => $pageFilter,
			]);
			while ($row = $iterator->fetch())
			{
				$id = (int)$row['IBLOCK_ELEMENT_ID'];
				$result[$id]['IBLOCK_SECTION'][] = (int)$row['IBLOCK_SECTION_ID'];
			}
		}
	}

	/**
	 * @param array<int|string, array> $result
	 * @param list<int> $propertyIds
	 */
	private function attachPropertyValues(array &$result, int $iblockId, array $propertyIds = []): void
	{
		if ($iblockId <= 0)
		{
			return;
		}

		$propertyFilter = $propertyIds !== [] ? ['ID' => $propertyIds] : [];
		$propertyValues = [];
		CIBlockElement::getPropertyValuesArray(
			$propertyValues,
			$iblockId,
			['ID' => array_keys($result)],
			$propertyFilter,
			['USE_PROPERTY_ID' => 'Y'],
		);

		foreach ($result as $k => $v)
		{
			if (isset($propertyValues[$k]))
			{
				foreach ($propertyValues[$k] as $propId => $fields)
				{
					$value = null;
					if (isset($fields['PROPERTY_VALUE_ID']))
					{
						if ($fields['PROPERTY_TYPE'] === Iblock\PropertyTable::TYPE_LIST)
						{
							if ($fields['MULTIPLE'] === 'Y')
							{
								if (is_array($fields['PROPERTY_VALUE_ID']))
								{
									foreach ($fields['PROPERTY_VALUE_ID'] as $i => $item)
									{
										$value[] = [
											'VALUE' => $fields['VALUE_ENUM_ID'][$i],
											'VALUE_ENUM' => $fields['VALUE_ENUM'][$i],
											'VALUE_ID' => $fields['PROPERTY_VALUE_ID'][$i],
										];
									}
								}
							}
							else
							{
								$value = [
									'VALUE' => $fields['VALUE_ENUM_ID'],
									'VALUE_ENUM' => $fields['VALUE_ENUM'],
									'VALUE_ID' => $fields['PROPERTY_VALUE_ID'],
								];
							}
						}
						elseif ($fields['MULTIPLE'] === 'Y')
						{
							if (is_array($fields['PROPERTY_VALUE_ID']))
							{
								foreach ($fields['PROPERTY_VALUE_ID'] as $i => $item)
								{
									$value[] = [
										'VALUE' => $fields['VALUE'][$i],
										'VALUE_ID' => $fields['PROPERTY_VALUE_ID'][$i],
									];
								}
							}
						}
						else
						{
							$value = [
								'VALUE' => $fields['VALUE'],
								'VALUE_ID' => $fields['PROPERTY_VALUE_ID'],
							];
						}
					}

					$result[$k]['PROPERTY_' . $propId] = $value;
				}
			}
			elseif ($propertyIds !== [])
			{
				foreach ($propertyIds as $propId)
				{
					$result[$k]['PROPERTY_' . $propId] = null;
				}
			}
		}
	}

	/**
	 * CIBlockElement pagination is page-based; reject offsets that are not page-aligned.
	 *
	 * @return array{nPageSize: int, iNumPage: int}|Result
	 */
	private function buildNavData(?PaginationStructure $pagination): array|Result
	{
		$limit = $pagination?->getLimit() ?? PaginationStructure::DEFAULT_LIMIT;
		$offset = $pagination?->getOffset() ?? 0;
		$limit = max(1, min($limit, IRestService::LIST_LIMIT));

		if ($offset < 0 || ($offset > 0 && $offset % $limit !== 0))
		{
			return ServiceResult::validation([
				new Error('pagination.offset must be a non-negative multiple of pagination.limit'),
			]);
		}

		return [
			'nPageSize' => $limit,
			'iNumPage' => (int)($offset / $limit) + 1,
		];
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function ensureAddValidate(array $fields): Result
	{
		return $this->ensureCheckFields($fields);
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function ensureUpdateValidate(array $fields): Result
	{
		return $this->ensureCheckFields($fields);
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function ensureCheckFields(array $fields): Result
	{
		$errors = [];

		if (isset($fields['SECTION_ID']))
		{
			$section = CIBlockSection::GetByID($fields['SECTION_ID'])->Fetch();
			if (!isset($section['ID']))
			{
				$errors[] = new Error('Section is not exists');
			}
		}
		if (isset($fields['MODIFIED_BY']))
		{
			$user = CUser::GetByID($fields['MODIFIED_BY'])->Fetch();
			if (!isset($user['ID']))
			{
				$errors[] = new Error('User modifiedBy is not exists');
			}
		}
		if (isset($fields['CREATED_BY']))
		{
			$user = CUser::GetByID($fields['CREATED_BY'])->Fetch();
			if (!isset($user['ID']))
			{
				$errors[] = new Error('User createdBy is not exists');
			}
		}
		if (isset($fields['PURCHASING_CURRENCY']))
		{
			$currency = CCurrency::GetByID($fields['PURCHASING_CURRENCY']);
			if (!isset($currency['CURRENCY']))
			{
				$errors[] = new Error('Currency purchasingCurrency is not exists');
			}
		}
		if (isset($fields['VAT_ID']))
		{
			$vat = VatTable::getRow([
				'select' => ['ID'],
				'filter' => ['=ID' => (int)$fields['VAT_ID']],
			]);
			if ($vat === null)
			{
				$errors[] = new Error('VAT vatId is not exists');
			}
		}

		if ($errors !== [])
		{
			return ServiceResult::validation($errors);
		}

		return ServiceResult::success();
	}

	/**
	 * @param array{NAME: string, IBLOCK_ID: int} $fields
	 */
	private function checkFieldsDownload(array $fields): bool
	{
		$name = $fields['NAME'];
		$iblockId = $fields['IBLOCK_ID'];

		if (in_array($name, ['DETAIL_PICTURE', 'PREVIEW_PICTURE', 'PICTURE'], true))
		{
			return true;
		}

		if (!preg_match('/^PROPERTY_(.+)$/', $name, $match))
		{
			return false;
		}

		$property = CIBlockProperty::GetPropertyArray($match[1], $iblockId);

		return is_array($property) && $property['PROPERTY_TYPE'] === Iblock\PropertyTable::TYPE_FILE;
	}

	private function ensurePermissionAdd(int $iblockId): Result
	{
		$check = $this->ensureIblockElementModify($iblockId, 0);
		if (!$check->isSuccess())
		{
			return $check;
		}

		return $this->accessSupport->ensureProductAdd();
	}

	private function ensurePermissionUpdate(int $iblockId, int $elementId): Result
	{
		$check = $this->ensureIblockElementModify($iblockId, $elementId);
		if (!$check->isSuccess())
		{
			return $check;
		}

		return $this->accessSupport->ensureProductEdit();
	}

	private function ensurePermissionDelete(int $iblockId, int $elementId): Result
	{
		$check = $this->ensureIblockExists($iblockId);
		if (!$check->isSuccess())
		{
			return $check;
		}

		if (!CIBlockElementRights::UserHasRightTo($iblockId, $elementId, self::IBLOCK_ELEMENT_DELETE))
		{
			return ServiceResult::accessDenied();
		}

		return $this->accessSupport->ensureProductDelete();
	}

	private function ensureIblockElementGet(int $iblockId, int $elementId): Result
	{
		$check = $this->ensureIblockExists($iblockId);
		if (!$check->isSuccess())
		{
			return $check;
		}

		if (!CIBlockElementRights::UserHasRightTo($iblockId, $elementId, self::IBLOCK_ELEMENT_READ))
		{
			return ServiceResult::accessDenied();
		}

		return ServiceResult::success();
	}

	private function ensureIblockElementList(int $iblockId): Result
	{
		$check = $this->ensureIblockExists($iblockId);
		if (!$check->isSuccess())
		{
			return $check;
		}

		if (!CIBlockRights::UserHasRightTo($iblockId, $iblockId, self::IBLOCK_READ))
		{
			return ServiceResult::accessDenied();
		}

		return ServiceResult::success();
	}

	private function ensureIblockElementModify(int $iblockId, int $elementId): Result
	{
		$check = $this->ensureIblockExists($iblockId);
		if (!$check->isSuccess())
		{
			return $check;
		}

		$badBlock = $elementId > 0
			? !CIBlockElementRights::UserHasRightTo($iblockId, $elementId, self::IBLOCK_ELEMENT_EDIT)
			: !CIBlockRights::UserHasRightTo($iblockId, $iblockId, self::IBLOCK_ELEMENT_EDIT);

		if ($badBlock)
		{
			return ServiceResult::accessDenied();
		}

		return ServiceResult::success();
	}

	private function ensureIblockModify(int $iblockId): Result
	{
		$check = $this->ensureIblockExists($iblockId);
		if (!$check->isSuccess())
		{
			return $check;
		}

		if (!CIBlockRights::UserHasRightTo($iblockId, $iblockId, self::IBLOCK_EDIT))
		{
			return ServiceResult::accessDenied();
		}

		return ServiceResult::success();
	}

	private function ensureSectionBind(int $iblockId, int $sectionId): Result
	{
		$check = $this->ensureIblockExists($iblockId);
		if (!$check->isSuccess())
		{
			return $check;
		}

		if (!CIBlockSectionRights::UserHasRightTo($iblockId, $sectionId, self::IBLOCK_ELEMENT_SECTION_BIND))
		{
			return ServiceResult::accessDenied();
		}

		return ServiceResult::success();
	}

	private function ensureIblockExists(int $iblockId): Result
	{
		$iblockName = CIBlock::GetArrayByID($iblockId, 'NAME');
		if (empty($iblockName))
		{
			return ServiceResult::validation([
				new Error('Iblock Not Found', '200040300000'),
			]);
		}

		return ServiceResult::success();
	}

	/**
	 * @param array<array-key, mixed> $filter
 	 * @return array<string, mixed>
	 */
	private function normalizeIncomingFilter(array $filter): array
	{
		$result = [];
		foreach ($filter as $key => $value)
		{
			$key = (string)$key;
			if ($key === strtoupper($key))
			{
				$result[$key] = $value;

				continue;
			}

			$result[strtoupper(StringHelper::camel2snake($key))] = $value;
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $filter
	 * @return array<string, mixed>
	 */
	private function stripOperatorPrefixes(array $filter): array
	{
		$result = [];
		foreach ($filter as $key => $value)
		{
			$field = preg_replace('/^(>=|<=|>|<|@|!|=)/', '', (string)$key) ?? (string)$key;
			$result[$field] = $value;
		}

		return $result;
	}
}
