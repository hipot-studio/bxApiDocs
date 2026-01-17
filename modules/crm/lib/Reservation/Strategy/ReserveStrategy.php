<?php

declare(strict_types = 1);

namespace Bitrix\Crm\Reservation\Strategy;

use Bitrix\Crm\ProductRowTable;
use Bitrix\Crm\Reservation\Internals\ProductRowReservationTable;
use Bitrix\Main\ORM\Data\Result;
use Bitrix\Main\Type\DateTime;

abstract class ReserveStrategy implements Strategy
{

	protected int $defaultStoreId;
	protected DateTime $defaultDateReserveEnd;

	/**
	 * Get product row.
	 *
	 * @param int $rowId
	 * @return array|null
	 */
	protected function getProductRow(int $rowId): ?array
	{
		return ProductRowTable::getRow([
			'select' => [
				'ID',
				'QUANTITY',
				'TYPE',
				'PRODUCT_ID',
			],
			'filter' => [
				'=ID' => $rowId,
			],
		]);
	}

	/**
	 * Get reserve by row id.
	 *
	 * @param int $productRowId
	 * @return array|null
	 */
	protected function getReserve(int $productRowId): ?array
	{
		return ProductRowReservationTable::getRow([
			'select' => [
				'ID',
				'IS_AUTO',
				'STORE_ID',
				'RESERVE_QUANTITY',
				'DATE_RESERVE_END',
			],
			'filter' => [
				'=ROW_ID' => $productRowId,
			],
		]);
	}

	/**
	 * Save reserve.
	 *
	 * @param array $fields
	 * @return Result
	 */
	protected function saveCrmReserve(array $fields): Result
	{
		$id = $fields['ID'] ?? null;
		if (isset($id))
		{
			unset($fields['ID']);

			return ProductRowReservationTable::update($id, $fields);
		}

		return ProductRowReservationTable::add($fields);
	}

	public function setDefaultStoreId(int $storeId): static
	{
		$this->defaultStoreId = $storeId;

		return $this;
	}

	public function getDefaultStoreId(): int
	{
		return $this->defaultStoreId;
	}

	public function setDefaultDateReserveEnd(DateTime $dateReserveEnd): static
	{
		$this->defaultDateReserveEnd = $dateReserveEnd;

		return $this;
	}

	public function getDefaultDateReserveEnd(): DateTime
	{
		return $this->defaultDateReserveEnd;
	}
}
