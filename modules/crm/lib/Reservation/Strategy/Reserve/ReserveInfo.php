<?php

namespace Bitrix\Crm\Reservation\Strategy\Reserve;

use Bitrix\Main\Type\DateTime;

/**
 * Information of reservation.
 */
class ReserveInfo
{
	private float $reserveQuantity;
	/**
	 * The difference between the old and the new quantity of reserve.
	 * It can be negative if the reserve has been withdrawn.
	 *
	 * @var float
	 */
	private float $deltaReserveQuantity;
	private ?int $storeId = null;
	private DateTime $dateReserveEnd;
	private bool $changed = false;

	/**
	 * @param float $reserveQuantity
	 * @param float $deltaReserveQuantity
	 */
	public function __construct(
		float $reserveQuantity,
		float $deltaReserveQuantity
	)
	{
		$this->setReserveQuantity($reserveQuantity);
		$this->setDeltaReserveQuantity($deltaReserveQuantity);
	}

	/**
	 * Reserved quantity.
	 *
	 * @param float $reserveQuantity
	 *
	 * @return void
	 */
	public function setReserveQuantity(float $reserveQuantity): void
	{
		$this->reserveQuantity = $reserveQuantity;
	}

	/**
	 * Reserved quantity.
	 *
	 * @return float
	 */
	public function getReserveQuantity(): float
	{
		return $this->reserveQuantity;
	}

	/**
	 * The difference between the old and the new quantity of reserve.
	 *
	 * @param float $deltaReserveQuantity
	 *
	 * @return void
	 */
	public function setDeltaReserveQuantity(float $deltaReserveQuantity): void
	{
		$this->deltaReserveQuantity = $deltaReserveQuantity;
	}

	/**
	 * The difference between the old and the new quantity of reserve.
	 *
	 * @return float
	 */
	public function getDeltaReserveQuantity(): float
	{
		return $this->deltaReserveQuantity;
	}

	/**
	 * Store.
	 *
	 * @param int|null $storeId
	 *
	 * @return void
	 */
	public function setStoreId(?int $storeId): void
	{
		$this->storeId = $storeId;
	}

	/**
	 * Store.
	 *
	 * @return int|null
	 */
	public function getStoreId(): ?int
	{
		return $this->storeId;
	}

	/**
	 * Date end of reserve.
	 *
	 * @return DateTime|null
	 * *@see getDateReserveEnd
	 *
	 * @deprecated
	 */
	public function getDateReserveEndAsDateTime(): ?DateTime
	{
		return $this->dateReserveEnd ?? null
		;
	}

	/**
	 * Set date end of reserve.
	 *
	 * @param DateTime $dateReserveEnd
	 * @return void
	 */
	public function setDateReserveEnd(DateTime $dateReserveEnd): void
	{
		$this->dateReserveEnd = $dateReserveEnd;
	}

	/**
	 * Returns date end of reserve.
	 *
	 * @return DateTime
	 */
	public function getDateReserveEnd(): DateTime
	{
		return $this->dateReserveEnd;
	}

	/**
	 * Mark reserve info as changed.
	 *
	 * @param bool $value
	 *
	 * @return void
	 */
	public function setChanged(bool $value = true): void
	{
		$this->changed = $value;
	}

	/**
	 * Check is changed reserve info.
	 *
	 * @return bool
	 */
	public function isChanged(): bool
	{
		if ($this->changed)
		{
			return true;
		}

		return $this->deltaReserveQuantity !== 0.0;
	}
}
