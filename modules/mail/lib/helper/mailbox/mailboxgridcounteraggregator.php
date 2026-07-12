<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Mailbox;

class MailboxGridCounterAggregator
{
	public function getButtonCounter(int $userId): int
	{
		return $this->getConnectionRequestCount($userId) + $this->getProblemMailboxCount($userId);
	}

	public function getConnectionRequestCount(int $userId): int
	{
		return $this->fetchConnectionRequestCount($userId);
	}

	public function getProblemMailboxCount(int $userId): int
	{
		return count($this->fetchEditableProblemMailboxIds($userId));
	}

	protected function fetchConnectionRequestCount(int $userId): int
	{
		return (new MailboxConnectionRequestService($userId))->getPendingCount();
	}

	/**
	 * @return int[]
	 */
	protected function fetchEditableProblemMailboxIds(int $userId): array
	{
		return (new MailboxGridAudienceResolver($userId))->getEditableProblemMailboxIds();
	}
}
