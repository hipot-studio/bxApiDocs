<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\OnAfterReadAllChats;

use Bitrix\Im\V2\Message\Event\AfterReadAllChatsEvent;
use Bitrix\Main\Application;
use Bitrix\Socialnetwork\V2\Feature;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\Trait\MarkFeedPostsSeenTrait;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\LogImMessageLinkService;

/**
 * Handler for the IM global "read all chats" event ({@see AfterReadAllChatsEvent},
 * event name 'OnAfterReadAllChats').
 *
 * "IM -> Feed" counter sync for the messenger-wide "read all" action (the funnel
 * button -> "read all"). That bulk path ({@see \Bitrix\Im\V2\Reading\Reader::readAll})
 * clears IM counters directly and does NOT emit the per-chat
 * OnAfterReadAllMessagesExternalChatSonetGroup event, so the per-chat handler
 * ({@see \Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\OnAfterReadAllMessages\UpdateFeedCounters})
 * never runs and the feed badge over project posts keeps hanging. The bulk path does
 * publish one shared AfterReadAllChatsEvent($userId) — this handler subscribes to it
 * and clears the feed counter for the linked (collab) posts.
 *
 * Scope: we mark seen ONLY the linked posts the user still has an unread feed badge
 * for (their own legacy '**L<logId>' codes). We do not touch other users (no S-1
 * cross-user effect) and do not reach into IM (no im <- socialnetwork dependency:
 * IM counters are already cleared by the bulk read-all itself).
 *
 * Loop-safety: event-free. {@see MarkFeedPostsSeenTrait::markPostsSeen} uses only the
 * primitives UserContentViewTable::set + UserProcessor::seen + clearLegacyFeedCounter
 * and never republishes socialnetwork::onContentViewed, so no loop with phase 2
 * (IM <- Feed) occurs.
 *
 * Idempotent: a repeated read-all is a no-op by effect (already-seen posts and
 * already-cleared legacy codes produce no change).
 *
 * Async: EventDispatcher defers this listener into a background job; the body stays
 * synchronous (no own addBackgroundJob).
 *
 * Typed listener: instantiated by the container ({@see \Bitrix\Socialnetwork\V2\Internal\EventDispatcher\EventDispatcher::handle}),
 * so the link service is taken via the constructor (DI), not resolved in the body.
 */
class UpdateFeedCounters
{
	use MarkFeedPostsSeenTrait;

	public function __construct(
		private readonly LogImMessageLinkService $linkService,
	)
	{
	}

	public function __invoke(AfterReadAllChatsEvent $event): void
	{
		// Collab gate: the whole IM -> Feed sync is collab-only.
		if (!Feature::isNewProjectsOn())
		{
			return;
		}

		$userId = $event->getUserId();
		if ($userId <= 0)
		{
			return;
		}

		// Source of candidate logIds: the user's own legacy live-feed badges
		// ('**L<logId>'). Read-all cleared IM counters globally, so every still-unread
		// feed post the user sees a badge for is now stale; we narrow this set to the
		// collab-linked ones below.
		$logIds = $this->getUnreadFeedLogIds($userId);
		if (empty($logIds))
		{
			return;
		}

		// Drop ordinary/historical posts without a project-chat link: only collab posts
		// are part of this IM <-> Feed sync.
		$linkedLogIds = $this->linkService->filterLinkedLogIds($logIds);
		if (empty($linkedLogIds))
		{
			return;
		}

		$this->markPostsSeen($userId, $linkedLogIds);
	}

	/**
	 * Reads the logIds the user still has a legacy live-feed badge for, from the
	 * per-post counter codes '**L<logId>' in b_user_counter.
	 *
	 * No dedicated CUserCounter reader exists for the live-feed codes (only the
	 * clearLiveFeedCodes writer), so a direct DISTINCT query is used. The grouped
	 * aggregate code '**' (no 'L') is excluded by the 'L%' suffix; non-numeric tails
	 * are skipped defensively.
	 *
	 * @return int[]
	 */
	private function getUnreadFeedLogIds(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$prefix = \CUserCounter::LIVEFEED_CODE . 'L'; // '**L'
		$like = $helper->forSql($prefix) . '%';

		$sql = sprintf(
			"SELECT DISTINCT CODE FROM b_user_counter WHERE USER_ID = %d AND CODE LIKE '%s'",
			(int)$userId,
			$like,
		);

		$result = [];
		$prefixLength = mb_strlen($prefix);
		$rows = $connection->query($sql);
		while ($row = $rows->fetch())
		{
			$tail = mb_substr((string)$row['CODE'], $prefixLength);
			if ($tail === '' || !preg_match('/^[0-9]+$/D', $tail))
			{
				continue;
			}

			$logId = (int)$tail;
			if ($logId > 0)
			{
				$result[] = $logId;
			}
		}

		return $result;
	}
}
