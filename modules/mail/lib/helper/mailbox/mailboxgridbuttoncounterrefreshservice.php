<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Mailbox;

use Bitrix\Mail\Access\MailAccessController;
use Bitrix\Mail\Access\MailActionDictionary;
use Bitrix\Mail\Helper\Enum\MailboxStatus;
use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;

class MailboxGridButtonCounterRefreshService
{
	public const PULL_COMMAND = 'mailbox_grid_button_counter_refresh';
	public const PULL_TAG = 'mail_mailbox_grid_counter';

	public function sendForMailbox(int $mailboxId): void
	{
		$ownerId = $this->getActiveMailboxOwnerId($mailboxId);
		if ($ownerId === null || !$this->isPullAvailable())
		{
			return;
		}

		$message = $this->getPullMessage();

		// Owner tabs that do not watch the tag still have to hear about their own mailbox.
		if ($ownerId > 0 && $this->hasMailboxGridAccess($ownerId))
		{
			$this->addUsersEvent([$ownerId], $message);
		}

		$this->addTagEvent(self::PULL_TAG, $message);
	}

	/**
	 * @param int[] $userIds
	 */
	public function sendToUsers(array $userIds): void
	{
		$userIds = $this->normalizeIds($userIds);
		if (empty($userIds) || !$this->isPullAvailable())
		{
			return;
		}

		$this->addUsersEvent($userIds, $this->getPullMessage());
	}

	/**
	 * Subscribes the current user to the tag of the mailbox grid button counter.
	 *
	 * @return string|null the tag when the subscription is set, null otherwise
	 */
	public function watchForCurrentUser(): ?string
	{
		$userId = $this->getCurrentUserId();
		if ($userId <= 0 || !$this->canCurrentUserWatch() || !$this->isPullAvailable())
		{
			return null;
		}

		$this->addWatch($userId, self::PULL_TAG);

		return self::PULL_TAG;
	}

	protected function getActiveMailboxOwnerId(int $mailboxId): ?int
	{
		$mailbox = MailboxTable::getRow([
			'select' => ['USER_ID'],
			'filter' => [
				'=ID' => $mailboxId,
				'=ACTIVE' => MailboxStatus::Active->value,
			],
		]);

		return is_array($mailbox) ? (int)$mailbox['USER_ID'] : null;
	}

	protected function hasMailboxGridAccess(int $userId): bool
	{
		return MailAccessController::can($userId, MailActionDictionary::ACTION_MAILBOX_LIST_VIEW);
	}

	protected function canCurrentUserWatch(): bool
	{
		return MailAccess::hasCurrentUserAccessToMailboxGrid();
	}

	protected function getCurrentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	protected function addWatch(int $userId, string $tag): void
	{
		\CPullWatch::Add($userId, $tag);
	}

	protected function isPullAvailable(): bool
	{
		return Loader::includeModule('pull');
	}

	/**
	 * @param int[] $userIds
	 */
	protected function addUsersEvent(array $userIds, array $message): void
	{
		\Bitrix\Pull\Event::add($userIds, $message);
	}

	protected function addTagEvent(string $tag, array $message): void
	{
		\CPullWatch::AddToStack($tag, $message);
	}

	private function getPullMessage(): array
	{
		return [
			'module_id' => 'mail',
			'command' => self::PULL_COMMAND,
			'params' => [],
		];
	}

	/**
	 * @param array<int|string> $ids
	 * @return int[]
	 */
	private function normalizeIds(array $ids): array
	{
		$normalizedIds = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$normalizedIds[] = $id;
			}
		}

		$normalizedIds = array_values(array_unique($normalizedIds));
		sort($normalizedIds);

		return $normalizedIds;
	}
}
