<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\Access\ActionDictionary;
use Bitrix\Catalog\Config\Feature;
use Bitrix\Catalog\Config\State;
use Bitrix\Catalog\Infrastructure\Rest\Dto\DocumentDto;
use Bitrix\Catalog\Infrastructure\Rest\Repository\CatalogOrmRepository;
use Bitrix\Catalog\Infrastructure\Rest\Support\ApplicationErrors;
use Bitrix\Catalog\Infrastructure\Rest\Support\DtoFieldsConverter;
use Bitrix\Catalog\Internal\Service\RestValidator\Entity\DocumentFilterValidator;
use Bitrix\Catalog\Internal\Service\RestValidator\Entity\DocumentValidator;
use Bitrix\Catalog\StoreDocumentElementTable;
use Bitrix\Catalog\StoreDocumentTable;
use Bitrix\Catalog\v2\Contractor\Provider\Manager;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;
use CCatalogDocs;

final class DocumentService
{
	use ApplicationErrors;

	private readonly CatalogOrmRepository $repository;

	public function __construct(
		private readonly AccessSupport $accessSupport,
	)
	{
		Loc::loadMessages(dirname(__DIR__, 3) . '/controller/document.php');
		$this->repository = new CatalogOrmRepository(DocumentDto::class);
	}

	public function get(int $id, ?SelectStructure $select = null): Result
	{
		$access = $this->accessSupport->ensureEntityRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		// Same DOC_TYPE allowlist + VIEW/store ACL as list — closes get/list asymmetry.
		$listFilter = (new FilterStructure())
			->where('docType', Operator::In, DocumentTypes::getAvailableCodes())
			->where('id', $id);

		$visible = $this->repository->getCollectionWithExtraFilters(
			$select,
			$listFilter,
			null,
			PaginationStructure::create(['limit' => 1]),
			$this->buildDocumentListAccessFilters(),
		);
		foreach ($visible as $entity)
		{
			return ServiceResult::success(['item' => $entity]);
		}

		if ($this->repository->getEntityById($id) === null)
		{
			return ServiceResult::notFound($id);
		}

		return ServiceResult::accessDenied();
	}

	public function list(
		?SelectStructure $select,
		?FilterStructure $filter,
		?OrderStructure $order,
		?PaginationStructure $pagination,
	): Result {
		$access = $this->accessSupport->ensureEntityRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$filterValidation = $this->validateListFilter($filter);
		if (!$filterValidation->isSuccess())
		{
			return $filterValidation;
		}

		$listFilter = (new FilterStructure())->where(
			'docType',
			Operator::In,
			DocumentTypes::getAvailableCodes(),
		);
		if ($filter !== null)
		{
			$listFilter->addCondition($filter);
		}

		return ServiceResult::success([
			'items' => $this->repository->getCollectionWithExtraFilters(
				$select,
				$listFilter,
				$order,
				$pagination,
				$this->buildDocumentListAccessFilters(),
			),
		]);
	}

	public function add(DocumentDto $dto): Result
	{
		$feature = $this->ensureInventoryManagementFeature();
		if (!$feature->isSuccess())
		{
			return $feature;
		}

		$fields = DtoFieldsConverter::toOrmFields($dto);
		$fields = $this->prepareFieldsModify($fields);
		$validation = $this->validateModifyFields($fields);
		if (!$validation->isSuccess())
		{
			return $validation;
		}

		$docType = (string)($fields['DOC_TYPE'] ?? '');
		if (!isset(DocumentTypes::getAvailable()[$docType]))
		{
			return ServiceResult::validation([
				new Error('DOC_TYPE isn\'t available'),
			]);
		}

		$access = $this->accessSupport->ensureDocumentModify(null, $docType);
		if (!$access->isSuccess())
		{
			return $access;
		}

		$fields = $this->prepareFieldsAdd($fields);

		$documentId = (int)CCatalogDocs::add($fields);
		if ($documentId <= 0)
		{
			return ServiceResult::validation([
				$this->getLastApplicationError() ?? new Error('Document add failed'),
			]);
		}

		return $this->get($documentId);
	}

	public function update(int $id, DocumentDto $dto): Result
	{
		$feature = $this->ensureInventoryManagementFeature();
		if (!$feature->isSuccess())
		{
			return $feature;
		}

		$access = $this->accessSupport->ensureDocumentModify($id);
		if (!$access->isSuccess())
		{
			return $access;
		}

		$document = $this->get($id, SelectStructure::create(['id'], DocumentDto::class));
		if (!$document->isSuccess())
		{
			return $document;
		}

		$fields = DtoFieldsConverter::toOrmFields($dto);
		$fields = $this->prepareFieldsModify($fields);
		$validation = $this->validateModifyFields($fields);
		if (!$validation->isSuccess())
		{
			return $validation;
		}

		$user = CurrentUser::get();
		if (!array_key_exists('MODIFIED_BY', $fields) || !$user->isAdmin())
		{
			$fields['MODIFIED_BY'] = $user->getId();
		}

		$result = CCatalogDocs::update($id, $fields);
		if (!$result)
		{
			$error = $this->getLastApplicationError();
			if ($error)
			{
				return ServiceResult::validation([$error]);
			}
		}

		return $this->get($id);
	}

	/**
	 * @param list<int> $documentIds
	 */
	public function delete(array $documentIds): Result
	{
		$feature = $this->ensureInventoryManagementFeature();
		if (!$feature->isSuccess())
		{
			return $feature;
		}

		$documentIds = array_values(array_unique(array_map('intval', $documentIds)));
		if ($documentIds === [])
		{
			return ServiceResult::validation([
				new Error(Loc::getMessage('CATALOG_CONTROLLER_DOCUMENT_NOT_FOUND') ?? 'Document not found'),
			]);
		}

		$documentData = $this->getDocumentData($documentIds);
		$storeList = $this->getDocumentStoreList($documentIds);
		$errors = [];

		foreach ($documentIds as $documentId)
		{
			$document = $documentData[$documentId] ?? null;
			if (!$document)
			{
				$errors[] = new Error(Loc::getMessage(
					'DOCUMENT_CONTROLLER_DELETE_ERROR',
					[
						'#DOC_TITLE#' => "#{$documentId}",
						'#ERROR#' => Loc::getMessage('CATALOG_CONTROLLER_DOCUMENT_NOT_FOUND'),
					],
				) ?? "Document #{$documentId} not found");
				continue;
			}

			$can = $this->accessSupport->checkDocumentAction(
				ActionDictionary::ACTION_STORE_DOCUMENT_DELETE,
				$document,
			);
			if (isset($storeList[$documentId]))
			{
				$can = $can && $this->accessSupport->checkAllStoreAccess($storeList[$documentId]);
			}

			if (!$can)
			{
				return ServiceResult::accessDenied();
			}

			CCatalogDocs::delete($documentId);
			$error = $this->getLastApplicationError();
			if ($error)
			{
				$documentTitle = $document['TITLE'] ?: (DocumentTypes::getAvailable()[$document['DOC_TYPE']] ?? $document['DOC_TYPE']);
				if ($error->getCode() === (string)CCatalogDocs::DELETE_CONDUCTED_ERROR)
				{
					$errors[] = new Error(Loc::getMessage(
						'DOCUMENT_CONTROLLER_DELETE_CONDUCTED_ERROR',
						['#DOC_TITLE#' => htmlspecialcharsbx($documentTitle)],
					) ?? 'Cannot delete conducted document', (string)CCatalogDocs::DELETE_CONDUCTED_ERROR);
				}
				else
				{
					$errors[] = new Error(Loc::getMessage(
						'DOCUMENT_CONTROLLER_DELETE_ERROR',
						[
							'#DOC_TITLE#' => htmlspecialcharsbx($documentTitle),
							'#ERROR#' => htmlspecialcharsbx($error->getMessage()),
						],
					) ?? $error->getMessage());
				}
			}
		}

		if ($errors !== [])
		{
			return ServiceResult::validation($errors);
		}

		return ServiceResult::success(['result' => true]);
	}

	/**
	 * @param list<int> $documentIds
	 * @param bool $batch Engine parity: batch/list path requires inventory State gate
	 */
	public function conduct(array $documentIds, bool $batch = false): Result
	{
		$documentIds = array_values(array_unique(array_map('intval', $documentIds)));
		if ($documentIds === [])
		{
			return ServiceResult::notFound(0);
		}

		if ($batch || count($documentIds) > 1)
		{
			return $this->conductBatch($documentIds);
		}

		return $this->conductOne($documentIds[0]);
	}

	public function confirm(int $id): Result
	{
		return $this->conduct([$id], false);
	}

	/**
	 * @param list<int> $documentIds
	 * @param bool $batch Engine parity: batch/list path requires inventory State gate
	 */
	public function cancel(array $documentIds, bool $batch = false): Result
	{
		$documentIds = array_values(array_unique(array_map('intval', $documentIds)));
		if ($documentIds === [])
		{
			return ServiceResult::notFound(0);
		}

		if ($batch || count($documentIds) > 1)
		{
			return $this->cancelBatch($documentIds);
		}

		return $this->cancelOne($documentIds[0]);
	}

	public function unconfirm(int $id): Result
	{
		return $this->cancel([$id], false);
	}

	private function conductOne(int $id): Result
	{
		$feature = $this->ensureInventoryManagementFeature();
		if (!$feature->isSuccess())
		{
			return $feature;
		}

		$documentResult = $this->getDocumentForAction(
			$id,
			ActionDictionary::ACTION_STORE_DOCUMENT_CONDUCT,
		);
		if (!$documentResult->isSuccess())
		{
			return $documentResult;
		}

		$userId = (int)CurrentUser::get()->getId();
		if (!CCatalogDocs::conductDocument($id, $userId))
		{
			$errorText = $this->getLastApplicationErrorText() ?? '';
			return ServiceResult::validation([
				new Error(Loc::getMessage('CATALOG_CONTROLLER_DOCUMENT_CONDUCT_ERROR', [
					'#ERROR#' => $errorText,
				]) ?? ('Conduct failed: ' . $errorText)),
			]);
		}

		return ServiceResult::success(['result' => true]);
	}

	private function cancelOne(int $id): Result
	{
		$feature = $this->ensureInventoryManagementFeature();
		if (!$feature->isSuccess())
		{
			return $feature;
		}

		$documentResult = $this->getDocumentForAction(
			$id,
			ActionDictionary::ACTION_STORE_DOCUMENT_CANCEL,
		);
		if (!$documentResult->isSuccess())
		{
			return $documentResult;
		}

		$userId = (int)CurrentUser::get()->getId();
		if (!CCatalogDocs::cancellationDocument($id, $userId))
		{
			$errorText = $this->getLastApplicationErrorText() ?? '';
			return ServiceResult::validation([
				new Error(Loc::getMessage('CATALOG_CONTROLLER_DOCUMENT_CANCEL_ERROR', [
					'#ERROR#' => $errorText,
				]) ?? ('Cancel failed: ' . $errorText)),
			]);
		}

		return ServiceResult::success(['result' => true]);
	}

	/**
	 * @param list<int> $documentIds
	 */
	private function conductBatch(array $documentIds): Result
	{
		$feature = $this->ensureInventoryManagementFeature();
		if (!$feature->isSuccess())
		{
			return $feature;
		}

		$state = $this->ensureInventoryManagementState();
		if (!$state->isSuccess())
		{
			return $state;
		}

		$documentResult = $this->accessSupport->getAccessibleDocuments($documentIds);
		if (!$documentResult->isSuccess())
		{
			return $documentResult;
		}

		$documentData = $documentResult->getData()['documents'] ?? [];
		$userId = (int)CurrentUser::get()->getId();
		$errors = [];

		foreach ($documentIds as $documentId)
		{
			$document = $documentData[$documentId] ?? null;
			if (!$document)
			{
				$errors[] = new Error(Loc::getMessage(
					'CATALOG_CONTROLLER_DOCUMENT_CONDUCT_GENERAL_ERROR',
					['#ERROR#' => Loc::getMessage('CATALOG_CONTROLLER_DOCUMENT_NOT_FOUND')],
				) ?? 'Document not found');
				continue;
			}

			$documentTitle = $document['TITLE'] ?: (DocumentTypes::getAvailable()[$document['DOC_TYPE']] ?? $document['DOC_TYPE']);
			if (!$this->accessSupport->checkDocumentAction(
				ActionDictionary::ACTION_STORE_DOCUMENT_CONDUCT,
				$document,
			))
			{
				$errors[] = new Error(Loc::getMessage('CATALOG_CONTROLLER_DOCUMENT_CONDUCT_ERROR', [
					'#DOC_TITLE#' => htmlspecialcharsbx($documentTitle),
					'#ERROR#' => Loc::getMessage('DOCUMENT_CONTROLLER_NO_RIGHTS_ERROR'),
				]) ?? 'Access denied');
				continue;
			}

			if (!CCatalogDocs::conductDocument($documentId, $userId))
			{
				$errorText = $this->getLastApplicationErrorText();
				if ($errorText !== null)
				{
					$errors[] = new Error(Loc::getMessage('DOCUMENT_CONTROLLER_CONDUCT_ERROR', [
						'#DOC_TITLE#' => htmlspecialcharsbx($documentTitle),
						'#ERROR#' => htmlspecialcharsbx($errorText),
					]) ?? $errorText);
				}
				else
				{
					$errors[] = new Error(Loc::getMessage('CATALOG_CONTROLLER_DOCUMENT_CONDUCT_GENERAL_ERROR', [
						'#DOC_TITLE#' => htmlspecialcharsbx($documentTitle),
					]) ?? 'Conduct failed');
				}
			}
		}

		if ($errors !== [])
		{
			return ServiceResult::validation($errors);
		}

		return ServiceResult::success(['result' => true]);
	}

	/**
	 * @param list<int> $documentIds
	 */
	private function cancelBatch(array $documentIds): Result
	{
		$feature = $this->ensureInventoryManagementFeature();
		if (!$feature->isSuccess())
		{
			return $feature;
		}

		$state = $this->ensureInventoryManagementState();
		if (!$state->isSuccess())
		{
			return $state;
		}

		$documentResult = $this->accessSupport->getAccessibleDocuments($documentIds);
		if (!$documentResult->isSuccess())
		{
			return $documentResult;
		}

		$documentData = $documentResult->getData()['documents'] ?? [];
		$userId = (int)CurrentUser::get()->getId();
		$errors = [];

		foreach ($documentIds as $documentId)
		{
			$document = $documentData[$documentId] ?? null;
			if (!$document)
			{
				$errors[] = new Error(Loc::getMessage(
					'CATALOG_CONTROLLER_DOCUMENT_CANCEL_ERROR',
					['#ERROR#' => Loc::getMessage('CATALOG_CONTROLLER_DOCUMENT_NOT_FOUND')],
				) ?? 'Document not found');
				continue;
			}

			$documentTitle = $document['TITLE'] ?: (DocumentTypes::getAvailable()[$document['DOC_TYPE']] ?? $document['DOC_TYPE']);
			if (!$this->accessSupport->checkDocumentAction(
				ActionDictionary::ACTION_STORE_DOCUMENT_CANCEL,
				$document,
			))
			{
				$errors[] = new Error(Loc::getMessage('DOCUMENT_CONTROLLER_CANCEL_ERROR', [
					'#DOC_TITLE#' => htmlspecialcharsbx($documentTitle),
					'#ERROR#' => Loc::getMessage('DOCUMENT_CONTROLLER_NO_RIGHTS_ERROR'),
				]) ?? 'Access denied');
				continue;
			}

			if (!CCatalogDocs::cancellationDocument($documentId, $userId))
			{
				$errorText = $this->getLastApplicationErrorText();
				if ($errorText !== null)
				{
					$errors[] = new Error(Loc::getMessage('DOCUMENT_CONTROLLER_CANCEL_ERROR', [
						'#DOC_TITLE#' => htmlspecialcharsbx($documentTitle),
						'#ERROR#' => htmlspecialcharsbx($errorText),
					]) ?? $errorText);
				}
				else
				{
					$errors[] = new Error(Loc::getMessage('CATALOG_CONTROLLER_DOCUMENT_CANCEL_ERROR', [
						'#DOC_TITLE#' => htmlspecialcharsbx($documentTitle),
					]) ?? 'Cancel failed');
				}
			}
		}

		if ($errors !== [])
		{
			return ServiceResult::validation($errors);
		}

		return ServiceResult::success(['result' => true]);
	}

	private function ensureInventoryManagementFeature(): Result
	{
		if (!Feature::isInventoryManagementEnabled())
		{
			return ServiceResult::validation([
				new Error(
					Loc::getMessage('DOCUMENT_CONTROLLER_NO_INVENTORY_MANAGEMENT_ENABLED_ERROR')
						?? 'Inventory management is not enabled',
				),
			]);
		}

		return ServiceResult::success();
	}

	private function ensureInventoryManagementState(): Result
	{
		if (!State::isUsedInventoryManagement())
		{
			return ServiceResult::validation([
				new Error(
					Loc::getMessage('DOCUMENT_CONTROLLER_MANAGEMENT_NOT_ENABLED')
						?? 'Inventory management is not used',
				),
			]);
		}

		return ServiceResult::success();
	}

	private function prepareFieldsAdd(array $fields): array
	{
		if (!array_key_exists('SITE_ID', $fields))
		{
			$fields['SITE_ID'] = defined('SITE_ID') ? SITE_ID : 's1';
		}

		$user = CurrentUser::get();
		if (!$user->isAdmin() || !array_key_exists('CREATED_BY', $fields))
		{
			$fields['CREATED_BY'] = $user->getId();
		}

		if (!$user->isAdmin() || !array_key_exists('MODIFIED_BY', $fields))
		{
			$fields['MODIFIED_BY'] = $user->getId();
		}

		return $fields;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private function prepareFieldsModify(array $fields): array
	{
		if (Manager::isActiveProviderExists())
		{
			unset($fields['CONTRACTOR_ID']);
		}

		return $fields;
	}

	private function validateModifyFields(array $fields): Result
	{
		$result = (new DocumentValidator())->run($fields);
		if (!$result->isSuccess())
		{
			return ServiceResult::validation($result->getErrors());
		}

		return ServiceResult::success();
	}

	/**
	 * @return list<array|null>
	 */
	private function buildDocumentListAccessFilters(): array
	{
		return $this->accessSupport->getDocumentReadFilters();
	}

	private function getDocumentForAction(int $documentId, string $action): Result
	{
		$documentResult = $this->accessSupport->getAccessibleDocuments([$documentId]);
		if (!$documentResult->isSuccess())
		{
			return $documentResult;
		}

		$document = $documentResult->getData()['documents'][$documentId] ?? null;
		if (!$document)
		{
			$canAccessMissingDocument = $this->accessSupport->checkDocumentAction(
				$action,
				[
					'ID' => $documentId,
					'DOC_TYPE' => null,
				],
			);
			if (!$canAccessMissingDocument)
			{
				return ServiceResult::accessDenied();
			}

			if (StoreDocumentTable::getById($documentId)->fetch())
			{
				return ServiceResult::accessDenied();
			}

			return ServiceResult::notFound($documentId);
		}

		if (!$this->accessSupport->checkDocumentAction($action, $document))
		{
			return ServiceResult::accessDenied();
		}

		if (!StoreDocumentTable::getById($documentId)->fetch())
		{
			return ServiceResult::notFound($documentId);
		}

		return ServiceResult::success(['document' => $document]);
	}

	/**
	 * Engine parity: Document controller processBeforeList → DocumentFilterValidator.
	 * V3 FilterStructure is normalized to Engine camelCase filter array first.
	 */
	private function validateListFilter(?FilterStructure $filter): Result
	{
		if ($filter === null)
		{
			return ServiceResult::success();
		}

		$engineFilter = $this->filterStructureToEngineFilter($filter);
		if ($engineFilter === [] && $filter->getConditions() !== [])
		{
			return ServiceResult::validation([new Error('Incorrect filter format')]);
		}

		$validation = (new DocumentFilterValidator())->run($engineFilter);
		if (!$validation->isSuccess())
		{
			return ServiceResult::validation($validation->getErrors());
		}

		return ServiceResult::success();
	}

	/**
	 * @return array<int|string, mixed>
	 */
	private function filterStructureToEngineFilter(FilterStructure $filter): array
	{
		$result = [];
		$this->appendEngineFilterConditions($filter, $result);

		return $result;
	}

	/**
	 * @param array<int|string, mixed> $result
	 */
	private function appendEngineFilterConditions(FilterStructure $filter, array &$result): void
	{
		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				$nested = [];
				$this->appendEngineFilterConditions($condition, $nested);
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

			$result[$prefix . $field] = $this->normalizeEngineFilterValue($condition->getRightOperand());
		}
	}

	private function normalizeEngineFilterValue(mixed $value): mixed
	{
		if ($value instanceof Date)
		{
			return (string)$value;
		}

		if (is_array($value))
		{
			$normalized = [];
			foreach ($value as $key => $item)
			{
				$normalized[$key] = $this->normalizeEngineFilterValue($item);
			}

			return $normalized;
		}

		return $value;
	}

	/**
	 * @param list<int> $documentIds
	 * @return array<int, array{ID: int, TITLE: ?string, DOC_TYPE: string}>
	 */
	private function getDocumentData(array $documentIds): array
	{
		if ($documentIds === [])
		{
			return [];
		}

		$rows = StoreDocumentTable::getList([
			'select' => ['ID', 'DOC_TYPE', 'TITLE'],
			'filter' => ['@ID' => $documentIds],
		]);

		$result = [];
		while ($document = $rows->fetch())
		{
			$result[(int)$document['ID']] = [
				'ID' => (int)$document['ID'],
				'TITLE' => $document['TITLE'],
				'DOC_TYPE' => (string)$document['DOC_TYPE'],
			];
		}

		return $result;
	}

	/**
	 * @param list<int> $documentIds
	 * @return array<int, array<int, int>>
	 */
	private function getDocumentStoreList(array $documentIds): array
	{
		if ($documentIds === [])
		{
			return [];
		}

		$rows = StoreDocumentElementTable::getList([
			'select' => ['DOC_ID', 'STORE_FROM', 'STORE_TO'],
			'filter' => ['@DOC_ID' => $documentIds],
		]);

		$storeList = [];
		while ($document = $rows->fetch())
		{
			$documentId = (int)$document['DOC_ID'];
			$storeList[$documentId] ??= [];

			$storeFrom = (int)$document['STORE_FROM'];
			$storeTo = (int)$document['STORE_TO'];

			if ($storeFrom > 0)
			{
				$storeList[$documentId][$storeFrom] = $storeFrom;
			}
			if ($storeTo > 0)
			{
				$storeList[$documentId][$storeTo] = $storeTo;
			}
		}

		return $storeList;
	}
}
