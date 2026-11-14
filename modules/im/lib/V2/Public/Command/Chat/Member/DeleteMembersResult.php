<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Command\Chat\Member;

use Bitrix\Im\V2\Result;

final class DeleteMembersResult extends Result
{
	private array $deletedUserIds = [];

	/**
	 * @param int[] $deletedUserIds
	 */
	public function setDeletedUserIds(array $deletedUserIds): self
	{
		$this->deletedUserIds = array_values($deletedUserIds);

		return $this;
	}

	/**
	 * @return int[] users whose relation with the chat is gone after the command
	 */
	public function getDeletedUserIds(): array
	{
		return $this->deletedUserIds;
	}
}
