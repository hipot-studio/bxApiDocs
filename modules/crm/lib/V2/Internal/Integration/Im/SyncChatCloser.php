<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Im;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/**
 * Not final: a test seam.
 *
 * @internal
 */
class SyncChatCloser
{
	private const ERROR_CODE_IM_FAILURE = 'SYNC_CHAT_IM_FAILURE';

	public function detach(int $chatId): Result
	{
		return $this->withChat($chatId, static function (Chat $chat): Result {
			// Chat::save() skips a null ENTITY_ID, and setEntityId() turns any empty value into null.
			$updateResult = ChatTable::update($chat->getChatId(), ['ENTITY_ID' => null]);
			Chat::cleanCache($chat->getChatId());

			return (new Result())->addErrors($updateResult->getErrors());
		});
	}

	public function delete(int $chatId): Result
	{
		return $this->withChat($chatId, static function (Chat $chat): Result {
			return (new Result())->addErrors($chat->deleteChat()->getErrors());
		});
	}

	/**
	 * @param callable(Chat): Result $action
	 */
	private function withChat(int $chatId, callable $action): Result
	{
		if (!Loader::includeModule('im'))
		{
			return (new Result())->addError(new Error('Module im is not available', self::ERROR_CODE_IM_FAILURE));
		}

		try
		{
			$chat = Chat::getInstance($chatId);
			if (!$chat->getChatId())
			{
				return (new Result())->addError(new ChatError(ChatError::NOT_FOUND));
			}

			return $action($chat);
		}
		catch (\Throwable $exception)
		{
			return (new Result())->addError(new Error($exception->getMessage(), self::ERROR_CODE_IM_FAILURE));
		}
	}
}
