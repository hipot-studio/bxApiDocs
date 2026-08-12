<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\OnAfterReadMessages;

use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterReadMessagesEvent;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Reading\Reader;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Feature;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\Trait\MarkFeedPostsSeenTrait;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\LogImMessageLinkService;

/**
 * Handler for the IM message-read event of a project (collab) chat
 * (OnAfterReadMessagesExternalChatSonetGroup).
 *
 * "IM -> Feed" counter sync: when a user reads system messages in a project
 * chat, the linked feed posts are marked seen and the feed counter is cleared.
 * Implements the IM->Feed counter sync.
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

	public function __construct(
		private readonly LogImMessageLinkService $linkService,
	)
	{
	}

	public function __invoke(AfterReadMessagesEvent $event): void
	{
		// Collab gate: event type is already ...SonetGroup, also check new projects.
		if (!Feature::isNewProjectsOn())
		{
			return;
		}

		$userId = $event->getReaderId();
		$chatId = (int)$event->getChat()->getChatId();
		$messageIds = $event->getMessages()->getIds();

		if ($userId <= 0 || $chatId <= 0 || empty($messageIds))
		{
			return;
		}

		$links = $this->linkService->getLinksByChatAndMessageIds($chatId, $messageIds);
		if (empty($links))
		{
			return;
		}

		// Idempotency + loop-safety: markPostsSeen uses only event-free
		// primitives (UserContentViewTable::set + UserProcessor::seen) and does NOT
		// publish socialnetwork::onContentViewed -> no loop with phase 2. Redelivery
		// does not change state (marking an already-seen post is a no-op by effect).
		// At-least-once delivery is safe.
		$this->markPostsSeen($userId, array_values($links));

		// Cross-post cascade: a post may be published to several project chats
		// ($logId -> several (IM_MESSAGE_ID, IM_CHAT_ID) rows). Reading the system
		// message in chat A clears the feed counter above, but the linked system
		// messages of the SAME post in OTHER chats (B, ...) keep their IM unread,
		// because this handler is event-free (loop-safety) and does NOT publish
		// socialnetwork::onContentViewed — so ContentViewedHandler (the Feed->IM
		// mirror that would clear them) never runs. Clear those sibling messages here.
		$this->clearCrossPostUnread($userId, $chatId, array_unique(array_values($links)));
	}

	/**
	 * Clears IM unread of the linked system messages of the same posts in the OTHER
	 * project chats of a cross-post (every chat except the one just read by the real
	 * event). The current chat is already read by the genuine read event, so it is
	 * filtered out — no need to re-read it.
	 *
	 * Uses ONLY the event-free primitive {@see Reader::readExactly}, exactly like the
	 * Feed->IM mirror {@see \Bitrix\Im\V2\Integration\Socialnetwork\EventHandler\ContentViewedHandler}:
	 * - Loop-safety: readExactly does NOT publish a typed read event, so there is no
	 *   echo back into socialnetwork and no loop with the Feed->IM phase.
	 * - Security (S-1): readExactly does its own per-chat checkAccess, so a sibling
	 *   message in a chat the user is not a member of is simply not cleared — no extra
	 *   access checks are added or bypassed here.
	 * - Idempotency: readExactly on an already-read message is a no-op.
	 *
	 * Runs in a background job to keep the chat read hot path light, mirroring
	 * ContentViewedHandler.
	 *
	 * @param int[] $logIds affected post ids (unique)
	 */
	private function clearCrossPostUnread(int $userId, int $currentChatId, array $logIds): void
	{
		if ($userId <= 0 || empty($logIds))
		{
			return;
		}

		// Cross-module gate before touching im classes (same prerequisite as the
		// sibling handler OnLogDelete\ClearImUnread).
		if (!Loader::includeModule('im'))
		{
			return;
		}

		// Collect sibling links synchronously (links outlive the deferred job and the
		// service belongs to socialnetwork): every linked chat of each post except the
		// one the real event already read.
		$siblingLinks = [];
		foreach ($logIds as $logId)
		{
			foreach ($this->linkService->getLinksByLogId((int)$logId) as $link)
			{
				if ($link->imMessageId <= 0 || $link->imChatId <= 0)
				{
					continue;
				}

				if ($link->imChatId === $currentChatId)
				{
					// Current chat — already read by the genuine read event.
					continue;
				}

				$siblingLinks[] = $link;
			}
		}

		if (empty($siblingLinks))
		{
			return;
		}

		Application::getInstance()->addBackgroundJob(
			static function () use ($siblingLinks, $userId): void {
				$reader = ServiceLocator::getInstance()->get(Reader::class);

				foreach ($siblingLinks as $link)
				{
					$message = (new Message($link->imMessageId))->setChatId($link->imChatId);
					$reader->readExactly(MessageCollection::createFromArray([$message]), $userId);
				}
			}
		);
	}
}
