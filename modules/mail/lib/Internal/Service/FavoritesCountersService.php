<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service;

use Bitrix\Mail\Helper\Message\Loader\QueryBuilder;
use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Mail\Internals\MailMessageMarkTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

/**
 * Unread favorites of a user, counted on the fly for a set of mailboxes: the favorites section keeps
 * no stored counter, and its rules are the rules of the message selection behind it.
 */
class FavoritesCountersService
{
	private const SEEN_STATUSES = ['Y', 'S'];

	/**
	 * The marks are the user's own, so the answer is per user even on a shared mailbox.
	 *
	 * @param int[] $mailboxIds Already allowed to the user by the caller: no access check happens here.
	 */
	public function countUnread(int $userId, array $mailboxIds): int
	{
		$mailboxIds = $this->normalizeIds($mailboxIds);
		if ($userId <= 0 || $mailboxIds === [])
		{
			return 0;
		}

		$excludedDirs = $this->spamAndTrashDirs($mailboxIds);

		// A message has a uid row per folder, so it stays unread until any visible copy is seen.
		$seenMessages = $this->visibleCopiesQuery($mailboxIds, $excludedDirs)
			->setSelect(['MESSAGE_ID'])
			->whereIn('IS_SEEN', self::SEEN_STATUSES)
		;

		$query = MailMessageMarkTable::query()
			->registerRuntimeField('UID', new Reference(
				'UID',
				MailMessageUidTable::class,
				Join::on('this.MAILBOX_ID', 'ref.MAILBOX_ID')->whereColumn('this.MESSAGE_ID', 'ref.MESSAGE_ID'),
				['join_type' => Join::TYPE_INNER],
			))
			->setSelect([new ExpressionField('CNT', 'COUNT(DISTINCT %s)', ['MESSAGE_ID'])])
			->where('USER_ID', $userId)
			->where('CODE', MailMessageMarkTable::CODE_FAVORITES)
			->whereIn('MAILBOX_ID', $mailboxIds)
			->where('UID.DELETE_TIME', 0)
			->where('UID.MESSAGE_ID', '>', 0)
			->whereNotIn('UID.IS_OLD', MailMessageUidTable::HIDDEN_STATUSES)
			->whereNotIn('MESSAGE_ID', $seenMessages)
		;

		if ($excludedDirs !== [])
		{
			$query->whereNotIn('UID.DIR_MD5', $excludedDirs);
		}

		$this->limitToActiveGeneration($query, $mailboxIds, 'UID.');

		$row = $query->fetch();

		return $row === false ? 0 : (int)($row['CNT'] ?? 0);
	}

	/**
	 * The section hides spam and trash (CMailClientMessageListComponent::applyFavoritesSection), so the
	 * counter leaves out the same folders. Asked of the folder table by one query for the whole scope:
	 * MailboxDirectoryHelper::getSpamAndTrashDirsMd5ForMailboxes() answers the same, but raises a full
	 * mailbox helper per mailbox and reads every folder of each of them along the way.
	 *
	 * @param int[] $mailboxIds
	 * @return string[]
	 */
	private function spamAndTrashDirs(array $mailboxIds): array
	{
		$query = MailboxDirectoryTable::query()
			->setSelect(['DIR_MD5'])
			->whereIn('MAILBOX_ID', $mailboxIds)
			->where(Query::filter()
				->logic('or')
				->where('IS_SPAM', MailboxDirectoryTable::ACTIVE)
				->where('IS_TRASH', MailboxDirectoryTable::ACTIVE))
		;

		$this->limitToActiveGeneration($query, $mailboxIds);

		$hashes = array_filter(
			array_map('strval', array_column($query->fetchAll(), 'DIR_MD5')),
			static fn (string $hash): bool => $hash !== '',
		);

		return array_values(array_unique($hashes));
	}

	/**
	 * @param int[] $mailboxIds
	 * @param string[] $excludedDirs
	 */
	private function visibleCopiesQuery(array $mailboxIds, array $excludedDirs): Query
	{
		$query = MailMessageUidTable::query()
			->whereIn('MAILBOX_ID', $mailboxIds)
			->where('DELETE_TIME', 0)
			->where('MESSAGE_ID', '>', 0)
			->whereNotIn('IS_OLD', MailMessageUidTable::HIDDEN_STATUSES)
		;

		if ($excludedDirs !== [])
		{
			$query->whereNotIn('DIR_MD5', $excludedDirs);
		}

		$this->limitToActiveGeneration($query, $mailboxIds);

		return $query;
	}

	/**
	 * The counter counts what the user sees, and the user is served by the active source generation of
	 * every mailbox of the scope. The ready form is asked for the whole set at once: it reads the
	 * pointers by one query and leaves a mailbox without generations untouched.
	 *
	 * @param int[] $mailboxIds
	 */
	private function limitToActiveGeneration(Query $query, array $mailboxIds, string $fieldPrefix = ''): void
	{
		foreach (QueryBuilder::generationScopeFilter($mailboxIds, $fieldPrefix) as $key => $condition)
		{
			$query->addFilter(is_int($key) ? null : $key, $condition);
		}
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private function normalizeIds(array $ids): array
	{
		$ids = array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0);

		return array_values(array_unique($ids));
	}
}
