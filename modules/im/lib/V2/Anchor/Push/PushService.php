<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Anchor\Push;

use Bitrix\Im\Common;
use Bitrix\Im\V2\Anchor\AnchorCollection;
use Bitrix\Im\V2\Anchor\AnchorItem;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\PrivateChat;
use Bitrix\Main\Loader;
use Bitrix\Pull\Event;

class PushService
{
	private const ADD_ANCHOR_EVENT = 'addAnchor';
	private const DELETE_ANCHOR_EVENT = 'deleteAnchor';
	private const DELETE_CHAT_ANCHORS_EVENT = 'deleteChatAnchors';
	private const DELETE_ALL_ANCHORS_EVENT = 'deleteAllAnchors';
	private const DELETE_ANCHORS_EVENT = 'deleteAnchors';

	public function addMulti(AnchorCollection $anchorCollection): void
	{
		$this->sendMulti(self::ADD_ANCHOR_EVENT, $anchorCollection);
	}

	public function add(AnchorItem $anchorItem): void
	{
		$this->send(self::ADD_ANCHOR_EVENT, $anchorItem);
	}

	public function deleteMulti(AnchorCollection $anchorCollection): void
	{
		foreach ($anchorCollection as $anchorItem)
		{
			$this->delete($anchorItem);
		}
	}

	public function delete(AnchorItem $anchorItem): void
	{
		$this->send(self::DELETE_ANCHOR_EVENT, $anchorItem);
	}

	public function deleteByChat(int $chatId, int $userId): void
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		$chat = Chat::getInstance($chatId);

		if ($chat instanceof Chat\PrivateChat)
		{
			$this->deleteByPrivateChat($chatId, $userId);

			return;
		}

		$parameters = [
			'dialogId' => $chat->getDialogId(),
			'chatId' => $chatId,
			'userId' => $userId,
		];

		$pull = [
			'module_id' => 'im',
			'command' => static::DELETE_CHAT_ANCHORS_EVENT,
			'params' => $parameters,
			'extra' => Common::getPullExtra(),
		];

		Event::add([$userId], $pull);
	}

	public function deleteAll(int $userId): void
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		$pull = [
			'module_id' => 'im',
			'command' => static::DELETE_ALL_ANCHORS_EVENT,
			'params' => ['userId' => $userId],
			'extra' => Common::getPullExtra(),
		];

		Event::add($userId, $pull);
	}

	public function deleteByChatIds(int $userId, array $chatIds): void
	{
		if (empty($chatIds) || !Loader::includeModule('pull'))
		{
			return;
		}

		$pull = [
			'module_id' => 'im',
			'command' => static::DELETE_ANCHORS_EVENT,
			'params' => [
				'chatIds' => array_values($chatIds),
			],
			'extra' => Common::getPullExtra(),
		];

		Event::add($userId, $pull);
	}

	private function send(string $eventName, AnchorItem $anchorItem): void
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		$chat = Chat::getInstance($anchorItem->getChatId());

		$parameters = [...$anchorItem->toRestFormat()];

		if ($chat instanceof Chat\PrivateChat)
		{
			$this->sendToPrivateChat($anchorItem, $eventName, $chat);

			return;
		}

		$parameters['dialogId'] = $chat->getDialogId();
		$pull = [
			'module_id' => 'im',
			'command' => $eventName,
			'params' => $parameters,
			'extra' => Common::getPullExtra(),
		];

		Event::add([$anchorItem->getUserId()], $pull);
	}

	private function sendMulti(string $eventName, AnchorCollection $anchorCollection): void
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		/** @var ?AnchorItem $firstAnchor */
		$firstAnchor = $anchorCollection->getAny();
		$chat = Chat::getInstance($firstAnchor->getChatId());

		if ($chat instanceof Chat\PrivateChat)
		{
			$this->sendToPrivateChat($firstAnchor, $eventName, $chat);
			return;
		}

		$recipientIds = $anchorCollection->getUserIdList();

		$parameters = $firstAnchor->toRestFormat();
		$parameters['dialogId'] = $chat->getDialogId();

		$pull = [
			'module_id' => 'im',
			'command' => $eventName,
			'params' => $parameters,
			'extra' => Common::getPullExtra(),
		];

		Event::add($recipientIds, $pull);
	}

	private function sendToPrivateChat(AnchorItem $anchorItem, string $eventName, PrivateChat $chat): void
	{
		$recipientId = $anchorItem->getUserId();
		$parameters = $anchorItem->toRestFormat();
		$parameters['dialogId'] = $chat->getCompanion($recipientId)->getId();

		Event::add($recipientId, [
			'module_id' => 'im',
			'command' => $eventName,
			'params' => $parameters,
			'extra' => Common::getPullExtra(),
		]);
	}

	private function deleteByPrivateChat(int $chatId, int $userId): void
	{
		/** @var Chat\PrivateChat $chat */
		$chat = Chat::getInstance($chatId);

		$parameters = [
			'dialogId' => $chat->getCompanion($userId)->getId(),
			'chatId' => $chatId,
			'userId' => $userId,
		];

		Event::add($userId, [
			'module_id' => 'im',
			'command' => static::DELETE_CHAT_ANCHORS_EVENT,
			'params' => $parameters,
			'extra' => Common::getPullExtra(),
		]);
	}
}
