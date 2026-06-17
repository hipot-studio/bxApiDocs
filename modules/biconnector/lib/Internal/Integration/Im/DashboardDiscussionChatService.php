<?php

namespace Bitrix\BIConnector\Internal\Integration\Im;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Chat\NullChat;
use Bitrix\Im\V2\Relation\DeleteUserConfig;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class DashboardDiscussionChatService
{
	private const ENTITY_TYPE = 'BICONNECTOR_SUPERSET_DASHBOARD';

	public function createOrGetUniqueChat(
		int $dashboardId,
		string $dashboardTitle,
		int $authorId,
		array $initialUserIds,
	): Result
	{
		$result = new Result();
		if (!Loader::includeModule('im'))
		{
			return $result->addError(
				new Error(Loc::getMessage('BICONNECTOR_INTERNAL_INTEGRATION_IM_DASHBOARD_DISCUSSION_CHAT_ERROR_IM_MODULE')),
			);
		}

		$initialUserIds = $this->normalizeUserIds($initialUserIds);
		$chatResult = ChatFactory::getInstance()->addUniqueChat([
			'TITLE' => Loc::getMessage(
				'BICONNECTOR_INTERNAL_INTEGRATION_IM_DASHBOARD_DISCUSSION_CHAT_TITLE',
				[
					'#DASHBOARD_TITLE#' => $dashboardTitle,
				],
			),
			'SKIP_ADD_MESSAGE' => 'Y',
			'TYPE' => Chat::IM_TYPE_CHAT,
			'ENTITY_TYPE' => self::ENTITY_TYPE,
			'ENTITY_ID' => $dashboardId,
			'USERS' => $initialUserIds,
			'AUTHOR_ID' => $authorId,
		]);

		if (!$chatResult->isSuccess())
		{
			return $result->addErrors($chatResult->getErrors());
		}

		$chatId = (int)$chatResult->getChatId();
		if ($chatId <= 0)
		{
			return $result->addError(
				new Error(Loc::getMessage('BICONNECTOR_INTERNAL_INTEGRATION_IM_DASHBOARD_DISCUSSION_CHAT_ERROR_CREATE')),
			);
		}

		$isCreated = !(bool)($chatResult->getData()['RESULT']['ALREADY_EXISTS'] ?? false);
		if ($isCreated)
		{
			$messageResult = $this->sendFirstPublicMessage($chatId, $dashboardTitle);
			if (!$messageResult->isSuccess())
			{
				return $result->addErrors($messageResult->getErrors());
			}
		}

		return $result->setData([
			'chatId' => $chatId,
			'dialogId' => "chat{$chatId}",
			'isCreated' => $isCreated,
		]);
	}

	public function addUsersToChat(int $chatId, array $userIds): Result
	{
		$result = new Result();
		if (!Loader::includeModule('im'))
		{
			return $result->addError(
				new Error(Loc::getMessage('BICONNECTOR_INTERNAL_INTEGRATION_IM_DASHBOARD_DISCUSSION_CHAT_ERROR_IM_MODULE')),
			);
		}

		$userIds = $this->normalizeUserIds($userIds);
		if (empty($userIds))
		{
			return $result;
		}

		$chat = Chat::getInstance($chatId);
		if ($chat instanceof NullChat)
		{
			return $result->addError(
				new Error(Loc::getMessage('BICONNECTOR_INTERNAL_INTEGRATION_IM_DASHBOARD_DISCUSSION_CHAT_ERROR_CHAT_NOT_FOUND')),
			);
		}

		$addUserResult = (new \CIMChat(0))->AddUser(
			$chatId,
			$userIds,
			false,
			true,
			false,
			false,
			true,
		);
		if (!$addUserResult)
		{
			return $result->addError(
				new Error(Loc::getMessage('BICONNECTOR_INTERNAL_INTEGRATION_IM_DASHBOARD_DISCUSSION_CHAT_ERROR_ADD_USERS')),
			);
		}

		return $result;
	}

	public function getChatUserIds(int $chatId): Result
	{
		$result = new Result();
		if (!Loader::includeModule('im'))
		{
			return $result->addError(
				new Error(Loc::getMessage('BICONNECTOR_INTERNAL_INTEGRATION_IM_DASHBOARD_DISCUSSION_CHAT_ERROR_IM_MODULE')),
			);
		}

		$chat = Chat::getInstance($chatId);
		if ($chat instanceof NullChat)
		{
			return $result->addError(
				new Error(Loc::getMessage('BICONNECTOR_INTERNAL_INTEGRATION_IM_DASHBOARD_DISCUSSION_CHAT_ERROR_CHAT_NOT_FOUND')),
			);
		}

		return $result->setData([
			'userIds' => $this->normalizeUserIds($chat->getRelations()->getUserIds()),
		]);
	}

	public function getChatUserIdsMap(array $chatIds): Result
	{
		$result = new Result();
		if (!Loader::includeModule('im'))
		{
			return $result->addError(
				new Error(Loc::getMessage('BICONNECTOR_INTERNAL_INTEGRATION_IM_DASHBOARD_DISCUSSION_CHAT_ERROR_IM_MODULE')),
			);
		}

		$chatIds = $this->normalizeIds($chatIds);
		if (empty($chatIds))
		{
			return $result->setData([
				'userIdsByChat' => [],
			]);
		}

		$existingChatIds = ChatTable::getList([
			'select' => ['ID'],
			'filter' => ['@ID' => $chatIds],
		])->fetchAll();
		$existingChatIds = $this->normalizeIds(array_column($existingChatIds, 'ID'));

		$userIdsByChat = array_fill_keys($existingChatIds, []);
		if (!empty($existingChatIds))
		{
			$relationRows = RelationTable::getList([
				'select' => ['CHAT_ID', 'USER_ID'],
				'filter' => ['@CHAT_ID' => $existingChatIds],
				'order' => ['CHAT_ID' => 'ASC', 'USER_ID' => 'ASC'],
			]);
			while ($relationRow = $relationRows->fetch())
			{
				$chatId = (int)($relationRow['CHAT_ID'] ?? 0);
				$userId = (int)($relationRow['USER_ID'] ?? 0);
				if ($chatId <= 0 || $userId <= 0)
				{
					continue;
				}

				$userIdsByChat[$chatId][$userId] = $userId;
			}

			foreach ($userIdsByChat as $chatId => $userIds)
			{
				$userIdsByChat[$chatId] = array_values($userIds);
			}
		}

		$missingChatIds = array_diff($chatIds, $existingChatIds);
		foreach ($missingChatIds as $missingChatId)
		{
			$result->addError(
				new Error(Loc::getMessage('BICONNECTOR_INTERNAL_INTEGRATION_IM_DASHBOARD_DISCUSSION_CHAT_ERROR_CHAT_NOT_FOUND')),
			);
		}

		return $result->setData([
			'userIdsByChat' => $userIdsByChat,
		]);
	}

	public function removeUsersFromChat(int $chatId, array $userIds): Result
	{
		$result = new Result();
		if (!Loader::includeModule('im'))
		{
			return $result->addError(
				new Error(Loc::getMessage('BICONNECTOR_INTERNAL_INTEGRATION_IM_DASHBOARD_DISCUSSION_CHAT_ERROR_IM_MODULE')),
			);
		}

		$userIds = $this->normalizeUserIds($userIds);
		if (empty($userIds))
		{
			return $result;
		}

		$chat = Chat::getInstance($chatId);
		if ($chat instanceof NullChat)
		{
			return $result->addError(
				new Error(Loc::getMessage('BICONNECTOR_INTERNAL_INTEGRATION_IM_DASHBOARD_DISCUSSION_CHAT_ERROR_CHAT_NOT_FOUND')),
			);
		}

		foreach ($userIds as $userId)
		{
			$deleteResult = $chat->deleteUser(
				$userId,
				new DeleteUserConfig(withMessage: false, withNotification: false),
			);
			if (!$deleteResult->isSuccess())
			{
				$result->addError(
					new Error(Loc::getMessage('BICONNECTOR_INTERNAL_INTEGRATION_IM_DASHBOARD_DISCUSSION_CHAT_ERROR_REMOVE_USERS')),
				);
			}
		}

		return $result;
	}

	private function sendFirstPublicMessage(int $chatId, string $dashboardTitle): Result
	{
		$result = new Result();
		$messageText = trim(
			Loc::getMessage(
				'BICONNECTOR_INTERNAL_INTEGRATION_IM_DASHBOARD_DISCUSSION_CHAT_PUBLIC_MESSAGE',
				[
					'#DASHBOARD_TITLE#' => $dashboardTitle,
				],
			),
		);

		if ($messageText === '')
		{
			return $result;
		}

		$messageId = \CIMChat::AddSystemMessage([
			'CHAT_ID' => $chatId,
			'MESSAGE' => $messageText,
		]);

		if (!$messageId)
		{
			return $result->addError(
				new Error(Loc::getMessage('BICONNECTOR_INTERNAL_INTEGRATION_IM_DASHBOARD_DISCUSSION_CHAT_ERROR_SYSTEM_MESSAGE')),
			);
		}

		return $result;
	}

	private function normalizeUserIds(array $userIds): array
	{
		return $this->normalizeIds($userIds);
	}

	private function normalizeIds(array $ids): array
	{
		$normalizedIds = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$normalizedIds[$id] = $id;
			}
		}

		return array_values($normalizedIds);
	}
}
