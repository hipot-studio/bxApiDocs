<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\Access\ActionDictionary;
use Bitrix\Catalog\Infrastructure\Rest\Dto\DocumentContractorDto;
use Bitrix\Catalog\Infrastructure\Rest\Support\DtoFieldsConverter;
use Bitrix\Catalog\Infrastructure\Rest\Support\FilterStructureSupport;
use Bitrix\Catalog\StoreDocumentTable;
use Bitrix\Catalog\v2\Contractor\Provider\Manager;
use Bitrix\Crm\Integration\Catalog\Contractor\StoreDocumentContractorTable;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Text\StringHelper;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;

final class DocumentContractorService
{
	public function __construct(
		private readonly AccessSupport $accessSupport,
	)
	{
	}

	public function assertCrmProvider(): Result
	{
		if (!Loader::includeModule('crm'))
		{
			return ServiceResult::validation([
				new Error('CRM module is required for document contractors'),
			]);
		}

		if (!Manager::isActiveProviderByModule(Manager::PROVIDER_STORE_DOCUMENT, 'crm'))
		{
			return ServiceResult::validation([
				new Error('Contractors should be provided by CRM'),
			]);
		}

		return ServiceResult::success();
	}

	public function list(
		?SelectStructure $select,
		?FilterStructure $filter,
		?OrderStructure $order,
		?PaginationStructure $pagination,
	): Result {
		$provider = $this->assertCrmProvider();
		if (!$provider->isSuccess())
		{
			return $provider;
		}

		$access = $this->ensureReadAccess();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$unsupportedFilter = FilterStructureSupport::findUnsupportedSemantics($filter);
		if ($unsupportedFilter !== null)
		{
			return ServiceResult::validation([$unsupportedFilter]);
		}

		// the filter is one conjunction - FilterStructureSupport rejects OR and NOT - so an empty
		// IN list leaves it unsatisfiable; this shortcut has to go when OR groups get supported.
		// D7 would render the list as `IN ()` and the request would fail on the SQL syntax.
		if (FilterStructureSupport::hasEmptyInCondition($filter))
		{
			return ServiceResult::success(['items' => []]);
		}

		$ormFilter = $this->toOrmFilter($filter);
		$documentFilter = $this->accessSupport->getDocumentReferenceFilter(
			'DOCUMENT_ID',
			ActionDictionary::ACTION_STORE_DOCUMENT_VIEW,
		);
		if ($documentFilter)
		{
			$ormFilter = [$documentFilter, $ormFilter];
		}

		$rows = StoreDocumentContractorTable::getList([
			'select' => $this->toOrmSelect($select),
			'filter' => $ormFilter,
			'order' => $this->toOrmOrder($order),
			'offset' => $pagination?->getOffset() ?? 0,
			'limit' => $pagination?->getLimit() ?? PaginationStructure::DEFAULT_LIMIT,
		])->fetchAll();

		return ServiceResult::success(['items' => $rows]);
	}

	public function add(DocumentContractorDto $dto): Result
	{
		$provider = $this->assertCrmProvider();
		if (!$provider->isSuccess())
		{
			return $provider;
		}

		$access = $this->ensureModifyAccess();
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

		$addResult = StoreDocumentContractorTable::add([
			'DOCUMENT_ID' => (int)$fields['DOCUMENT_ID'],
			'ENTITY_ID' => (int)$fields['ENTITY_ID'],
			'ENTITY_TYPE_ID' => (int)$fields['ENTITY_TYPE_ID'],
		]);
		if (!$addResult->isSuccess())
		{
			return ServiceResult::validation($addResult->getErrors());
		}

		$id = (int)$addResult->getId();
		$row = StoreDocumentContractorTable::getRow([
			'select' => ['ID', 'DOCUMENT_ID', 'ENTITY_TYPE_ID', 'ENTITY_ID'],
			'filter' => ['=ID' => $id],
		]);
		if ($row === null)
		{
			return ServiceResult::notFound($id);
		}

		return ServiceResult::success(['item' => $row]);
	}

	public function delete(int $id): Result
	{
		$provider = $this->assertCrmProvider();
		if (!$provider->isSuccess())
		{
			return $provider;
		}

		$access = $this->ensureModifyAccess();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$filter = ['=ID' => $id];
		$documentFilter = $this->accessSupport->getDocumentReferenceFilter(
			'DOCUMENT_ID',
			ActionDictionary::ACTION_STORE_DOCUMENT_VIEW,
			ActionDictionary::ACTION_STORE_DOCUMENT_MODIFY,
		);
		if ($documentFilter)
		{
			$filter[] = $documentFilter;
		}

		$binding = StoreDocumentContractorTable::getRow([
			'select' => ['ID'],
			'filter' => $filter,
		]);
		if ($binding === null)
		{
			return ServiceResult::notFound($id);
		}

		$deleteResult = StoreDocumentContractorTable::delete($id);
		if (!$deleteResult->isSuccess())
		{
			return ServiceResult::validation($deleteResult->getErrors());
		}

		return ServiceResult::success(['result' => true]);
	}

	/**
	 * @return list<string>
	 */
	private function toOrmSelect(?SelectStructure $select): array
	{
		$map = [
			'id' => 'ID',
			'documentId' => 'DOCUMENT_ID',
			'entityTypeId' => 'ENTITY_TYPE_ID',
			'entityId' => 'ENTITY_ID',
		];
		$list = $select?->getList() ?? [];
		if ($list === [])
		{
			return array_values($map);
		}

		$ormSelect = [];
		foreach ($list as $field)
		{
			if (isset($map[$field]))
			{
				$ormSelect[] = $map[$field];
			}
		}

		return $ormSelect !== [] ? array_values(array_unique($ormSelect)) : ['ID'];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function toOrmFilter(?FilterStructure $filter): array
	{
		if ($filter === null)
		{
			return [];
		}

		$result = [];
		$this->appendFilterConditions($filter, $result);

		return $result;
	}

	/**
	 * @param array<int|string, mixed> $result
	 */
	private function appendFilterConditions(FilterStructure $filter, array &$result): void
	{
		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				$nested = [];
				$this->appendFilterConditions($condition, $nested);
				if ($nested !== [])
				{
					$result[] = $nested;
				}

				continue;
			}

			if (!$condition instanceof Condition)
			{
				continue;
			}

			$field = (string)$condition->getLeftOperand();
			$ormField = match ($field)
			{
				'id' => 'ID',
				'documentId' => 'DOCUMENT_ID',
				'entityTypeId' => 'ENTITY_TYPE_ID',
				'entityId' => 'ENTITY_ID',
				default => strtoupper(StringHelper::camel2snake($field)),
			};

			$prefix = match ($condition->getOperator())
			{
				Operator::Equal => '=',
				Operator::NotEqual => '!',
				Operator::Greater => '>',
				Operator::GreaterOrEqual => '>=',
				Operator::Less => '<',
				Operator::LessOrEqual => '<=',
				Operator::In => '@',
				Operator::Between => '><',
			};

			$result[$prefix . $ormField] = $condition->getRightOperand();
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function toOrmOrder(?OrderStructure $order): array
	{
		if ($order === null)
		{
			return [];
		}

		$result = [];
		foreach ($order->getList() as $property => $direction)
		{
			$ormField = match ((string)$property)
			{
				'id' => 'ID',
				'documentId' => 'DOCUMENT_ID',
				'entityTypeId' => 'ENTITY_TYPE_ID',
				'entityId' => 'ENTITY_ID',
				default => strtoupper(StringHelper::camel2snake((string)$property)),
			};
			$result[$ormField] = $direction;
		}

		return $result;
	}

	private function ensureReadAccess(): Result
	{
		if (!$this->accessSupport->checkDocumentByValue(
			ActionDictionary::ACTION_STORE_DOCUMENT_VIEW,
			StoreDocumentTable::TYPE_ARRIVAL,
		))
		{
			return ServiceResult::accessDenied();
		}

		$accessController = $this->accessSupport->getAccessController();
		if (
			!$accessController->check(ActionDictionary::ACTION_STORE_VIEW)
			|| !$accessController->check(ActionDictionary::ACTION_CATALOG_READ)
		)
		{
			return ServiceResult::accessDenied();
		}

		return ServiceResult::success();
	}

	private function ensureModifyAccess(): Result
	{
		$readAccess = $this->ensureReadAccess();
		if (!$readAccess->isSuccess())
		{
			return $readAccess;
		}

		if (!$this->accessSupport->checkDocumentByValue(
			ActionDictionary::ACTION_STORE_DOCUMENT_MODIFY,
			StoreDocumentTable::TYPE_ARRIVAL,
		))
		{
			return ServiceResult::accessDenied();
		}

		return ServiceResult::success();
	}

	private function checkFields(array $fields): Result
	{
		$result = new Result();
		$documentId = (int)($fields['DOCUMENT_ID'] ?? 0);
		$entityTypeId = (int)($fields['ENTITY_TYPE_ID'] ?? 0);
		$entityId = (int)($fields['ENTITY_ID'] ?? 0);

		if (!$documentId)
		{
			$result->addError(new Error('Store document was not found'));

			return $result;
		}

		$document = StoreDocumentTable::getRow([
			'select' => ['ID', 'DOC_TYPE', 'STATUS'],
			'filter' => [
				'ID' => $documentId,
				...$this->accessSupport->getDocumentFilters(
					ActionDictionary::ACTION_STORE_DOCUMENT_VIEW,
					ActionDictionary::ACTION_STORE_DOCUMENT_MODIFY,
				),
			],
		]);
		if (!$document)
		{
			$result->addError(new Error('Store document was not found'));

			return $result;
		}

		if ($document['DOC_TYPE'] !== StoreDocumentTable::TYPE_ARRIVAL)
		{
			$result->addError(new Error('Type of store document is wrong'));
		}

		if ($document['STATUS'] === 'Y')
		{
			$result->addError(new Error('Unable to edit conducted document'));
		}

		if (
			$entityTypeId !== \CCrmOwnerType::Contact
			&& $entityTypeId !== \CCrmOwnerType::Company
		)
		{
			$result->addError(new Error('Wrong entity type id'));
		}

		if (!$entityId)
		{
			$result->addError(new Error('Wrong entity id'));
		}

		$bindingExists = StoreDocumentContractorTable::getRow([
			'select' => ['ID'],
			'filter' => [
				'DOCUMENT_ID' => $documentId,
				'ENTITY_TYPE_ID' => $entityTypeId,
				'ENTITY_ID' => $entityId,
			],
		]);
		if ($bindingExists)
		{
			$result->addError(new Error('This contractor has been already bound to this document'));
		}

		if ($entityTypeId === \CCrmOwnerType::Company)
		{
			$documentCompanyBinding = StoreDocumentContractorTable::getRow([
				'select' => ['ID'],
				'filter' => [
					'DOCUMENT_ID' => $documentId,
					'ENTITY_TYPE_ID' => \CCrmOwnerType::Company,
				],
			]);
			if (!empty($documentCompanyBinding))
			{
				$result->addError(new Error('This document already has a Company contractor'));
			}
		}

		return $result;
	}
}
