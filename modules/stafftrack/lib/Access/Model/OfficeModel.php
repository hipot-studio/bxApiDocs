<?php

namespace Bitrix\StaffTrack\Access\Model;

use Bitrix\Main\Access\AccessibleItem;

class OfficeModel implements AccessibleItem
{
	private int $id = 0;

	public static function createFromId(int $itemId): AccessibleItem
	{
		$model = new self();
		$model->id = $itemId;

		return $model;
	}

	public function getId(): int
	{
		return $this->id;
	}
}
