<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\Access\AccessController;
use Bitrix\Catalog\Access\ActionDictionary;
use Bitrix\Catalog\Access\Model\StoreDocument;
use Bitrix\Catalog\Access\Model\StoreDocumentElement;
use Bitrix\Catalog\StoreDocumentTable;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CIBlock;
use CIBlockRights;
use CIBlockSection;
use CIBlockSectionRights;

final class AccessSupport
{
	private const IBLOCK_SECTION_READ = 'section_read';
	private const IBLOCK_SECTION_EDIT = 'section_edit';
	private const IBLOCK_SECTION_DELETE = 'section_delete';
	private const IBLOCK_SECTION_BIND = 'section_section_bind';
	private const IBLOCK_ELEMENT_EDIT_PRICE = 'element_edit_price';

	public function __construct(
		private readonly ?int $userId = null,
	)
	{
	}

	public function ensureCatalogRead(): Result
	{
		$result = new Result();
		if (!$this->getAccessController()->check(ActionDictionary::ACTION_CATALOG_READ))
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	public function ensureDocumentReadAccess(): Result
	{
		$result = $this->ensureCatalogRead();
		if (!$result->isSuccess())
		{
			return $result;
		}

		if (!$this->getAccessController()->check(ActionDictionary::ACTION_INVENTORY_MANAGEMENT_ACCESS))
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	/**
	 * @param list<int> $documentIds
	 */
	public function getAccessibleDocuments(array $documentIds): Result
	{
		$result = $this->ensureDocumentReadAccess();
		if (!$result->isSuccess())
		{
			return $result;
		}

		$documentIds = array_values(array_unique(array_map('intval', $documentIds)));
		$documents = [];
		if ($documentIds !== [])
		{
			$filter = ['@ID' => $documentIds];
			foreach ($this->getDocumentReadFilters() as $accessFilter)
			{
				$filter[] = $accessFilter;
			}

			$rows = StoreDocumentTable::getList([
				'select' => ['ID', 'DOC_TYPE', 'TITLE'],
				'filter' => $filter,
			]);
			while ($document = $rows->fetch())
			{
				$documentId = (int)$document['ID'];
				$documents[$documentId] = [
					'ID' => $documentId,
					'TITLE' => $document['TITLE'],
					'DOC_TYPE' => (string)$document['DOC_TYPE'],
				];
			}
		}

		$result->setData(['documents' => $documents]);

		return $result;
	}

	/**
	 * @return list<array>
	 */
	public function getDocumentReadFilters(): array
	{
		return $this->getDocumentFilters(ActionDictionary::ACTION_STORE_DOCUMENT_VIEW);
	}

	public function getDocumentFilters(string ...$documentActions): array
	{
		$accessController = $this->getAccessController();
		$filters = [];

		foreach ([...$documentActions, ActionDictionary::ACTION_STORE_VIEW] as $action)
		{
			$filter = $accessController->getEntityFilter($action, StoreDocumentTable::class);
			if ($filter)
			{
				$filters[] = $filter;
			}
		}

		return $filters;
	}

	public function getDocumentReferenceFilter(string $documentIdField, string ...$documentActions): array
	{
		$filters = $this->getDocumentFilters(...$documentActions);
		if ($filters === [])
		{
			return [];
		}

		$query =
			StoreDocumentTable::query()
				->setSelect(['ID'])
				->setFilter($filters)
				->getQuery()
		;

		return [
			'@' . $documentIdField => new SqlExpression($query),
		];
	}

	/**
	 * Entity read gate: store view OR catalog read.
	 */
	public function ensureEntityRead(): Result
	{
		$result = new Result();
		$accessController = $this->getAccessController();

		if (
			!$accessController->check(ActionDictionary::ACTION_STORE_VIEW)
			&& !$accessController->check(ActionDictionary::ACTION_CATALOG_READ)
		)
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	public function ensureStoreView(int $storeId): Result
	{
		$result = $this->ensureEntityRead();
		if (!$result->isSuccess())
		{
			return $result;
		}

		if ($storeId <= 0)
		{
			return $result;
		}

		if (!$this->getAccessController()->checkByValue(
			ActionDictionary::ACTION_STORE_VIEW,
			(string)$storeId,
		))
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	public function ensureDocumentModify(?int $entityId = null, ?string $docType = null): Result
	{
		$result = new Result();
		if (!$this->getAccessController()->check(
			ActionDictionary::ACTION_STORE_DOCUMENT_MODIFY,
			$this->resolveStoreDocumentModel($entityId, $docType),
		))
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	public function ensureDocumentConduct(int $documentId): Result
	{
		$result = new Result();
		if (!$this->getAccessController()->check(
			ActionDictionary::ACTION_STORE_DOCUMENT_CONDUCT,
			StoreDocument::createFromId($documentId),
		))
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	public function ensureDocumentCancel(int $documentId): Result
	{
		$result = new Result();
		if (!$this->getAccessController()->check(
			ActionDictionary::ACTION_STORE_DOCUMENT_CANCEL,
			StoreDocument::createFromId($documentId),
		))
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	public function ensureDocumentDelete(int $documentId): Result
	{
		$result = new Result();
		if (!$this->getAccessController()->check(
			ActionDictionary::ACTION_STORE_DOCUMENT_DELETE,
			StoreDocument::createFromId($documentId),
		))
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	public function checkDocumentAction(string $action, array $documentFields): bool
	{
		return $this->getAccessController()->check(
			$action,
			StoreDocument::createFromArray($documentFields),
		);
	}

	public function checkDocumentActionById(string $action, int $documentId): bool
	{
		return $this->getAccessController()->check(
			$action,
			StoreDocument::createFromId($documentId),
		);
	}

	/**
	 * @param array<int, int> $storeIds
	 */
	public function checkAllStoreAccess(array $storeIds): bool
	{
		foreach ($storeIds as $storeId)
		{
			if (!$this->getAccessController()->checkByValue(
				ActionDictionary::ACTION_STORE_VIEW,
				(string)$storeId,
			))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<int, int> $storeIds
	 */
	public function ensureAllStoreAccess(array $storeIds): Result
	{
		$result = new Result();
		if (!$this->checkAllStoreAccess($storeIds))
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	public function ensureDocumentElementStoresAccess(int $elementId, array $fields = []): Result
	{
		$result = new Result();
		$fields['ID'] = $elementId;

		if (!$this->getAccessController()->check(
			ActionDictionary::ACTION_STORE_VIEW,
			StoreDocumentElement::createFromArray($fields),
		))
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	public function checkDocumentByValue(string $action, string $docType): bool
	{
		return $this->getAccessController()->checkByValue($action, $docType);
	}

	/**
	 * Product entity read gate: catalog read OR price edit OR catalog view.
	 */
	public function ensureProductEntityRead(): Result
	{
		$result = new Result();
		$accessController = $this->getAccessController();

		if (
			!$accessController->check(ActionDictionary::ACTION_CATALOG_READ)
			&& !$accessController->check(ActionDictionary::ACTION_PRICE_EDIT)
			&& !$accessController->check(ActionDictionary::ACTION_CATALOG_VIEW)
		)
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	public function ensureProductAdd(): Result
	{
		$result = new Result();
		if (!$this->getAccessController()->check(ActionDictionary::ACTION_PRODUCT_ADD))
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	public function ensureProductEdit(): Result
	{
		$result = new Result();
		if (!$this->getAccessController()->check(ActionDictionary::ACTION_PRODUCT_EDIT))
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	public function ensureProductDelete(): Result
	{
		$result = new Result();
		if (!$this->getAccessController()->check(ActionDictionary::ACTION_PRODUCT_DELETE))
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	/**
	 * Price type entity read: catalog read OR price group edit (Engine PriceTypeRights).
	 */
	public function ensurePriceTypeRead(): Result
	{
		$result = new Result();
		$accessController = $this->getAccessController();

		if (
			!$accessController->check(ActionDictionary::ACTION_CATALOG_READ)
			&& !$accessController->check(ActionDictionary::ACTION_PRICE_GROUP_EDIT)
		)
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	public function ensurePriceTypeModify(): Result
	{
		$result = new Result();
		if (!$this->getAccessController()->check(ActionDictionary::ACTION_PRICE_GROUP_EDIT))
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	/**
	 * Price entity read gate: catalog read OR price edit (Engine Price::checkReadPermissionEntity).
	 */
	public function ensurePriceRead(): Result
	{
		$result = new Result();
		$accessController = $this->getAccessController();

		if (
			!$accessController->check(ActionDictionary::ACTION_CATALOG_READ)
			&& !$accessController->check(ActionDictionary::ACTION_PRICE_EDIT)
		)
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	/**
	 * Price entity write gate: the read gate AND price edit (Engine Price::checkModifyPermissionEntity).
	 */
	public function ensurePriceModify(): Result
	{
		$result = $this->ensurePriceRead();
		if (!$result->isSuccess())
		{
			return $result;
		}

		if (!$this->getAccessController()->check(ActionDictionary::ACTION_PRICE_EDIT))
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	/**
	 * Price rights on the iblock element of a product
	 * (Engine Price::checkPermissionIBlockElementPriceModify).
	 */
	public function ensureIblockElementPriceModify(int $productId): Result
	{
		$result = new Result();

		$iblockId = (int)\CIBlockElement::GetIBlockByID($productId);
		if (!\CIBlockElementRights::UserHasRightTo($iblockId, $productId, self::IBLOCK_ELEMENT_EDIT_PRICE))
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	/**
	 * Vat entity read: catalog read OR vat edit (Engine Vat::checkReadPermissionEntity).
	 */
	public function ensureVatRead(): Result
	{
		$result = new Result();
		$accessController = $this->getAccessController();

		if (
			!$accessController->check(ActionDictionary::ACTION_CATALOG_READ)
			&& !$accessController->check(ActionDictionary::ACTION_VAT_EDIT)
		)
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	public function ensureVatModify(): Result
	{
		$result = new Result();
		if (!$this->getAccessController()->check(ActionDictionary::ACTION_VAT_EDIT))
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	/**
	 * Measure write gate: entity read plus measure edit (Engine Measure::checkModifyPermissionEntity).
	 */
	public function ensureMeasureModify(): Result
	{
		$result = $this->ensureEntityRead();
		if (!$result->isSuccess())
		{
			return $result;
		}

		if (!$this->getAccessController()->check(ActionDictionary::ACTION_MEASURE_EDIT))
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	public function ensureStoreViewAction(): Result
	{
		$result = new Result();
		if (!$this->getAccessController()->check(ActionDictionary::ACTION_STORE_VIEW))
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	/**
	 * Trade catalog read: settings view operation of the main module
	 * plus catalog read OR catalog settings action.
	 */
	public function ensureTradeCatalogRead(): Result
	{
		$result = new Result();
		$user = CurrentUser::get();
		$accessController = $this->getAccessController();

		$hasAccess =
			($user->canDoOperation('view_other_settings') || $user->canDoOperation('edit_other_settings'))
			&& (
				$accessController->check(ActionDictionary::ACTION_CATALOG_READ)
				|| $accessController->check(ActionDictionary::ACTION_CATALOG_SETTINGS_ACCESS)
			)
		;

		if (!$hasAccess)
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	public function ensureTradeCatalogModify(): Result
	{
		$result = $this->ensureTradeCatalogRead();
		if (!$result->isSuccess())
		{
			return $result;
		}

		if (!$this->getAccessController()->check(ActionDictionary::ACTION_CATALOG_SETTINGS_ACCESS))
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	/**
	 * Section entity read gate: catalog read OR catalog view.
	 */
	public function ensureSectionEntityRead(): Result
	{
		$result = new Result();
		$accessController = $this->getAccessController();

		if (
			!$accessController->check(ActionDictionary::ACTION_CATALOG_READ)
			&& !$accessController->check(ActionDictionary::ACTION_CATALOG_VIEW)
		)
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	public function ensureSectionAdd(): Result
	{
		return $this->ensureSectionAction(ActionDictionary::ACTION_PRODUCT_ADD);
	}

	public function ensureSectionUpdate(): Result
	{
		return $this->ensureSectionAction(ActionDictionary::ACTION_PRODUCT_EDIT);
	}

	public function ensureSectionDelete(): Result
	{
		return $this->ensureSectionAction(ActionDictionary::ACTION_PRODUCT_DELETE);
	}

	/**
	 * Iblock rights bridge: `list` is gated the same way as `get`, by `section_read`.
	 *
	 * Section id 0 asks for the right on the whole iblock, which extended rights may never
	 * grant: there `section_read` lives on a section or a subtree. An iblock level denial is
	 * therefore not final, the gate then asks whether any section of the iblock is readable.
	 * That probe runs the very filter \CIBlockSection::GetList applies to every row it returns,
	 * so the gate allows exactly the requests the domain would answer with at least one section.
	 */
	public function ensureIblockSectionList(int $iblockId): Result
	{
		$result = $this->ensureIblockSectionRight($iblockId, 0, self::IBLOCK_SECTION_READ);
		if ($result->isSuccess() || $this->hasReadableSection($iblockId))
		{
			return new Result();
		}

		return $result;
	}

	/**
	 * @see \CIBlockSection::_check_rights_sql the row level filter this probe reuses
	 */
	private function hasReadableSection(int $iblockId): bool
	{
		if ($iblockId <= 0)
		{
			return false;
		}

		$iterator = CIBlockSection::GetList(
			[],
			[
				'IBLOCK_ID' => $iblockId,
				'CHECK_PERMISSIONS' => 'Y',
				'MIN_PERMISSION' => CIBlockRights::PUBLIC_READ,
			],
			false,
			['ID'],
			['nTopCount' => 1],
		);

		return is_array($iterator->Fetch());
	}

	/**
	 * @param int|null $iblockId iblock of the section when the caller has already read the row
	 */
	public function ensureIblockSectionGet(int $sectionId, ?int $iblockId = null): Result
	{
		return $this->ensureIblockSectionRight(
			$iblockId ?? $this->resolveIblockIdBySection($sectionId),
			$sectionId,
			self::IBLOCK_SECTION_READ,
		);
	}

	public function ensureIblockSectionAdd(int $iblockId): Result
	{
		return $this->ensureIblockSectionRight($iblockId, 0, self::IBLOCK_SECTION_EDIT);
	}

	/**
	 * @param int|null $iblockId iblock of the section when the caller has already read the row
	 */
	public function ensureIblockSectionUpdate(int $sectionId, ?int $iblockId = null): Result
	{
		return $this->ensureIblockSectionRight(
			$iblockId ?? $this->resolveIblockIdBySection($sectionId),
			$sectionId,
			self::IBLOCK_SECTION_EDIT,
		);
	}

	/**
	 * @param int|null $iblockId iblock of the section when the caller has already read the row
	 */
	public function ensureIblockSectionDelete(int $sectionId, ?int $iblockId = null): Result
	{
		return $this->ensureIblockSectionRight(
			$iblockId ?? $this->resolveIblockIdBySection($sectionId),
			$sectionId,
			self::IBLOCK_SECTION_DELETE,
		);
	}

	/**
	 * Rights to bind a section to the given parent section.
	 */
	public function ensureIblockSectionBind(int $parentSectionId): Result
	{
		return $this->ensureIblockSectionRight(
			$this->resolveIblockIdBySection($parentSectionId),
			$parentSectionId,
			self::IBLOCK_SECTION_BIND,
		);
	}

	/**
	 * Extra entity read gate: catalog read OR price edit OR extra edit (Engine Extra::checkReadPermissionEntity).
	 */
	public function ensureExtraRead(): Result
	{
		$result = new Result();
		$accessController = $this->getAccessController();

		if (
			!$accessController->check(ActionDictionary::ACTION_CATALOG_READ)
			&& !$accessController->check(ActionDictionary::ACTION_PRICE_EDIT)
			&& !$accessController->check(ActionDictionary::ACTION_PRODUCT_PRICE_EXTRA_EDIT)
		)
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	public function getAccessController(): AccessController
	{
		$userId = $this->userId ?? (int)CurrentUser::get()->getId();

		return AccessController::getInstance($userId);
	}

	private function accessDeniedError(): Error
	{
		return new Error('Access denied', RestErrorCode::ACCESS_DENIED);
	}

	private function ensureSectionAction(string $action): Result
	{
		$result = $this->ensureSectionEntityRead();
		if (!$result->isSuccess())
		{
			return $result;
		}

		if (!$this->getAccessController()->check($action))
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	/**
	 * Section id 0 falls back to the iblock level rights.
	 * @see \CIBlockSectionRights::UserHasRightTo
	 */
	private function ensureIblockSectionRight(int $iblockId, int $sectionId, string $operation): Result
	{
		$result = new Result();
		if (
			!CIBlockSectionRights::UserHasRightTo($iblockId, $sectionId, $operation)
			|| $this->isIblockHiddenAsInactive($iblockId)
		)
		{
			$result->addError($this->accessDeniedError());
		}

		return $result;
	}

	/**
	 * Missing section resolves to iblock 0: a non admin is denied before the entity is looked up.
	 */
	/**
	 * In the simple rights mode \CIBlockSection::GetList hides the sections of an inactive iblock
	 * from everyone below full access, while \CIBlockSectionRights::UserHasRightTo ignores the
	 * activity. Without this the gate lets a write through that the domain read cannot see after it.
	 *
	 * @see \CIBlockSection::_check_rights_sql
	 */
	private function isIblockHiddenAsInactive(int $iblockId): bool
	{
		if ($iblockId <= 0 || defined('ADMIN_SECTION'))
		{
			return false;
		}

		$iblock = CIBlock::GetArrayByID($iblockId);
		if (
			!is_array($iblock)
			|| $iblock['ACTIVE'] === 'Y'
			|| $iblock['RIGHTS_MODE'] === IblockTable::RIGHTS_EXTENDED
		)
		{
			return false;
		}

		return CIBlock::GetPermission($iblockId) !== CIBlockRights::FULL_ACCESS;
	}

	private function resolveIblockIdBySection(int $sectionId): int
	{
		if ($sectionId <= 0)
		{
			return 0;
		}

		$row = SectionTable::getRow([
			'select' => ['IBLOCK_ID'],
			'filter' => ['=ID' => $sectionId],
		]);

		return (int)($row['IBLOCK_ID'] ?? 0);
	}

	private function resolveStoreDocumentModel(?int $entityId, ?string $docType): StoreDocument
	{
		if ($entityId !== null && $entityId > 0)
		{
			return StoreDocument::createFromId($entityId);
		}

		return StoreDocument::createFromArray(['DOC_TYPE' => $docType]);
	}
}
