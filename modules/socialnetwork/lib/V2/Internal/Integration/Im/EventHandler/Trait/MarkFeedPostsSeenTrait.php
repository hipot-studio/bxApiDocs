<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\Trait;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\UserTable;
use Bitrix\Socialnetwork\Internals\LiveFeed\Counter\CounterController;
use Bitrix\Socialnetwork\Internals\LiveFeed\Counter\CounterDictionary;
use Bitrix\Socialnetwork\Internals\LiveFeed\Counter\Processor\UserProcessor;
use Bitrix\Socialnetwork\LogTable;
use Bitrix\Socialnetwork\UserContentViewTable;

/**
 * Shared logic for clearing the feed counter over a set of logIds.
 *
 * Loop-safety: uses ONLY event-free primitives
 * ({@see UserContentViewTable::set} + {@see UserProcessor::seen}).
 * Provider::setContentView is NOT used, so socialnetwork::onContentViewed is not
 * re-published and no loop with phase 2 (IM <- Feed) occurs.
 */
trait MarkFeedPostsSeenTrait
{
	/**
	 * Marks posts seen and clears the user's feed counter.
	 * Idempotent: repeated call on an already-seen post is a no-op by effect.
	 *
	 * Log fields (RATING_TYPE_ID / RATING_ENTITY_ID) are preloaded in ONE batch
	 * query over the logId set before the loop — no N+1 (Log::getById per logId).
	 * seen() is called over the whole set at once (it accepts a logId array).
	 *
	 * @param int[] $logIds
	 * @param bool $refreshCounter whether to trigger the feed counter pull immediately (true by
	 *        default; false — the caller invokes {@see self::refreshFeedCounter}
	 *        once after batch processing, to avoid a per-batch refresh).
	 */
	private function markPostsSeen(int $userId, array $logIds, bool $refreshCounter = true): void
	{
		$logIds = array_values(array_unique(array_filter(
			array_map('intval', $logIds),
			static fn (int $id): bool => $id > 0,
		)));

		if ($userId <= 0 || empty($logIds))
		{
			return;
		}

		// Batch-preload the needed log fields in one query (eliminates N+1).
		$logFieldsById = $this->loadLogRatingFields($logIds);
		if (empty($logFieldsById))
		{
			if ($refreshCounter)
			{
				$this->refreshFeedCounter($userId);
			}

			return;
		}

		$processor = UserProcessor::getInstance($userId);

		$contentViewRows = [];
		$seenLogIds = [];
		foreach ($logIds as $logId)
		{
			$fields = $logFieldsById[$logId] ?? null;

			if ($fields === null || empty($fields['RATING_TYPE_ID']) || !isset($fields['RATING_ENTITY_ID']))
			{
				continue;
			}

			$typeId = (string)$fields['RATING_TYPE_ID'];
			$entityId = (int)$fields['RATING_ENTITY_ID'];

			// Same shape as UserContentViewTable::set() with save=true (PK
			// USER_ID + RATING_TYPE_ID + RATING_ENTITY_ID), collected for a single
			// batch upsert instead of one query per post (read-all could touch
			// hundreds/thousands of links).
			$contentViewRows[] = [
				'USER_ID' => $userId,
				'RATING_TYPE_ID' => $typeId,
				'RATING_ENTITY_ID' => $entityId,
				'CONTENT_ID' => $typeId . '-' . $entityId,
			];

			$seenLogIds[] = $logId;
		}

		$this->mergeContentViews($userId, $contentViewRows);

		if (!empty($seenLogIds))
		{
			$processor->seen(CounterDictionary::COUNTER_NEW_POSTS, $seenLogIds);
			$processor->seen(CounterDictionary::COUNTER_NEW_COMMENTS, $seenLogIds);

			// The left-menu "Live feed" badge is served by the LEGACY per-post counter
			// (CUserCounter code '**L<logId>', read via LegacyCounterProvider), NOT by the
			// new scorer/sonet_total counter cleared above. This sync is event-free for
			// loop-safety, so it bypasses Provider::setContentView and never clears the
			// legacy per-post counter -> the badge keeps hanging (incl. after a full
			// reload, since the value is persisted in b_user_counter). Clear it per post
			// for the user and emit the realtime pull. No onContentViewed is published, so
			// loop-safety with phase 2 (IM <- Feed) is preserved.
			$this->clearLegacyFeedCounter($userId, $seenLogIds);
		}

		if ($refreshCounter)
		{
			$this->refreshFeedCounter($userId);
		}
	}

	/**
	 * Batch upsert of content views: one merge over the whole post set instead of
	 * a per-row {@see UserContentViewTable::set} in a loop (on read-all this saves
	 * hundreds/thousands of queries). Semantics match set(save=true): same PK
	 * conflict (USER_ID, RATING_TYPE_ID, RATING_ENTITY_ID), same DATE_VIEW update
	 * to the current time.
	 *
	 * @param array<int, array{USER_ID: int, RATING_TYPE_ID: string, RATING_ENTITY_ID: int, CONTENT_ID: string}> $rows
	 */
	private function mergeContentViews(int $userId, array $rows): void
	{
		if (empty($rows))
		{
			return;
		}

		// Mirror UserContentViewTable::set(): controller users on bitrix24 do not
		// persist content views — skip the whole batch for such a user (the per-row
		// check inside set() cached on userId, so the effect is the same).
		if (ModuleManager::isModuleInstalled('bitrix24') && $this->isControllerUser($userId))
		{
			return;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$nowDate = new SqlExpression($helper->getCurrentDateTimeFunction());

		$insertRows = [];
		foreach ($rows as $row)
		{
			$insertRows[] = $row + ['DATE_VIEW' => $nowDate];
		}

		$sql = $helper->prepareMergeValues(
			UserContentViewTable::getTableName(),
			['USER_ID', 'RATING_TYPE_ID', 'RATING_ENTITY_ID'],
			$insertRows,
			['DATE_VIEW' => $nowDate],
		);

		$connection->queryExecute($sql);

		UserContentViewTable::cleanCache();
	}

	private function isControllerUser(int $userId): bool
	{
		return (bool)UserTable::query()
			->setSelect(['ID'])
			->where('ID', $userId)
			->where('EXTERNAL_AUTH_ID', '__controller')
			->setLimit(1)
			->fetch()
		;
	}

	/**
	 * Refreshes the left menu (triggers a feed counter pull).
	 * Extracted so that on batch processing (read-all) it is called once after all
	 * batches rather than per batch.
	 */
	private function refreshFeedCounter(int $userId): void
	{
		(new CounterController($userId))->updateLeftMenuCounter();
	}

	/**
	 * Clears the LEGACY per-post live feed counter (CUserCounter code
	 * '**L<logId>') for the user and emits the realtime user_counter pull.
	 *
	 * Why this is needed: the displayed left-menu "Live feed" badge is a grouped
	 * sum of '**L*' rows ({@see \CAllUserCounter::getGroupedCode}), read via
	 * {@see \Bitrix\Socialnetwork\Integration\SocialNetwork\LiveFeed\LegacyCounterProvider}.
	 * The normal feed view clears it in bulk on feed open
	 * ({@see \Bitrix\Socialnetwork\Component\LogList\Counter::clearLogCounter}:
	 * CUserCounter::Clear($userId, '**', [SITE_ID, '**'], sendPull, multiple)).
	 * The IM -> Feed sync marks a post seen WITHOUT opening the feed, so it must
	 * clear the legacy counter per post itself.
	 *
	 * Mirrors the canonical livefeed delete ({@see \CUserCounter::clearLiveFeedCodes},
	 * same family as {@see \CUserCounter::DeleteByCode}): the codes '**L<logId>'
	 * (grouped under '**') are removed with ONE delete over the whole set — point-wise
	 * (exactly these posts, not the whole feed), and WITHOUT the zero-row upsert
	 * that a per-post CUserCounter::Clear(bMultiple=false) would perform for posts the
	 * user had no unread for (which would bloat b_user_counter on read-all). The cache
	 * is invalidated once and a single consolidated pull is emitted for the batch.
	 * No onContentViewed is published -> loop-safety preserved.
	 *
	 * Unlike the feed-open clear (which targets only the current [SITE_ID, '**']),
	 * this clears across all active sites: reading the chat system message is a
	 * site-agnostic "post seen" action, so the badge must drop wherever the user
	 * sees it, not only on the site that happened to run the read request.
	 *
	 * @param int[] $logIds
	 */
	private function clearLegacyFeedCounter(int $userId, array $logIds): void
	{
		$logIds = array_values(array_filter(
			array_map('intval', $logIds),
			static fn (int $id): bool => $id > 0,
		));

		if ($userId <= 0 || empty($logIds))
		{
			return;
		}

		$codes = array_map(
			static fn (int $logId): string => \CUserCounter::LIVEFEED_CODE . 'L' . $logId,
			$logIds,
		);

		// Batch-clear with ONE delete. A per-post CUserCounter::Clear(bMultiple=false)
		// would prepareMerge() each code and thus UPSERT a zero CNT row for every
		// '**L<logId>' even when the user had no unread for that post -> b_user_counter
		// bloat on read-all over a large project. ClearLiveFeedCodes deletes only the
		// rows that exist (point-wise: exactly these posts, not the whole feed),
		// invalidates the cache once and emits one consolidated pull for the batch.
		\CUserCounter::clearLiveFeedCodes($userId, $codes, $this->getActiveSiteIds());
	}

	/**
	 * Active site IDs plus the cross-site marker '**' (cached per process).
	 *
	 * @return string[]
	 */
	private function getActiveSiteIds(): array
	{
		static $siteIds = null;

		if ($siteIds === null)
		{
			$siteIds = ['**'];
			$rows = \Bitrix\Main\SiteTable::getList([
				'select' => ['LID'],
				'filter' => ['=ACTIVE' => 'Y'],
			]);
			foreach ($rows as $row)
			{
				$siteIds[] = $row['LID'];
			}
		}

		return $siteIds;
	}

	/**
	 * Preloads log RATING_TYPE_ID / RATING_ENTITY_ID in one query.
	 *
	 * @param int[] $logIds
	 * @return array<int, array{RATING_TYPE_ID: mixed, RATING_ENTITY_ID: mixed}> map [logId => fields]
	 */
	private function loadLogRatingFields(array $logIds): array
	{
		if (empty($logIds))
		{
			return [];
		}

		$rows = LogTable::getList([
			'select' => ['ID', 'RATING_TYPE_ID', 'RATING_ENTITY_ID'],
			'filter' => ['@ID' => $logIds],
		]);

		$result = [];
		foreach ($rows as $row)
		{
			$result[(int)$row['ID']] = [
				'RATING_TYPE_ID' => $row['RATING_TYPE_ID'],
				'RATING_ENTITY_ID' => $row['RATING_ENTITY_ID'],
			];
		}

		return $result;
	}
}
