<?php

declare(strict_types=1);

namespace Bitrix\Call\Public\Service;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

final class SyncChatService
{
	/**
	 * Checks no rights: the caller is responsible for access and must not pass request input through.
	 *
	 * @return Result data on success: ['chat' => Chat, 'chatId' => int, 'dialogId' => string]
	 */
	public function createChat(
		int $userId,
		?string $type = null,
		?string $entityType = null,
		?string $entityId = null,
	): Result
	{
		$result = new Result();
		// The type defaults below, not in the signature: a Chat::IM_TYPE_* default is evaluated before im is loaded.
		if (!Loader::includeModule('im'))
		{
			return $result->addError(new Error('Module im is not available', 'im_module_unavailable'));
		}

		$params = [
			'TYPE' => $type ?? Chat::IM_TYPE_CHAT,
			'AUTHOR_ID' => $userId,
			'USERS' => [$userId],
			'SKIP_ANALYTICS' => 'Y',
		];
		if ($entityType !== null)
		{
			$params['ENTITY_TYPE'] = $entityType;
		}
		if ($entityId !== null)
		{
			$params['ENTITY_ID'] = $entityId;
		}

		$addResult = ChatFactory::getInstance()->addChat($params);
		if (!$addResult->isSuccess() || !$addResult->hasResult())
		{
			return $result->addError(new Error('Could not create chat', 'chat_create_failed'));
		}

		/** @var Chat $chat */
		$chat = $addResult->getResult()['CHAT'];
		$chatId = $chat->getChatId();
		if (!$chatId)
		{
			return $result->addError(new Error('Chat ID is missing after creation', 'chat_id_missing'));
		}

		return $result->setData([
			'chat' => $chat,
			'chatId' => $chatId,
			'dialogId' => $chat->getDialogId(),
		]);
	}
}
