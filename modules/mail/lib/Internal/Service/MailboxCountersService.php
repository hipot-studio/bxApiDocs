<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\Service\ScheduledMessage\ScheduledMessageService;
use Bitrix\Main;

class MailboxCountersService
{
	public function __construct(
		private readonly ScheduledMessageService $scheduledMessageService = new ScheduledMessageService(),
	)
	{
	}

	public function getCountersForUser(int $userId, ?int $mailboxId = null): array
	{
		if ($userId <= 0)
		{
			return ['total' => 0, 'mailboxes' => [], 'folders' => null, 'scheduled' => 0, 'favoritesUnread' => 0];
		}

		$perMailbox = [];
		foreach (Message::getCountersForUserMailboxes($userId) as $id => $row)
		{
			$perMailbox[(int)$id] = (int)($row['UNSEEN'] ?? 0);
		}

		$folders = null;
		if ($mailboxId)
		{
			try
			{
				$mailboxHelper = Mailbox::createInstance($mailboxId);
				if ($mailboxHelper instanceof Mailbox)
				{
					$folders = $mailboxHelper->getDirsWithUnseenMailCounters();
				}
			}
			catch (Main\ObjectException)
			{
			}
		}

		// No label counters here: the left menu gets them from the labelCountersUpdated pull command.
		return [
			'total' => (int)\CUserCounter::GetValue($userId, 'mail_unseen'),
			'mailboxes' => $perMailbox,
			'folders' => $folders,
			'scheduled' => $this->getScheduledCount($userId, $mailboxId),
			'favoritesUnread' => $this->countFavoritesUnread($userId, $mailboxId),
		];
	}

	/**
	 * The section of scheduled letters lives inside one mailbox, so outside a mailbox there is no number to
	 * show: the menu of the all mail mode keeps the counter at zero instead of summing sections the reader
	 * has nowhere to open.
	 */
	private function getScheduledCount(int $userId, ?int $mailboxId): int
	{
		if ($mailboxId === null || $mailboxId <= 0 || !Feature::isScheduledMessageAvailable())
		{
			return 0;
		}

		$result = $this->scheduledMessageService->getSectionCount($userId, $mailboxId);

		return $result->isSuccess() ? (int)$result->getData()['count'] : 0;
	}

	/**
	 * The section lives behind the list improvements, so on a portal without them nothing is counted.
	 * The key keeps its place in the answer with a zero: the client reads it on every counters update.
	 */
	private function countFavoritesUnread(int $userId, ?int $mailboxId): int
	{
		if (!Feature::isMailListImprovementsAvailable())
		{
			return 0;
		}

		return (new FavoritesCountersService())->countUnread(
			$userId,
			$this->favoritesScope($userId, $mailboxId),
		);
	}

	/**
	 * The favorites section spans the screen: the current mailbox, or every mailbox of the user in the
	 * all mail mode. A mailbox id arrives from the request, so the scope is the intersection with the
	 * mailboxes the user may read - own and shared alike - and a foreign id counts nothing.
	 *
	 * MailboxAccess::resolveUserMailboxIds() resolves the same scope from the same source, but rejects a
	 * mailbox outside the access of the user by an exception; a counter answers zero and leaves the rest
	 * of the payload intact.
	 *
	 * @return int[]
	 */
	private function favoritesScope(int $userId, ?int $mailboxId): array
	{
		$allowedIds = array_map('intval', array_column(MailboxTable::getUserMailboxes($userId, true), 'ID'));

		if ((int)$mailboxId <= 0)
		{
			return $allowedIds;
		}

		return in_array((int)$mailboxId, $allowedIds, true) ? [(int)$mailboxId] : [];
	}
}
