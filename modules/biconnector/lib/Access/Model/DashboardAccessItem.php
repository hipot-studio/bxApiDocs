<?php

namespace Bitrix\BIConnector\Access\Model;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\Main\Access\AccessibleItem;

final class DashboardAccessItem implements AccessibleItem
{
	private int $id;
	private ?string $type = null;
	private ?int $ownerId = null;

	/**
	 * @param int $id
	 */
	public function __construct(int $id)
	{
		$this->id = $id;
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getType(): ?string
	{
		return $this->type;
	}

	public function getOwnerId(): ?int
	{
		return $this->ownerId;
	}

	public static function createFromId(int $itemId): DashboardAccessItem
	{
		$dashboard = SupersetDashboardTable::getById($itemId)->fetchObject();
		$item = new static($itemId);
		if ($dashboard)
		{
			$item->type = $dashboard->getType();
			$item->ownerId = $dashboard->getOwnerId();
		}

		return $item;
	}

	/**
	 * Creates Dashboard object to use in Access check.
	 *
	 * @param array $fields Fields: ID, TYPE, OWNER_ID.
	 *
	 * @return DashboardAccessItem
	 */
	public static function createFromArray(array $fields): DashboardAccessItem
	{
		$dashboard = new static(
			(int)($fields['ID'] ?? 0)
		);
		$dashboard->type = $fields['TYPE'] ?? null;
		$dashboard->ownerId = $fields['OWNER_ID'] ?? null;

		return $dashboard;
	}
}
