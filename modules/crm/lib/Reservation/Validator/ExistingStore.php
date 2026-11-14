<?php

declare(strict_types=1);

namespace Bitrix\Crm\Reservation\Validator;

use Bitrix\Catalog\StoreTable;
use Bitrix\Crm\ProductRow;
use Bitrix\Crm\ProductRowCollection;
use Bitrix\Crm\Reservation\Error\BaseProductErrorAssembler;
use Bitrix\Crm\Reservation\Internals\ProductRowReservationTable;
use Bitrix\Main;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Objectify\Values;
use Bitrix\Main\Result;
use Bitrix\Sale\Configuration;

/**
 * A reserve above zero may be written only to a store that exists. A reserve that is not new and keeps
 * its quantity, store and row quantity is left alone, so an old reserve on a vanished store does not
 * block the rest of the save; taking a reserve off (zero or row removal) is always allowed.
 */
final class ExistingStore extends BaseValidator
{
	public const ERROR_CODE = 'ERROR_RESERVE_STORE_NOT_EXISTS';

	private const NAME_TEMPLATE = '#NAME#';

	protected function initErrorAssembler(): void
	{
		parent::initErrorAssembler();

		$assembler = $this->getErrorAssembler();
		$assembler->setErrorCode(self::ERROR_CODE);
		$assembler->setNameTemplate(self::NAME_TEMPLATE);
		$assembler->setErrorTemplates([
			BaseProductErrorAssembler::TEMPLATE_PRODUCT_ONE =>
				Loc::getMessage('CRM_RESERVATION_ERROR_EXISTING_STORE_ERROR_ONE_PRODUCT'),
			BaseProductErrorAssembler::TEMPLATE_PRODUCT_MULTI =>
				Loc::getMessage('CRM_RESERVATION_ERROR_EXISTING_STORE_ERROR_MULTI_PRODUCTS'),
			BaseProductErrorAssembler::TEMPLATE_PRODUCT_TOO_MANY =>
				Loc::getMessage('CRM_RESERVATION_ERROR_EXISTING_STORE_ERROR_TOO_MANY_PRODUCTS'),
		]);
		unset(
			$assembler,
		);
	}

	public function validateCollection(?ProductRowCollection $collection): Result
	{
		if ($collection === null)
		{
			return new Result();
		}

		$candidates = [];
		/** @var ProductRow $productRow */
		foreach ($collection as $productRow)
		{
			$reservation = $productRow->getProductRowReservation();
			if (!$reservation)
			{
				continue;
			}

			$reserve = (float)$reservation->getReserveQuantity();
			if ($reserve <= 0)
			{
				continue;
			}

			$candidates[] = [
				'ROW_ID' => $productRow->isNew() ? 0 : (int)$productRow->getId(),
				'PRODUCT_ID' => (int)$productRow->getProductId(),
				'PRODUCT_NAME' => (string)$productRow->getProductName(),
				'RESERVE_QUANTITY' => $reserve,
				'STORE_ID' => (int)$reservation->getStoreId(),
				'IS_RESERVE_CHANGED' => $this->isReserveChanged($reservation->collectValues(Values::CURRENT)),
				'IS_QUANTITY_CHANGED' => array_key_exists('QUANTITY', $productRow->collectValues(Values::CURRENT)),
			];
		}

		return $this->validateCandidates($candidates);
	}

	/**
	 * @param array $currentRows rows to be saved
	 * @param array $actualRows rows as they are stored, matched to $currentRows by key or by ID
	 */
	public function validateRows(array $currentRows, array $actualRows): Result
	{
		if (empty($currentRows))
		{
			return new Result();
		}

		$actualRowsById = [];
		foreach ($actualRows as $actualRow)
		{
			$id = (int)($actualRow['ID'] ?? 0);
			if ($id > 0)
			{
				$actualRowsById[$id] = $actualRow;
			}
		}

		$productNames = [];
		$storeIdsByKey = [];
		foreach ($currentRows as $key => $currentRow)
		{
			$id = (int)($currentRow['ID'] ?? 0);
			$previousRow = $actualRows[$key] ?? ($id > 0 ? ($actualRowsById[$id] ?? null) : null);

			if (!$this->isRowToCheck($currentRow, $previousRow))
			{
				continue;
			}

			$storeId = $this->resolveStoreId((int)($currentRow['STORE_ID'] ?? $previousRow['STORE_ID'] ?? 0));
			if ($storeId <= 0)
			{
				continue;
			}

			$storeIdsByKey[$key] = $storeId;
			$productNames[$key] = $this->getRowProductName($currentRow, $previousRow);
		}

		$storeIdsByKey = $this->filterMissingStores($storeIdsByKey);

		return $this->getValidationResult(array_intersect_key($productNames, $storeIdsByKey));
	}

	/**
	 * @param int[] $storeIds
	 * @return int[] ids of the given stores that do not exist; activity and access rights are not taken into account
	 */
	public static function getMissingStoreIds(array $storeIds): array
	{
		$storeIds = array_values(array_unique(array_filter(
			array_map('intval', $storeIds),
			static fn(int $storeId): bool => $storeId > 0,
		)));
		if (empty($storeIds) || !Loader::includeModule('catalog'))
		{
			return [];
		}

		$missing = array_fill_keys($storeIds, true);
		foreach (array_chunk($storeIds, 500) as $pageIds)
		{
			$iterator = StoreTable::getList([
				'select' => ['ID'],
				'filter' => ['@ID' => $pageIds],
			]);
			while ($row = $iterator->fetch())
			{
				unset($missing[(int)$row['ID']]);
			}
		}

		return array_keys($missing);
	}

	/**
	 * @param string[] $productNames
	 */
	public function createError(array $productNames): Main\Error
	{
		$assembler = $this->getErrorAssembler();
		$assembler->setProductNames(array_values($productNames));

		return $assembler->getError();
	}

	private function validateCandidates(array $candidates): Result
	{
		$storeIdsByKey = [];
		$productNames = [];
		foreach ($candidates as $key => $candidate)
		{
			$storeId = $this->resolveStoreId($candidate['STORE_ID']);
			if ($storeId <= 0)
			{
				continue;
			}
			$storeIdsByKey[$key] = $storeId;
			$productNames[$key] = $candidate['PRODUCT_NAME'] !== ''
				? $candidate['PRODUCT_NAME']
				: (string)$candidate['PRODUCT_ID']
			;
		}

		$storeIdsByKey = $this->filterMissingStores($storeIdsByKey);
		$storedReserves = null;
		foreach (array_keys($storeIdsByKey) as $key)
		{
			$candidate = $candidates[$key];
			if ($candidate['ROW_ID'] <= 0 || $candidate['IS_QUANTITY_CHANGED'])
			{
				continue;
			}
			if (!$candidate['IS_RESERVE_CHANGED'])
			{
				unset($storeIdsByKey[$key]);

				continue;
			}

			// The reserve object may come without its stored values, so the change is checked against the storage
			$storedReserves ??= $this->loadStoredReserves(array_column($candidates, 'ROW_ID'));
			$stored = $storedReserves[$candidate['ROW_ID']] ?? null;
			if (
				$stored !== null
				&& (float)$stored['RESERVE_QUANTITY'] === $candidate['RESERVE_QUANTITY']
				&& (int)$stored['STORE_ID'] === $candidate['STORE_ID']
			)
			{
				unset($storeIdsByKey[$key]);
			}
		}

		return $this->getValidationResult(array_intersect_key($productNames, $storeIdsByKey));
	}

	/**
	 * @param array<int|string, int> $storeIdsByKey
	 * @return array<int|string, int> only the entries whose store does not exist
	 */
	private function filterMissingStores(array $storeIdsByKey): array
	{
		if (empty($storeIdsByKey))
		{
			return [];
		}

		$missingStoreIds = array_fill_keys(self::getMissingStoreIds($storeIdsByKey), true);

		return array_filter(
			$storeIdsByKey,
			static fn(int $storeId): bool => isset($missingStoreIds[$storeId]),
		);
	}

	private function getValidationResult(array $productNames): Result
	{
		$result = new Result();
		if (!empty($productNames))
		{
			$result->addError($this->createError($productNames));
		}

		return $result;
	}

	private function isReserveChanged(array $currentReserveValues): bool
	{
		return
			array_key_exists('RESERVE_QUANTITY', $currentReserveValues)
			|| array_key_exists('STORE_ID', $currentReserveValues)
		;
	}

	private function isRowToCheck(array $currentRow, ?array $previousRow): bool
	{
		$reserve = $this->getRowReserve($currentRow);
		if ($reserve === null)
		{
			if ($previousRow === null || !array_key_exists('STORE_ID', $currentRow))
			{
				return false;
			}
			$reserve = (float)$this->getRowReserve($previousRow);
		}
		if ($reserve <= 0)
		{
			return false;
		}

		if ($previousRow === null)
		{
			return true;
		}

		if ($reserve !== (float)$this->getRowReserve($previousRow))
		{
			return true;
		}

		if (
			array_key_exists('STORE_ID', $currentRow)
			&& (int)$currentRow['STORE_ID'] !== (int)($previousRow['STORE_ID'] ?? 0)
		)
		{
			return true;
		}

		return
			array_key_exists('QUANTITY', $currentRow)
			&& (float)$currentRow['QUANTITY'] !== (float)($previousRow['QUANTITY'] ?? 0)
		;
	}

	private function getRowReserve(array $row): ?float
	{
		$reserve = $row['INPUT_RESERVE_QUANTITY'] ?? $row['RESERVE_QUANTITY'] ?? null;

		return $reserve === null ? null : (float)$reserve;
	}

	private function getRowProductName(array $currentRow, ?array $previousRow): string
	{
		$name = (string)($currentRow['PRODUCT_NAME'] ?? $previousRow['PRODUCT_NAME'] ?? '');
		if ($name !== '')
		{
			return $name;
		}

		return (string)($currentRow['PRODUCT_ID'] ?? $previousRow['PRODUCT_ID'] ?? '');
	}

	private function resolveStoreId(int $storeId): int
	{
		if ($storeId > 0)
		{
			return $storeId;
		}

		return Loader::includeModule('sale') ? (int)Configuration::getDefaultStoreId() : 0;
	}

	/**
	 * @param int[] $rowIds
	 * @return array<int, array{RESERVE_QUANTITY: mixed, STORE_ID: mixed}> by row id
	 */
	private function loadStoredReserves(array $rowIds): array
	{
		$rowIds = array_values(array_unique(array_filter($rowIds, static fn(int $rowId): bool => $rowId > 0)));
		if (empty($rowIds))
		{
			return [];
		}

		$result = [];
		$iterator = ProductRowReservationTable::getList([
			'select' => ['ROW_ID', 'RESERVE_QUANTITY', 'STORE_ID'],
			'filter' => ['@ROW_ID' => $rowIds],
		]);
		while ($row = $iterator->fetch())
		{
			$result[(int)$row['ROW_ID']] = $row;
		}

		return $result;
	}
}
