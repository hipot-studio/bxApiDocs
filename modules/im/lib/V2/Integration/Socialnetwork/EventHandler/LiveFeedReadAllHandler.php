<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Socialnetwork\EventHandler;

use Bitrix\Im\V2\Integration\Socialnetwork\Collab\Collab;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Reading\Reader;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\LogImMessageLinkService;

/**
 * Handler for the socialnetwork::onSpaceLiveFeedReadAll event.
 *
 * Sync "Read-all in Feed -> project (collab) chat": when a user marks
 * the whole project feed read, ALL linked post system messages in the project
 * chat are marked read for that user (exact event-free clear unread + counter pull).
 *
 * Mirror of {@see \Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\OnAfterReadAllMessages\UpdateFeedCounters}
 * (reverse direction IM -> Feed). Implements the read-all branch of the Feed->IM sync.
 */
final class LiveFeedReadAllHandler
{
	/**
	 * Batch size of linked messages (keyset by unique IM_MESSAGE_ID).
	 * Avoids an unbounded fetch on long-lived collab projects with thousands of posts.
	 */
	private const BATCH_SIZE = 500;

	public static function onSpaceLiveFeedReadAll(Event $event): void
	{
		if (!Collab::isNewProjectsAvailable())
		{
			return;
		}

		$userId = (int)$event->getParameter('userId');
		$chatId = (int)$event->getParameter('imChatId');

		if ($userId <= 0 || $chatId <= 0)
		{
			return;
		}

		if (!Loader::includeModule('socialnetwork'))
		{
			return;
		}

		$linkService = new LogImMessageLinkService();

		// Idempotency: readExactly on already-read messages is a no-op
		// (counter never goes negative, no duplicates). At-least-once delivery is safe.
		// Exact: only post system messages have unread cleared; other chat
		// messages (including human ones) are untouched.
		// Loop-safety: readExactly is event-free -> no echo back to socialnetwork.
		// No snapshot boundary here (deliberate, asymmetric to the mirror IM->Feed
		// handler UpdateFeedCounters, which IS bounded): "Read all in feed" intentionally
		// also clears the chat unread of system messages cross-posted in the brief gap
		// before this job runs, so the project chat reads as fully caught up right after
		// the action. The post itself stays unread in the feed (read-all could not cover
		// a post that did not exist yet), so nothing is lost — only the duplicate chat
		// badge is suppressed. Product decision (MR 7179 snapshot-race thread); do not
		// "fix" this to match UpdateFeedCounters without revisiting that decision.
		Application::getInstance()->addBackgroundJob(
			static function () use ($linkService, $chatId, $userId): void {
				$reader = ServiceLocator::getInstance()->get(Reader::class);

				// Keyset pagination by unique IM_MESSAGE_ID: process linked messages
				// in batches without an unbounded single fetch.
				$lastImMessageId = 0;
				do
				{
					$imMessageIds = $linkService->getImMessageIdsByChatId($chatId, self::BATCH_SIZE, $lastImMessageId);
					if (empty($imMessageIds))
					{
						break;
					}

					// Batch-load linked system messages in a single query (avoids N+1:
					// a MessageCollection built from ids hydrates via one whereIn fetch
					// instead of a per-id getByPrimary). CHAT_ID comes from the rows, so
					// no manual setChatId is needed; orphan link ids (no b_im_message row)
					// are silently skipped.
					$messages = new MessageCollection($imMessageIds);
					if (!$messages->isEmpty())
					{
						$reader->readExactly($messages, $userId);
					}

					$lastImMessageId = (int)end($imMessageIds);
				}
				while (count($imMessageIds) === self::BATCH_SIZE);
			}
		);
	}
}
