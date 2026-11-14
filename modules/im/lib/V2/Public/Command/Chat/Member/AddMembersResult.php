<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Command\Chat\Member;

use Bitrix\Im\V2\Result;

final class AddMembersResult extends Result
{
	private array $addedUserIds = [];
	private array $notAddedUserIds = [];

	/**
	 * @param int[] $addedUserIds
	 */
	public function setAddedUserIds(array $addedUserIds): self
	{
		$this->addedUserIds = array_values($addedUserIds);

		return $this;
	}

	/**
	 * @return int[] users who became visible members of the chat by the command, including revealed hidden relations
	 */
	public function getAddedUserIds(): array
	{
		return $this->addedUserIds;
	}

	/**
	 * @param int[] $notAddedUserIds
	 */
	public function setNotAddedUserIds(array $notAddedUserIds): self
	{
		$this->notAddedUserIds = array_values($notAddedUserIds);

		return $this;
	}

	/**
	 * @return int[] requested users who were not visible members of the chat and did not become ones
	 */
	public function getNotAddedUserIds(): array
	{
		return $this->notAddedUserIds;
	}
}
