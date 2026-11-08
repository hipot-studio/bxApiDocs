<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools\Support;

use Bitrix\Im\Text;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\FavoriteChat;
use Bitrix\Im\V2\Chat\PrivateChat;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserFactory;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Reading\Counter\Entity\ChatCounter;
use Bitrix\Im\V2\Relation;

final class ImToolFormatter
{
	/**
	 * Warms the user cache for a batch of ids with a single query, so that subsequent per-user
	 * lookups in formatMessage()/formatMember() (User::getInstance) read from the hot cache instead
	 * of issuing one SQL per user. Delegates to {@see UserFactory::preloadUsers()}, which owns the
	 * field-set and caches through the same path as the per-user read, so formatting stays
	 * bit-identical.
	 */
	public function preloadUsers(array $userIds): void
	{
		UserFactory::getInstance()->preloadUsers($userIds);
	}

	public function formatChat(Chat $chat, int $userId): array
	{
		return [
			'chatId' => $chat->getChatId(),
			'dialogId' => $chat->getDialogId($userId),
			'title' => Text::decodeEmoji($chat->getTitle() ?? ''),
			'type' => $chat->getType(),
		];
	}

	public function formatMessage(Message $message): array
	{
		$authorId = $message->getAuthorId();
		$author = $authorId > 0 ? User::getInstance($authorId) : null;

		return [
			'id' => $message->getId(),
			'chatId' => $message->getChatId(),
			'authorId' => $authorId,
			'authorName' => $author?->getName() ?? '',
			'dateCreate' => $message->getDateCreate()?->format('c'),
			'text' => $this->formatMessageText($message),
		];
	}

	/**
	 * Relations come from RelationProvider::getMembers(), which has a mandatory USER.ACTIVE filter,
	 * so every listed member is active by construction and the payload carries no activity flag.
	 */
	public function formatMember(Relation $relation): array
	{
		$user = $relation->getUser();

		return [
			'userId' => $relation->getUserId(),
			'name' => $user->getName() ?? '',
			'role' => mb_strtolower($relation->getRole()),
		];
	}

	/**
	 * Formats the all-chats summary sample. Private chats are resolved to the companion's dialog id
	 * in a single batch query ({@see PrivateChat::getDialogIds()}); the self chat (Saved Messages,
	 * the only private chat without a companion relation) resolves to the user's own id, matching
	 * {@see FavoriteChat::getDialogId()}; group and other chats keep the `chat<ID>` form. The counter
	 * of each item is the subtree-aggregated value provided by the caller, so it is consistent with
	 * the summary totals.
	 *
	 * @param ChatCounter[] $counters Root counters, already ordered and sampled by the caller.
	 * @param array<int, int> $aggregatedByChatId chatId => subtree-aggregated unread counter.
	 * @return array<int, array>
	 */
	public function formatChatCounterList(array $counters, array $aggregatedByChatId, int $userId): array
	{
		if (empty($counters))
		{
			return [];
		}

		// Only private ('P') chats can resolve to a companion dialog id, so the batch query gets
		// their ids alone: b_im_relation is indexed by CHAT_ID without MESSAGE_TYPE, and a large
		// group chat in the id list would make the DB scan all of its member relations for nothing.
		$privateChatIds = array_map(
			static fn (ChatCounter $counter): int => $counter->chatId,
			array_filter(
				$counters,
				static fn (ChatCounter $counter): bool => $counter->type->literal === Chat::IM_TYPE_PRIVATE,
			),
		);
		$privateDialogIds = PrivateChat::getDialogIds($privateChatIds, $userId);

		$items = [];
		foreach ($counters as $counter)
		{
			// Always a string: the dialogId input schemas of the other IM tools require a string,
			// so a numeric (private/self) dialogId from this summary must be passable to them as is.
			$dialogId = isset($privateDialogIds[$counter->chatId])
				? (string)$privateDialogIds[$counter->chatId]
				: ($counter->type->entityType === Chat::ENTITY_TYPE_FAVORITE ? (string)$userId : 'chat' . $counter->chatId)
			;

			$items[] = $this->formatChatCounter(
				$counter,
				$dialogId,
				$aggregatedByChatId[$counter->chatId] ?? $counter->counter,
			);
		}

		return $items;
	}

	private function formatChatCounter(ChatCounter $counter, string $dialogId, int $counterValue): array
	{
		return [
			'chatId' => $counter->chatId,
			'dialogId' => $dialogId,
			'counter' => $counterValue,
			'parentChatId' => $counter->parentChatId,
			'isMuted' => $counter->isMuted,
			'isMarkedAsUnread' => $counter->isMarkedAsUnread,
			'type' => $counter->type->getExtendedType(),
			'messageType' => $counter->type->literal,
		];
	}

	private function formatMessageText(Message $message): string
	{
		$text = $message->getMessage() ?? '';
		$text = Text::removeBbCodes($text, $message->hasFiles());

		return Text::decodeEmoji($text);
	}
}
