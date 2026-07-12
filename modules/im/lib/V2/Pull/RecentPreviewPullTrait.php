<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Pull;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\PrivateChat;
use Bitrix\Im\V2\Entity\User\UserCollection;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\MessagePopupItem;
use Bitrix\Im\V2\MessageCollection;
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

	private function resolveRecentPreviewMessage(?Message $message): ?Message
	{
		return (($message?->getId() ?? 0) > 0) ? $message : null;
	}

	private function buildRecentPreviewRestPayload(Chat $chat, ?Message $message): array
	{
		$restAdapter = new RestAdapter($this->getUsersForRest($chat));

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

	private function getUsersForRest(Chat $chat): UserCollection
	{
		if ($chat instanceof PrivateChat)
		{
			return $chat->getRelations()->getUsers();
		}

		return new UserCollection();
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
