<?php

namespace Bitrix\Mail\Access\Model;

use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Access\AccessibleItem;

final class MailboxModel implements AccessibleItem
{
	private static array $cache = [];

	private int $id = 0;
	private int $ownerId = 0;

	public static function createFromId(int $itemId): self
	{
		if (isset(self::$cache[$itemId]))
		{
			return self::$cache[$itemId];
		}

		$model = new self();
		if ($mailbox = MailboxTable::getById($itemId)->fetch())
		{
			$model->setId($itemId);
			$model->setOwnerId((int)($mailbox['USER_ID'] ?? 0));
			self::$cache[$itemId] = $model;
		}

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

	private function setOwnerId(int $ownerId): void
	{
		$this->ownerId = $ownerId;
	}

	public function getOwnerId(): int
	{
		return $this->ownerId;
	}
}
