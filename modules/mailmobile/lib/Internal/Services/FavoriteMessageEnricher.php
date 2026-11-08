<?php

namespace Bitrix\MailMobile\Internal\Services;

use Bitrix\Mail\Helper\Dto\MailMessage;
use Bitrix\Mail\Helper\Message\Loader\QueryBuilder;
use Bitrix\Mail\Internal\Service\FavoritesService;
use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Mail\MailMessageUidTable;
use Closure;

final class FavoriteMessageEnricher
{
	private Closure $favoriteIdsLoader;
	private Closure $allowedIndexesLoader;
	private Closure $directoryRowsLoader;
	private Closure $uidRowsLoader;

	public function __construct(
		?callable $favoriteIdsLoader = null,
		?callable $allowedIndexesLoader = null,
		?callable $directoryRowsLoader = null,
		?callable $uidRowsLoader = null,
	)
	{
		$this->favoriteIdsLoader = Closure::fromCallable(
			$favoriteIdsLoader ?? static fn (int $userId, array $messageIds): array =>
				(new FavoritesService())->getFavoriteMessageIds($userId, $messageIds),
		);
		$this->allowedIndexesLoader = Closure::fromCallable(
			$allowedIndexesLoader ?? fn (array $messages): array => $this->loadAllowedIndexes($messages),
		);
		$this->directoryRowsLoader = Closure::fromCallable(
			$directoryRowsLoader ?? static fn (array $mailboxIds): array => MailboxDirectoryTable::getList([
				'select' => ['ID', 'MAILBOX_ID', 'DIR_MD5', 'IS_SPAM', 'IS_TRASH'],
				'filter' => ['@MAILBOX_ID' => $mailboxIds],
			])->fetchAll(),
		);
		$this->uidRowsLoader = Closure::fromCallable(
			$uidRowsLoader ?? static fn (array $uidIds, array $mailboxIds): array => MailMessageUidTable::getList([
				'select' => ['ID', 'MESSAGE_ID', 'MAILBOX_ID', 'DIR_MD5'],
				'filter' => array_merge(
					QueryBuilder::VISIBLE_UID_FILTERS_DRIVER,
					['@ID' => $uidIds, '@MAILBOX_ID' => $mailboxIds],
				),
			])->fetchAll(),
		);
	}

	/**
	 * @param MailMessage[] $messages
	 * @return MailMessage[]
	 */
	public function enrich(array $messages, ?int $userId, bool $featureEnabled): array
	{
		foreach ($messages as $message)
		{
			$message->isFavorite = false;
			$message->isFavoriteAllowed = false;
		}

		if (!$featureEnabled || $userId === null || $userId <= 0 || $messages === [])
		{
			return $messages;
		}

		$messageIds = $this->collectMessageIds($messages);
		if ($messageIds === [])
		{
			return $messages;
		}

		$favoriteSet = array_flip(($this->favoriteIdsLoader)($userId, $messageIds));
		$allowedSet = array_flip(($this->allowedIndexesLoader)($messages));
		foreach ($messages as $index => $message)
		{
			if (!isset($message->id))
			{
				continue;
			}

			$message->isFavorite = isset($favoriteSet[$message->id]);
			$message->isFavoriteAllowed = !$message->readOnly && isset($allowedSet[$index]);
		}

		return $messages;
	}

	private function collectMessageIds(array $messages): array
	{
		$messageIds = [];
		foreach ($messages as $message)
		{
			if (
				isset($message->id, $message->uidId, $message->mailboxId)
				&& $message->id > 0
				&& $message->uidId !== ''
				&& $message->mailboxId > 0
			)
			{
				$messageIds[$message->id] = true;
			}
		}

		return array_keys($messageIds);
	}

	private function loadAllowedIndexes(array $messages): array
	{
		$uidIds = [];
		$mailboxIds = [];
		foreach ($messages as $message)
		{
			if (!isset($message->mailboxId) || $message->mailboxId <= 0)
			{
				continue;
			}

			$mailboxIds[] = $message->mailboxId;
			if ($message->folderId === null && isset($message->uidId))
			{
				[$uidId] = explode('-', $message->uidId, 2);
				if ($uidId !== '')
				{
					$uidIds[] = $uidId;
				}
			}
		}

		if ($mailboxIds === [])
		{
			return [];
		}

		$mailboxIds = array_values(array_unique($mailboxIds));
		$directoryRows = ($this->directoryRowsLoader)($mailboxIds);
		$uidRows = $uidIds === []
			? []
			: ($this->uidRowsLoader)(array_values(array_unique($uidIds)), $mailboxIds)
		;

		return $this->resolveAllowedIndexes($messages, $directoryRows, $uidRows);
	}

	private function resolveAllowedIndexes(array $messages, array $directoryRows, array $uidRows): array
	{
		$directoriesById = [];
		$excludedDirsByMailbox = [];
		foreach ($directoryRows as $row)
		{
			$mailboxId = (int)$row['MAILBOX_ID'];
			$directoriesById[(int)$row['ID']] = $row;
			if (
				(int)$row['IS_SPAM'] === MailboxDirectoryTable::ACTIVE
				|| (int)$row['IS_TRASH'] === MailboxDirectoryTable::ACTIVE
			)
			{
				$excludedDirsByMailbox[$mailboxId][(string)$row['DIR_MD5']] = true;
			}
		}

		$uids = [];
		foreach ($uidRows as $row)
		{
			$key = (string)$row['ID'] . ':' . (int)$row['MAILBOX_ID'] . ':' . (int)$row['MESSAGE_ID'];
			$uids[$key] = $row;
		}

		$allowedIndexes = [];
		foreach ($messages as $index => $message)
		{
			if (!isset($message->id, $message->mailboxId) || $message->mailboxId <= 0)
			{
				continue;
			}

			if ($message->folderId !== null)
			{
				$directory = $directoriesById[$message->folderId] ?? null;
				if (
					$directory !== null
					&& (int)$directory['MAILBOX_ID'] === $message->mailboxId
					&& (int)$directory['IS_SPAM'] !== MailboxDirectoryTable::ACTIVE
					&& (int)$directory['IS_TRASH'] !== MailboxDirectoryTable::ACTIVE
				)
				{
					$allowedIndexes[] = $index;
				}

				continue;
			}

			if (!isset($message->uidId))
			{
				continue;
			}

			[$uidId] = explode('-', $message->uidId, 2);
			$uid = $uids[$uidId . ':' . $message->mailboxId . ':' . $message->id] ?? null;
			if ($uid !== null && !isset($excludedDirsByMailbox[$message->mailboxId][(string)$uid['DIR_MD5']]))
			{
				$allowedIndexes[] = $index;
			}
		}

		return $allowedIndexes;
	}
}
