<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Mailbox;

use Bitrix\Mail\Access\MailAccessController;
use Bitrix\Mail\Access\MailActionDictionary;
use Bitrix\Mail\Access\MailboxAccessController;
use Bitrix\Mail\Helper\Enum\MailboxStatus;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\UserTable;
use Bitrix\Main\Loader;

class MailboxGridButtonCounterRefreshService
{
	public const PULL_COMMAND = 'mailbox_grid_button_counter_refresh';

	public function sendForMailbox(int $mailboxId): void
	{
		$this->sendToUsers($this->getRefreshRecipientUserIds($mailboxId));
	}

	/**
	 * @param int[] $userIds
	 */
	public function sendToUsers(array $userIds): void
	{
		$userIds = $this->normalizeIds($userIds);
		if (empty($userIds) || !Loader::includeModule('pull'))
		{
			return;
		}

		\Bitrix\Pull\Event::add($userIds, [
			'module_id' => 'mail',
			'command' => self::PULL_COMMAND,
			'params' => [],
		]);
	}

	/**
	 * @return int[]
	 */
	protected function getRefreshRecipientUserIds(int $mailboxId): array
	{
		if (!$this->isActiveMailbox($mailboxId))
		{
			return [];
		}

		$recipientUserIds = [];
		foreach ($this->getActiveUserIds() as $userId)
		{
			if (
				$this->hasMailboxGridAccess($userId)
				&& $this->canEditMailbox($userId, $mailboxId)
			)
			{
				$recipientUserIds[] = $userId;
			}
		}

		return $recipientUserIds;
	}

	protected function hasMailboxGridAccess(int $userId): bool
	{
		return MailAccessController::can($userId, MailActionDictionary::ACTION_MAILBOX_LIST_VIEW);
	}

	protected function canEditMailbox(int $userId, int $mailboxId): bool
	{
		return MailboxAccessController::can(
			$userId,
			MailActionDictionary::ACTION_MAILBOX_LIST_ITEM_EDIT,
			$mailboxId,
		);
	}

	/**
	 * @return int[]
	 */
	protected function getActiveUserIds(): array
	{
		$userIds = [];
		$users = UserTable::query()
			->setSelect(['ID'])
			->where('ACTIVE', 'Y')
			->exec()
		;

		while ($user = $users->fetch())
		{
			$userIds[] = (int)($user['ID'] ?? 0);
		}

		return $this->normalizeIds($userIds);
	}

	protected function isActiveMailbox(int $mailboxId): bool
	{
		$mailbox = MailboxTable::getRow([
			'select' => ['ID'],
			'filter' => [
				'=ID' => $mailboxId,
				'=ACTIVE' => MailboxStatus::Active->value,
			],
		]);

		return is_array($mailbox);
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
