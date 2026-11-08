<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\Access\ActionDictionary;
use Bitrix\Catalog\Infrastructure\Rest\Dto\DocumentElementDto;
use Bitrix\Catalog\Infrastructure\Rest\Repository\CatalogOrmRepository;
use Bitrix\Catalog\Infrastructure\Rest\Support\DtoFieldsConverter;
use Bitrix\Catalog\StoreDocumentElementTable;
use Bitrix\Catalog\StoreDocumentTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;
use CCatalogStoreDocsElement;

final class DocumentElementService
{
	private const DOCUMENT_STATUS_ALLOWED = 0;
	private const DOCUMENT_STATUS_CONDUCT = -1;
	private const DOCUMENT_STATUS_ABSENT = -2;

	private readonly CatalogOrmRepository $repository;

	public function __construct(
		private readonly AccessSupport $accessSupport,
	)
	{
		$this->repository = new CatalogOrmRepository(DocumentElementDto::class);
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

		$extraFilters = [
			[
				'@DOCUMENT.DOC_TYPE' => DocumentTypes::getAvailableCodes(),
			],
		];

		$accessFilter = $this->accessSupport->getAccessController()->getEntityFilter(
			ActionDictionary::ACTION_STORE_DOCUMENT_VIEW,
			StoreDocumentElementTable::class,
		);
		if ($accessFilter)
		{
			$extraFilters[] = $accessFilter;
		}

		$storeFilter = $this->accessSupport->getAccessController()->getEntityFilter(
			ActionDictionary::ACTION_STORE_VIEW,
			StoreDocumentElementTable::class,
		);
		if ($storeFilter)
		{
			$extraFilters[] = $storeFilter;
		}

		return ServiceResult::success([
			'items' => $this->repository->getCollectionWithExtraFilters(
				$select,
				$filter,
				$order,
				$pagination,
				$extraFilters,
			),
		]);
	}

	public function add(DocumentElementDto $dto): Result
	{
		$fields = DtoFieldsConverter::toOrmFields($dto);
		$documentId = (int)($fields['DOC_ID'] ?? 0);
		if ($documentId <= 0)
		{
			return ServiceResult::notFound(0);
		}

		$documentFields = $this->getDocumentFields($documentId);
		if (!$documentFields)
		{
			return ServiceResult::notFound($documentId);
		}

		$access = $this->accessSupport->ensureDocumentModify(
			(int)$documentFields['ID'],
			(string)$documentFields['DOC_TYPE'],
		);
		if (!$access->isSuccess())
		{
			return $access;
		}

		$storeAccess = $this->accessSupport->ensureDocumentElementStoresAccess(0, $fields);
		if (!$storeAccess->isSuccess())
		{
			return $storeAccess;
		}

		return match ($this->getDocumentStatus($documentFields))
		{
			self::DOCUMENT_STATUS_ALLOWED => $this->addAllowed($fields),
			self::DOCUMENT_STATUS_CONDUCT => ServiceResult::validation([
				new Error('Conducted document', 'ERROR_DOCUMENT_STATUS'),
			]),
			default => ServiceResult::notFound($documentId),
		};
	}

	public function update(int $id, DocumentElementDto $dto): Result
	{
		$documentFields = $this->getDocumentFieldsByElementId($id);
		if (!$documentFields)
		{
			return ServiceResult::notFound($id);
		}

		$access = $this->accessSupport->ensureDocumentModify(
			(int)$documentFields['ID'],
			(string)$documentFields['DOC_TYPE'],
		);
		if (!$access->isSuccess())
		{
			return $access;
		}

		$fields = DtoFieldsConverter::toOrmFields($dto);
		$storeAccess = $this->accessSupport->ensureDocumentElementStoresAccess($id, $fields);
		if (!$storeAccess->isSuccess())
		{
			return $storeAccess;
		}

		return match ($this->getDocumentStatus($documentFields))
		{
			self::DOCUMENT_STATUS_ALLOWED => $this->updateAllowed($id, $fields),
			self::DOCUMENT_STATUS_CONDUCT => ServiceResult::validation([
				new Error('Conducted document', 'ERROR_DOCUMENT_STATUS'),
			]),
			default => ServiceResult::notFound($id),
		};
	}

	public function delete(int $id): Result
	{
		$documentFields = $this->getDocumentFieldsByElementId($id);
		if (!$documentFields)
		{
			return ServiceResult::notFound($id);
		}

		$access = $this->accessSupport->ensureDocumentModify(
			(int)$documentFields['ID'],
			(string)$documentFields['DOC_TYPE'],
		);
		if (!$access->isSuccess())
		{
			return $access;
		}

		$storeAccess = $this->accessSupport->ensureDocumentElementStoresAccess($id);
		if (!$storeAccess->isSuccess())
		{
			return $storeAccess;
		}

		return match ($this->getDocumentStatus($documentFields))
		{
			self::DOCUMENT_STATUS_ALLOWED => $this->deleteAllowed($id),
			self::DOCUMENT_STATUS_CONDUCT => ServiceResult::validation([
				new Error('Conducted document', 'ERROR_DOCUMENT_STATUS'),
			]),
			default => ServiceResult::notFound($id),
		};
	}

	private function addAllowed(array $fields): Result
	{
		$addResult = CCatalogStoreDocsElement::add($fields);
		if (!$addResult)
		{
			return ServiceResult::validation([
				new Error('Error of adding new document element'),
			]);
		}

		return $this->loadEntity($addResult);
	}

	private function updateAllowed(int $id, array $fields): Result
	{
		$result = CCatalogStoreDocsElement::update($id, $fields);
		if (!$result)
		{
			return ServiceResult::validation([
				new Error('Error of modifying new document'),
			]);
		}

		return $this->loadEntity($id);
	}

	private function deleteAllowed(int $id): Result
	{
		$result = CCatalogStoreDocsElement::delete($id);
		if (!$result)
		{
			return ServiceResult::validation([
				new Error('Error of deleting document'),
			]);
		}

		return ServiceResult::success(['result' => true]);
	}

	private function loadEntity(int $id): Result
	{
		$entity = $this->repository->getEntityById($id);
		if ($entity === null)
		{
			return ServiceResult::notFound($id);
		}

		return ServiceResult::success(['item' => $entity]);
	}

	private function getDocumentFields(int $documentId): ?array
	{
		return StoreDocumentTable::getRow([
			'select' => ['ID', 'STATUS', 'DOC_TYPE'],
			'filter' => ['=ID' => $documentId],
		]);
	}

	private function getDocumentFieldsByElementId(int $elementId): ?array
	{
		$row = StoreDocumentElementTable::getRow([
			'select' => [
				'STORE_DOCUMENT_ID' => 'DOC_ID',
				'STATUS' => 'DOCUMENT.STATUS',
				'DOC_TYPE' => 'DOCUMENT.DOC_TYPE',
			],
			'filter' => ['=ID' => $elementId],
		]);
		if ($row)
		{
			$row['ID'] = $row['STORE_DOCUMENT_ID'];
			unset($row['STORE_DOCUMENT_ID']);
		}

		return $row;
	}

	private function getDocumentStatus(?array $row): int
	{
		if (empty($row) || !is_array($row))
		{
			return self::DOCUMENT_STATUS_ABSENT;
		}

		if (!isset(DocumentTypes::getAvailable()[$row['DOC_TYPE']]))
		{
			return self::DOCUMENT_STATUS_ABSENT;
		}

		return ($row['STATUS'] === 'N')
			? self::DOCUMENT_STATUS_ALLOWED
			: self::DOCUMENT_STATUS_CONDUCT;
	}
}
