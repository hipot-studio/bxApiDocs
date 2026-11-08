<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Main;

use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\Internals\Service\Mailbox\EmailNormalizer;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\DB\MysqlCommonConnection;
use Bitrix\Main\Loader;
use Bitrix\Main\Mail\Internal\SenderTable;
use Bitrix\Main\Mail\Sender\AddressFilter;
use Bitrix\Main\Mail\Sender\UserSenderDataProvider;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;

class SenderProvider
{
	/**
	 * Returns sender addresses available to the user, normalized to a flat shape.
	 *
	 * @return array<int, array{email: string, name: string, sender: string, senderId: ?int, mailboxId: ?int}>
	 */
	public static function getAvailableSenders(int $userId): array
	{
		$rawSenders = method_exists(UserSenderDataProvider::class, 'getUserAvailableSenderIdentities')
			? UserSenderDataProvider::getUserAvailableSenderIdentities(userId: $userId)
			: UserSenderDataProvider::getUserAvailableSenders(userId: $userId)
		;

		$senders = [];
		foreach ($rawSenders as $sender)
		{
			$senders[] = [
				'email' => $sender['email'] ?? '',
				'name' => $sender['name'] ?? '',
				'sender' => $sender['formated'] ?? '',
				'senderId' => isset($sender['id']) ? (int)$sender['id'] : null,
				'mailboxId' => isset($sender['mailboxId']) ? (int)$sender['mailboxId'] : null,
			];
		}

		return $senders;
	}

	/**
	 * Returns typed sender candidates without collapsing equal formatted addresses.
	 *
	 * @param string[] $emails
	 * @return array<int, array{email: string, name: string, sender: string, type: string, mailboxId: int|null}>
	 */
	public static function getAvailableTypedSenders(int $userId, array $emails): array
	{
		$emails = array_values(array_unique(array_filter(array_map(
			static fn(mixed $email): string => mb_strtolower(trim((string)$email)),
			$emails,
		))));
		if ($emails === [])
		{
			return [];
		}

		$rawSenders = self::getRelevantMailboxSenders($userId, $emails);

		$senders = [];
		foreach ($rawSenders as $sender)
		{
			$email = mb_strtolower((string)($sender['email'] ?? ''));
			if (!in_array($email, $emails, true))
			{
				continue;
			}

			$type = (string)($sender['type'] ?? '');
			if (!in_array($type, [
				UserSenderDataProvider::MAILBOX_TYPE,
				UserSenderDataProvider::MAILBOX_SENDER_TYPE,
			], true))
			{
				continue;
			}

			$name = (string)($sender['name'] ?? '');
			$senders[] = [
				'email' => $email,
				'name' => $name,
				'sender' => sprintf('%s <%s>', $name, $email),
				'type' => $type,
				'mailboxId' => (int)($sender['mailboxId'] ?? 0),
			];
		}

		$independentQuery = SenderTable::query()
			->setSelect(['ID', 'NAME', 'EMAIL', 'USER_ID', 'OPTIONS'])
			->where('IS_CONFIRMED', true)
			->where('PARENT_MODULE_ID', 'main')
			->where(Query::filter()
				->logic('or')
				->where('USER_ID', $userId)
				->where('IS_PUBLIC', true)
			)
		;
		$independentSenders = self::applyAddressCondition($independentQuery, $emails)->fetchAll();
		foreach ($independentSenders as $sender)
		{
			$email = mb_strtolower((string)($sender['EMAIL'] ?? ''));
			if (!in_array($email, $emails, true))
			{
				continue;
			}
			$name = UserSenderDataProvider::getSenderNameBySender($sender, $userId);
			$senders[] = [
				'email' => $email,
				'name' => $name,
				'sender' => sprintf('%s <%s>', $name, $email),
				'type' => !empty($sender['OPTIONS']['smtp'])
					? UserSenderDataProvider::SENDER_TYPE
					: UserSenderDataProvider::ALIAS_TYPE,
				'mailboxId' => null,
			];
		}

		return $senders;
	}

	/**
	 * The address condition belongs to main: it owns the table and the form of the lookup over it.
	 *
	 * @param string[] $emails
	 */
	private static function applyAddressCondition(Query $query, array $emails): Query
	{
		if (method_exists(AddressFilter::class, 'applyEmails'))
		{
			return AddressFilter::applyEmails($query, $emails);
		}

		return self::applyCaseInsensitiveAddressCondition($query, $emails);
	}

	/**
	 * An older kernel hands out no condition, and it does not normalize the stored address either, so
	 * here the case of the stored value still matters. Where the collation folds the case on its own
	 * the plain comparison already matches every variant and keeps the index; only an engine that
	 * compares case-sensitively has to fold the case in the query and pay for it, because a sender
	 * saved in mixed case would otherwise vanish from the list.
	 *
	 * @param string[] $emails
	 */
	private static function applyCaseInsensitiveAddressCondition(Query $query, array $emails): Query
	{
		if (Application::getConnection() instanceof MysqlCommonConnection)
		{
			return $query->whereIn('EMAIL', $emails);
		}

		return $query
			->registerRuntimeField(new ExpressionField('EMAIL_LOWER', 'LOWER(%s)', 'EMAIL'))
			->whereIn('EMAIL_LOWER', $emails)
		;
	}

	/**
	 * @param string[] $emails
	 */
	private static function getRelevantMailboxSenders(int $userId, array $emails): array
	{
		$emailSet = array_fill_keys($emails, true);
		$emailNormalizer = new EmailNormalizer();
		$mailboxes = array_values(array_filter(
			MailboxTable::getUserMailboxes($userId),
			static fn(array $mailbox): bool => isset($emailSet[$emailNormalizer->normalizeMailbox($mailbox)]),
		));
		if ($mailboxes === [])
		{
			return [];
		}

		$sendersByMailboxId = [];
		if (self::isSmtpAvailable())
		{
			$relevantMailboxIds = array_map('intval', array_column($mailboxes, 'ID'));
			$senderRows = SenderTable::query()
				->setSelect(['ID', 'NAME', 'EMAIL', 'USER_ID', 'OPTIONS', 'PARENT_ID'])
				->where('IS_CONFIRMED', true)
				->where('PARENT_MODULE_ID', 'mail')
				->whereIn('PARENT_ID', $relevantMailboxIds)
				->fetchAll()
			;
			foreach ($senderRows as $sender)
			{
				$sendersByMailboxId[(int)$sender['PARENT_ID']] ??= $sender;
			}
		}

		$currentUserName = (string)UserSenderDataProvider::getUserFormattedName($userId);
		$senders = [];
		foreach ($mailboxes as $mailbox)
		{
			$mailboxId = (int)$mailbox['ID'];
			$sender = $sendersByMailboxId[$mailboxId] ?? null;
			$mailboxName = trim((string)($mailbox['USERNAME'] ?? ''));
			if ($sender !== null)
			{
				$name = UserSenderDataProvider::getSenderNameBySender($sender, $userId);
			}
			else
			{
				$ownerName = (string)UserSenderDataProvider::getUserFormattedName((int)($mailbox['USER_ID'] ?? 0));
				$useSenderName = $mailbox['OPTIONS']['useSenderName']
					?? ($mailboxName !== '' && $mailboxName !== $ownerName);
				$name = $useSenderName ? $mailboxName : $currentUserName;
			}
			if (trim((string)$name) === '')
			{
				$name = $currentUserName;
			}

			$senders[] = [
				// The very fallback address the mailbox was matched by: a legacy row may keep it
				// in NAME or LOGIN while EMAIL stays empty.
				'email' => $emailNormalizer->normalizeMailbox($mailbox) ?? '',
				'name' => $name,
				'type' => $sender === null
					? UserSenderDataProvider::MAILBOX_TYPE
					: UserSenderDataProvider::MAILBOX_SENDER_TYPE,
				'mailboxId' => $mailboxId,
			];
		}

		return $senders;
	}

	/** Mirrors the private UserSenderDataProvider::isSmtpAvailable(): without SMTP the canonical
	 * provider ignores mailbox sender rows, and the typed list has to name mailboxes the same way. */
	private static function isSmtpAvailable(): bool
	{
		return Loader::includeModule('bitrix24')
			|| ((Configuration::getValue('smtp')['enabled'] ?? false) === true);
	}
}
