<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Pull;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\PrivateChat;
use Bitrix\Im\V2\Entity\User\UserCollection;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\MessagePopupItem;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Recent\PreviewSource\PreviewSource;
use Bitrix\Im\V2\Recent\RecentProvider;
use Bitrix\Im\V2\Rest\RestAdapter;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Type\DateTime;

trait RecentPreviewPullTrait
{
	protected function getBaseRecentPreviewParams(
		Chat $chat,
		?Message $lastMessage,
		?DateTime $lastActivityDate,
	): array
	{
		$payload = $this->buildRecentPreviewRestPayload($chat, $this->resolveRecentPreviewMessage($lastMessage));

		return array_merge(
			$payload,
			[
				'chatId' => $chat->getId(),
				'chat' => $chat->toPullFormat(),
				'lastActivityDate' => $lastActivityDate,
				'counterType' => $chat->getCounterType(),
				'recentConfig' => $chat->getRecentConfig()->toPullFormat(),
				'parentChatId' => $this->chat->getParentChatId(),
			],
		);
	}

	/**
	 * Resolves the date for a per-user pull event (ChatPin, ChatMute, ...): user's existing recent
	 * item date if there is one, otherwise the chat's last message creation date as a fallback
	 * for the case when the user hasn't loaded the chat yet and the event itself adds it to recent.
	 */
	protected function resolveUserDateLastActivity(Chat $chat, int $userId): ?DateTime
	{
		$chatId = $chat->getId();
		$item = $chatId !== null
			? ServiceLocator::getInstance()->get(RecentProvider::class)->getItem($userId, $chatId)
			: null
		;

		return $item?->getDateLastActivity() ?? $chat->getLastMessage()?->getDateCreate();
	}

	protected function getRecentPreviewUserDiffParams(Chat $chat, int $userId): array
	{
		$dialogId = $chat->getDialogId($userId);

		return [
			'dialogId' => $dialogId,
			'chat' => [
				'dialogId' => $dialogId,
			],
		];
	}

	/**
	 * @return array message override (+ normalized `chats` for a child source) to be deep-merged over the base pull params
	 */
	protected function getCollabPreviewUserDiffParams(Chat $chat, PreviewSource $previewSource): array
	{
		if (!$this->hasChildPreviewSource($chat, $previewSource))
		{
			return [];
		}

		$sourceChat = Chat::getInstance($previewSource->sourceChatId);
		$sourceMessage = new Message($previewSource->messageId);

		return $this->buildCollabPreviewDiff($chat, $previewSource, $sourceChat, $sourceMessage);
	}

	private function hasChildPreviewSource(Chat $chat, PreviewSource $previewSource): bool
	{
		return $previewSource->sourceChatId !== $chat->getId();
	}

	/**
	 * Reuses the source chat/message already held by the send-path instead of re-hydrating them from DB.
	 */
	protected function getCollabPreviewUserDiffParamsFromObjects(
		Chat $chat,
		PreviewSource $previewSource,
		Chat $sourceChat,
		Message $sourceMessage,
	): array
	{
		return $this->buildCollabPreviewDiff($chat, $previewSource, $sourceChat, $sourceMessage);
	}

	/**
	 * @return array message override (+ normalized `chats` for a child source) to be deep-merged over the base pull params
	 */
	private function buildCollabPreviewDiff(
		Chat $chat,
		PreviewSource $previewSource,
		Chat $sourceChat,
		?Message $sourceMessage,
	): array
	{
		if (!$this->hasChildPreviewSource($chat, $previewSource))
		{
			return [];
		}

		$diff = [];

		$resolvedSourceMessage = $this->resolveRecentPreviewMessage($sourceMessage);
		if ($resolvedSourceMessage !== null)
		{
			// Build from the source chat, not the collab: the source message author may not be a member
			// of the collab main chat, so a collab-scoped users-block could miss them in the push.
			$messagePayload = $this->buildRecentPreviewRestPayload($sourceChat, $resolvedSourceMessage);
			$diff['message'] = $messagePayload['message'];
			$diff['users'] = $messagePayload['users'];
			$diff['files'] = $messagePayload['files'];
		}

		$diff['chats'] = [$previewSource->sourceChatId => $sourceChat->toPullFormat()];

		return $diff;
	}

	private function resolveRecentPreviewMessage(?Message $message): ?Message
	{
		return (($message?->getId() ?? 0) > 0) ? $message : null;
	}

	private function buildRecentPreviewRestPayload(Chat $chat, ?Message $message): array
	{
		// The explicit users entity wins over the message popup users in RestAdapter::toRestFormat (both
		// map to the `users` REST key), so it must carry the message author too, else it is dropped.
		$restAdapter = new RestAdapter($this->getUsersForRest($chat, $message));

		if ($message !== null)
		{
			$messages = MessagePopupItem::getInstanceMessages(
				MessageCollection::createFromArray([$message]),
				true,
			);
			$restAdapter->addEntities($messages);
		}

		$payload = $restAdapter->toRestFormat([
			'WITHOUT_OWN_REACTIONS' => true,
			'MESSAGE_ONLY_COMMON_FIELDS' => true,
		]);

		$payload += [
			'users' => [],
			'files' => [],
		];

		$payload['message'] = $this->extractRecentPreviewMessage($payload);
		unset($payload['messages']);

		return $payload;
	}

	private function getUsersForRest(Chat $chat, ?Message $message = null): UserCollection
	{
		$userIds = $chat instanceof PrivateChat
			? $chat->getRelations()->getUsers()->getIds()
			: [];

		// Only the message author (+ forward author) is needed to render the preview row. Avoid
		// Message::getUserIds(): it folds in mentions, and a `[USER=all]` mention would expand to the
		// whole chat membership, bloating the users-block of every recent-preview push.
		if ($message !== null)
		{
			$userIds = array_merge($userIds, $this->getPreviewMessageAuthorIds($message));
		}

		return new UserCollection(array_values(array_unique($userIds)));
	}

	/**
	 * Message author and, for a forward, the original author. Excludes mentions.
	 *
	 * @return int[]
	 */
	private function getPreviewMessageAuthorIds(Message $message): array
	{
		$userIds = [];

		if ($message->getAuthorId() !== 0)
		{
			$userIds[] = $message->getAuthorId();
		}

		$params = $message->getParams();
		if ($params->isSet(Params::FORWARD_USER_ID))
		{
			$forwardUserId = (int)$params->get(Params::FORWARD_USER_ID)->getValue();
			if ($forwardUserId !== 0)
			{
				$userIds[] = $forwardUserId;
			}
		}

		return $userIds;
	}

	private function extractRecentPreviewMessage(array $payload): ?array
	{
		$messages = $payload['messages'] ?? [];
		if (empty($messages))
		{
			return null;
		}

		return array_values($messages)[0];
	}
}
