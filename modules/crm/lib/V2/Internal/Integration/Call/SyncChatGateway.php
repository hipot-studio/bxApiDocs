<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Call;

use Bitrix\Call\Public\Service\SyncChatService;
use Bitrix\Call\Settings;
use Bitrix\Crm\V2\Internal\Integration\Im\SyncChatType;
use Bitrix\Im\V2\Chat;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/**
 * Not final: a test seam for the availability checks.
 *
 * @internal
 */
class SyncChatGateway
{
	public const CHAT_ENTITY_TYPE = SyncChatType::ENTITY_TYPE;

	public function isModuleAvailable(): bool
	{
		return Loader::includeModule('call') && class_exists(SyncChatService::class);
	}

	public function isSyncPresetEnabled(): bool
	{
		return $this->isModuleAvailable() && Settings::isSyncPresetEnabled();
	}

	/**
	 * @return Result data on success: ['chatId' => int, 'dialogId' => string]
	 */
	public function createChat(int $userId, int $activityId): Result
	{
		$result = new Result();
		if (!$this->isModuleAvailable())
		{
			return $result->addError(new Error('Module call has no sync chat service', 'call_module_unavailable'));
		}
		// Chat::IM_TYPE_EXTERNAL below needs im loaded, and module call does not load it itself.
		if (!Loader::includeModule('im'))
		{
			return $result->addError(new Error('Module im is not available', 'im_module_unavailable'));
		}

		$chatResult = (new SyncChatService())->createChat(
			$userId,
			Chat::IM_TYPE_EXTERNAL,
			self::CHAT_ENTITY_TYPE,
			(string)$activityId,
		);
		if (!$chatResult->isSuccess())
		{
			return $result->addErrors($chatResult->getErrors());
		}

		return $result->setData([
			'chatId' => $chatResult->getData()['chatId'],
			'dialogId' => $chatResult->getData()['dialogId'],
		]);
	}
}
