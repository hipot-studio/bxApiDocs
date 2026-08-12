<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Socialnetwork\EventHandler;

use Bitrix\Im\V2\Integration\Socialnetwork\Collab\Collab;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Reading\Reader;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\LogImMessageLinkService;

/**
 * Handler for the socialnetwork::onContentViewed event.
 *
 * Counter sync "Feed -> project (collab) chat": when a user views a feed post,
 * the linked system message in the project chat is marked read for that user
 * (clear unread + counter pull).
 *
 * Implements the Feed->IM counter sync.
 */
final class ContentViewedHandler
{
	public static function onContentViewed(Event $event): void
	{
		if (!Collab::isNewProjectsAvailable())
		{
			return;
		}

		$userId = (int)$event->getParameter('userId');
		$logId = (int)$event->getParameter('logId');

		if ($userId <= 0 || $logId <= 0)
		{
			return;
		}

		// Symmetry with the feed counter: when the view is not persisted
		// (save=false), the Lenta counter does not clear, so we must not clear IM-unread
		// either. Gate on the 'save' flag carried by socialnetwork::onContentViewed
		// (Provider::setContentView).
		if (!$event->getParameter('save'))
		{
			return;
		}

		if (!Loader::includeModule('socialnetwork'))
		{
			return;
		}

		$linkService = new LogImMessageLinkService();

		// All post links in one SELECT: IM_MESSAGE_ID + IM_CHAT_ID.
		// A post may be published to several project chats (crosspost to several
		// groups) — clear the linked system message unread in each of them.
		$links = $linkService->getLinksByLogId($logId);
		if (empty($links))
		{
			// No link (not a collab / historical post) — skip.
			return;
		}

		// Exact read: clear unread of EXACTLY the post system message,
		// not "up to it" — other unread chat messages (including human ones) are
		// untouched. Reader::readExactly is event-free (loop-safety): it does not
		// publish a typed read event, so there is no echo back to socialnetwork.
		//
		// Idempotency: readExactly on an already-read message is a no-op
		// (counter never goes negative, no duplicates). At-least-once delivery is
		// safe. No explicit retry needed: a repeated post view repeats the
		// read.
		Application::getInstance()->addBackgroundJob(
			static function () use ($links, $userId): void {
				$reader = ServiceLocator::getInstance()->get(Reader::class);

				foreach ($links as $link)
				{
					if ($link->imMessageId <= 0 || $link->imChatId <= 0)
					{
						continue;
					}

					$message = (new Message($link->imMessageId))->setChatId($link->imChatId);
					$reader->readExactly(MessageCollection::createFromArray([$message]), $userId);
				}
			}
		);
	}
}
