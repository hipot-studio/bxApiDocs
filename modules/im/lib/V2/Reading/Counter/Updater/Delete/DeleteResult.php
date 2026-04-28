<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter\Updater\Delete;

use Bitrix\Im\V2\Result;

class DeleteResult extends Result
{
	public function __construct(
		private readonly int $deletedCount = 0,
		private readonly ?array $chatIds = null,
	)
	{
		parent::__construct();
	}

	public function getDeletedCount(): int
	{
		return $this->deletedCount;
	}

	public function hasDeleted(): bool
	{
		return $this->deletedCount > 0;
	}

	/**
	 * @return int[]|null null means "all chats" (scope=all), array — specific affected chat IDs.
	 */
	public function getChatIds(): ?array
	{
		return $this->chatIds;
	}
}
