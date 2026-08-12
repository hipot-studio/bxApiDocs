<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\OnAfterReadAllMessages;

use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterReadAllMessagesEvent;
use Bitrix\Socialnetwork\V2\Feature;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\Trait\MarkFeedPostsSeenTrait;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\LogImMessageLinkService;

/**
 * Handler for the IM "read-all" event of a project (collab) chat
 * (OnAfterReadAllMessagesExternalChatSonetGroup).
 *
 * "IM -> Feed" counter sync: when a user reads all messages of a project chat,
 * ALL linked feed posts of the project are marked seen and the feed counter is
 * cleared. Implements the read-all branch of the IM->Feed counter sync.
 *
 * Runs asynchronously: EventDispatcher defers the call into a background job.
 *
 * Typed listener: instantiated by the container ({@see EventDispatcher::handle}),
 * so the link-service dependency is taken via the constructor (DI), not resolved by
 * the service locator inside the body.
 */
class UpdateFeedCounters
{
	use MarkFeedPostsSeenTrait;

	/**
	 * Batch size for processing linked posts on read-all.
	 *
	 * Keyset batching by PK LOG_ID: on large collab projects with thousands of
	 * linked posts, avoids an unbounded single fetch and a heavy background job.
	 */
	private const BATCH_SIZE = 500;

	public function __construct(
		private readonly LogImMessageLinkService $linkService,
	)
	{
	}

	public function __invoke(AfterReadAllMessagesEvent $event): void
	{
		// Collab gate: event type is already ...SonetGroup, also check new projects.
		if (!Feature::isNewProjectsOn())
		{
			return;
		}

		$userId = $event->getReaderId();
		$chatId = (int)$event->getChat()->getChatId();

		if ($userId <= 0 || $chatId <= 0)
		{
			return;
		}

		// Snapshot boundary (read-all race): this listener runs in a background job, so
		// a post cross-posted into the chat AFTER the read-all but before the job runs
		// would otherwise be scanned and falsely marked seen in the feed. Bound the scan
		// to links whose system message existed at the read-all moment. 0 (legacy event
		// without the field) - falls back to the previous unbounded scan.
		$maxImMessageId = $event->getLastMessageId();

		// Keyset pagination by LOG_ID: batch processing without an unbounded fetch.
		$lastLogId = 0;
		do
		{
			$logIds = $this->linkService->getLogIdsByChatId($chatId, self::BATCH_SIZE, $lastLogId, $maxImMessageId);
			if (empty($logIds))
			{
				break;
			}

			$this->markPostsSeen($userId, $logIds, false);

			$lastLogId = (int)end($logIds);
		}
		while (count($logIds) === self::BATCH_SIZE);

		// Trigger the feed counter pull once after all batches are processed.
		$this->refreshFeedCounter($userId);
	}
}
