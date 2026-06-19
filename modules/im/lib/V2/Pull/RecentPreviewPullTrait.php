<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Pull;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\PrivateChat;
use Bitrix\Im\V2\Entity\User\UserCollection;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\MessagePopupItem;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Rest\RestAdapter;
use Bitrix\Main\Type\DateTime;

trait RecentPreviewPullTrait
{
	protected function getBaseRecentPreviewParams(
		Chat $chat,
		?Message $lastMessage = null,
		?DateTime $lastActivityDate = null,
	): array
	{
		$message = $this->resolveRecentPreviewMessage($chat, $lastMessage);
		$payload = $this->buildRecentPreviewRestPayload($chat, $message);

		return array_merge(
			$payload,
			[
				'chatId' => $chat->getId(),
				'chat' => $chat->toPullFormat(),
				'lastActivityDate' => $lastActivityDate ?? $message?->getDateCreate(),
				'counterType' => $chat->getCounterType(),
				'recentConfig' => $chat->getRecentConfig()->toPullFormat(),
				'parentChatId' => $this->chat->getParentChatId(),
			],
		);
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

	private function resolveRecentPreviewMessage(Chat $chat, ?Message $message): ?Message
	{
		if (($message?->getId() ?? 0) > 0)
		{
			return $message;
		}

		return $chat->getLastMessage();
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
