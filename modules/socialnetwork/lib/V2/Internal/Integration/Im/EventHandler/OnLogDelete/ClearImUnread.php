<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\OnLogDelete;

use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Reading\Counter\CountersProvider;
use Bitrix\Im\V2\Reading\Counter\CountersUpdater;
use Bitrix\Im\V2\Reading\Pull\ReadMessages;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\LogImMessageLinkService;

/**
 * Handler for the classic socialnetwork::OnSocNetLogDelete event.
 *
 * When a feed post linked to a system message of a project (collab) chat is
 * deleted, the IM unread of that message is cleared for all chat members,
 * then the link row is removed.
 *
 * Minimal impact: the system message itself is NOT deleted — only the IM unread
 * counter is cleared (CountersUpdater::delete()->byMessages()->forAllUsers()),
 * so chat history AND per-user view/read-receipt rows (b_im_message_viewed) stay
 * intact. NOT Cleaner::onDeleteMessages: that path is for real message deletion
 * and also wipes view rows for all users, which would drop persisted read-receipt
 * state on a message that remains in history.
 *
 * Collab gate: sync runs only for linked posts (a link row means the post is
 * already published to a collab chat). No link — no-op.
 *
 * Loop-safety: clearing IM unread does not publish
 * socialnetwork::onContentViewed, so no loop with phases 2/3 occurs.
 *
 * The event is published classically (ExecuteModuleEventEx with scalar params
 * $logId, $logFields), hence registered via registerEventHandler, NOT via the
 * typed EventDispatcher.
 */
final class ClearImUnread
{
	/**
	 * @param int $logId deleted feed post id (LOG_ID)
	 * @param array $logFields deleted log fields (unused; passed by the core)
	 */
	public static function onLogDelete($logId, $logFields = []): void
	{
		$logId = (int)$logId;
		if ($logId <= 0)
		{
			return;
		}

		if (!Loader::includeModule('im'))
		{
			return;
		}

		$linkService = Container::getInstance()->get(LogImMessageLinkService::class);

		// All post links in one SELECT: IM_MESSAGE_ID + IM_CHAT_ID.
		// The post may have been cross-posted to several project chats — clear the
		// IM unread of the linked system message in each of them.
		$links = $linkService->getLinksByLogId($logId);
		if (empty($links))
		{
			// No link (not a collab / historical post) — no-op.
			return;
		}

		// Links are read synchronously BEFORE the deferred clear (imMessageId/imChatId
		// outlive the post deletion and are needed by the job body). The heavy body —
		// loading each chat's members (getRelations) and clearing IM unread via
		// Cleaner — is moved off the post-deletion hot path into a background job
		// (modeled on ContentViewedHandler). Deferred clear is safe: the message is
		// not deleted and unread is cleared by imMessageId/imChatId, independent of
		// the link row. unlinkByLogId runs in the same job to keep the order
		// "clear unread -> delete link"; idempotent: re-deleting the post (links
		// already gone) hits the synchronous no-op above, a repeated job is a no-op.
		Application::getInstance()->addBackgroundJob(
			static function () use ($links, $logId, $linkService): void {
				$countersUpdater = ServiceLocator::getInstance()->get(CountersUpdater::class);
				$countersProvider = ServiceLocator::getInstance()->get(CountersProvider::class);

				foreach ($links as $link)
				{
					$imMessageId = $link->imMessageId;
					$imChatId = $link->imChatId;
					if ($imMessageId <= 0 || $imChatId <= 0)
					{
						// Broken link — unread cannot be cleared deterministically; skip,
						// the row will be removed by the common unlinkByLogId below.
						continue;
					}

					// Only the users who actually have THIS system message unread — not the
					// whole chat roster. The delete is scoped by chat+message; this precise
					// set drives both cache invalidation (forAllUsers) and the realtime pull
					// below. On a large collab, loading every relation just to invalidate the
					// cache for users without unread is wasteful.
					$affectedUsers = array_values(array_unique(array_map(
						'intval',
						array_column(
							MessageUnreadTable::query()
								->setSelect(['USER_ID'])
								->where('CHAT_ID', $imChatId)
								->where('MESSAGE_ID', $imMessageId)
								->fetchAll(),
							'USER_ID',
						),
					)));
					if (empty($affectedUsers))
					{
						continue;
					}

					$chat = Chat::getInstance($imChatId);
					$message = (new Message($imMessageId))->setChatId($imChatId);
					$messages = MessageCollection::createFromArray([$message]);

					$countersUpdater->delete()->byMessages($messages)->forAllUsers($affectedUsers)->execute();

					// CountersUpdater only deletes the unread rows and clears the counter
					// cache; it sends no pull, so online clients would keep the stale chat
					// counter until reload. Push the recomputed counter to exactly the
					// affected users.
					self::pushCounterDrop($chat, $messages, $affectedUsers, $countersProvider);
				}

				// Remove all post links (across all chats).
				$linkService->unlinkByLogId($logId);
			}
		);
	}

	/**
	 * Realtime-notifies the affected users that the linked system message's IM unread
	 * was cleared (the feed post was deleted). Mirrors the exact-read pull
	 * ({@see \Bitrix\Im\V2\Reading\Reader::readExactly}): one {@see ReadMessages} per
	 * user carrying the recomputed counter, exact=true so clients drop the indication
	 * of this message point-wise while the badge stays authoritative via the counter.
	 * No read-receipt-to-opponent — the post was deleted, not read.
	 *
	 * @param int[] $userIds the precise set with unread for this message
	 */
	private static function pushCounterDrop(
		Chat $chat,
		MessageCollection $messages,
		array $userIds,
		CountersProvider $countersProvider,
	): void
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		$chatId = $chat->getChatId();
		$counters = $countersProvider->getForUsers($chatId, $userIds);

		// Current per-user read cursor (NOT moved — the message was deleted, not read);
		// one targeted query instead of loading the whole relation collection.
		$lastIdByUser = [];
		$relations = RelationTable::query()
			->setSelect(['USER_ID', 'LAST_ID'])
			->where('CHAT_ID', $chatId)
			->whereIn('USER_ID', $userIds)
			->fetchAll()
		;
		foreach ($relations as $relation)
		{
			$lastIdByUser[(int)$relation['USER_ID']] = (int)$relation['LAST_ID'];
		}

		foreach ($userIds as $userId)
		{
			(new ReadMessages(
				$chat,
				$messages,
				$userId,
				$lastIdByUser[$userId] ?? 0,
				$counters->getByUserId($userId),
				true,
			))->send();
		}
	}
}
