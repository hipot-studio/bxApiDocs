<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Integration\Im\Notification;
use Bitrix\Mail\MailboxTable;

final class OrphanedMailboxLifecycle
{
	public const NOTIFY_BEFORE_DAYS = 7;
	public const DISCONNECT_AFTER_DAYS = 30;

	private const OPTION_FIRED_AT = 'fired_owner_at';
	private const OPTION_NOTIFIED_7D_AT = 'fired_owner_notified_7d_at';

	/**
	 * A mailbox is "corporate" iff it can survive its owner leaving the company.
	 *
	 * - controller / crdomain / domain — managed by Bitrix or the company domain,
	 *   the box can be reassigned to another user.
	 * - imap with login/password — typically a company server; credentials can be
	 *   handed over.
	 * - imap with OAuth — the auth token belongs to the leaving user; not corporate.
	 * - empty PASSWORD or unknown SERVER_TYPE — fail safe, not corporate.
	 */
	public static function isCorporate(array $mailbox): bool
	{
		$serverType = mb_strtolower((string)($mailbox['SERVER_TYPE'] ?? ''));

		if (in_array($serverType, ['controller', 'crdomain', 'domain'], true))
		{
			return true;
		}

		if ($serverType !== 'imap')
		{
			return false;
		}

		$password = (string)($mailbox['PASSWORD'] ?? '');
		if ($password === '')
		{
			return false;
		}

		return OAuth::parseMeta($password) === null;
	}

	public static function isOrphan(array $mailbox): bool
	{
		if (!Feature::isMailboxOwnerChangeAvailable())
		{
			return false;
		}

		if (!self::isCorporate($mailbox))
		{
			return false;
		}

		return (int)(($mailbox['OPTIONS'] ?? [])[self::OPTION_FIRED_AT] ?? 0) > 0;
	}

	public static function handleUserFired(int $userId): void
	{
		if (!Feature::isMailboxOwnerChangeAvailable())
		{
			self::handleUserDeactivated($userId);

			return;
		}

		foreach (self::fetchUserMailboxes($userId) as $mailbox)
		{
			if (self::isCorporate($mailbox))
			{
				self::markFired((int)$mailbox['ID'], $mailbox);
				continue;
			}

			if ($mailbox['ACTIVE'] === 'Y')
			{
				self::deactivate((int)$mailbox['ID']);
			}
		}
	}

	public static function handleUserDeactivated(int $userId): void
	{
		foreach (self::fetchUserMailboxes($userId) as $mailbox)
		{
			if ($mailbox['ACTIVE'] === 'Y')
			{
				self::deactivate((int)$mailbox['ID']);
			}
		}
	}

	public static function handleUserReactivated(int $userId): void
	{
		if (!Feature::isMailboxOwnerChangeAvailable())
		{
			return;
		}

		foreach (self::fetchUserMailboxes($userId) as $mailbox)
		{
			if (!self::isCorporate($mailbox))
			{
				continue;
			}

			$options = (array)($mailbox['OPTIONS'] ?? []);
			if (!isset($options[self::OPTION_FIRED_AT]) && !isset($options[self::OPTION_NOTIFIED_7D_AT]))
			{
				continue;
			}

			unset($options[self::OPTION_FIRED_AT], $options[self::OPTION_NOTIFIED_7D_AT]);
			MailboxTable::update((int)$mailbox['ID'], ['OPTIONS' => $options]);
		}
	}

	public static function tick(int $mailboxId): void
	{
		if (!Feature::isMailboxOwnerChangeAvailable())
		{
			return;
		}

		$mailbox = MailboxTable::query()
			->setSelect(['ID', 'EMAIL', 'SERVER_TYPE', 'PASSWORD', 'OPTIONS', 'ACTIVE'])
			->where('ID', $mailboxId)
			->setLimit(1)
			->fetch()
		;

		if (!$mailbox || !self::isCorporate($mailbox))
		{
			return;
		}

		$firedAt = (int)(($mailbox['OPTIONS'] ?? [])[self::OPTION_FIRED_AT] ?? 0);
		if ($firedAt <= 0)
		{
			return;
		}

		$elapsedSeconds = time() - $firedAt;
		$disconnectAfterSeconds = self::DISCONNECT_AFTER_DAYS * 86400;

		if ($elapsedSeconds >= $disconnectAfterSeconds)
		{
			MailboxTable::update($mailboxId, ['ACTIVE' => 'N']);

			return;
		}

		$notifyAfterSeconds = ($disconnectAfterSeconds - self::NOTIFY_BEFORE_DAYS * 86400);
		$notifiedAt = (int)(($mailbox['OPTIONS'] ?? [])[self::OPTION_NOTIFIED_7D_AT] ?? 0);

		if ($elapsedSeconds >= $notifyAfterSeconds && $notifiedAt <= 0)
		{
			Notification::dispatchOrphanedMailboxAutoDisconnectNotifications(
				$mailboxId,
				(string)$mailbox['EMAIL'],
			);

			$options = (array)($mailbox['OPTIONS'] ?? []);
			$options[self::OPTION_NOTIFIED_7D_AT] = time();
			MailboxTable::update($mailboxId, ['OPTIONS' => $options]);
		}
	}

	private static function fetchUserMailboxes(int $userId): array
	{
		return MailboxTable::query()
			->setSelect(['ID', 'USER_ID', 'ACTIVE', 'SERVER_TYPE', 'PASSWORD', 'OPTIONS', 'EMAIL'])
			->where('USER_ID', $userId)
			->fetchAll()
		;
	}

	private static function markFired(int $mailboxId, array $mailbox): void
	{
		$options = (array)($mailbox['OPTIONS'] ?? []);
		if ((int)($options[self::OPTION_FIRED_AT] ?? 0) > 0)
		{
			return;
		}

		$options[self::OPTION_FIRED_AT] = time();
		MailboxTable::update($mailboxId, ['OPTIONS' => $options]);
	}

	private static function deactivate(int $mailboxId): void
	{
		MailboxTable::update($mailboxId, ['ACTIVE' => 'N']);
	}
}
