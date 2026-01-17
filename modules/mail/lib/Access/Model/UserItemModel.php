<?php

namespace Bitrix\Mail\Access\Model;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\UserTable;

final class UserItemModel implements AccessibleItem
{
	private static array $cache = [];

	private int $id = 0;

	public static function createFromId(int $itemId): self
	{
		if (isset(self::$cache[$itemId]))
		{
			return self::$cache[$itemId];
		}

		$model = new self();
		$model->setId($itemId);

		return $model;
	}

	private function setId(int $itemId): void
	{
		$this->id = $itemId;
	}

	public function getId(): int
	{
		return $this->id;
	}
}
