<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Im;

use Bitrix\Im;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Message\Color\Color;
use Bitrix\Im\V2\Relation\AddUsersConfig;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\UserTable;

class ConnectionRequestChat
{
	public const ENTITY_TYPE = 'MAIL_CONNECTION_REQUEST_CHAT';

	public function getOrCreateChat(int $requesterId, array $adminIds): Result
	{
		$result = new Result();

		if (!Loader::includeModule('im'))
		{
			return $result->addError(new Error('IM module is not installed'));
		}

		$userName = $this->getUserFormattedName($requesterId);

		$chatTitle = Loc::getMessage(
			'MAIL_CONNECTION_REQUEST_CHAT_TITLE',
			['#USER_NAME#' => $userName],
		);

		$factory = ChatFactory::getInstance()->withContextUser($requesterId);
		$chatResult = $factory->addUniqueChat([
			'TYPE' => Im\V2\Chat::IM_TYPE_CHAT,
			'ENTITY_TYPE' => self::ENTITY_TYPE,
			'ENTITY_ID' => (string)$requesterId,
			'USERS' => array_merge([$requesterId], $adminIds),
			'TITLE' => $chatTitle,
			'SKIP_ADD_MESSAGE' => 'Y',
			'AUTHOR_ID' => $requesterId,
		]);

		if (!$chatResult->isSuccess())
		{
			return $result->addErrors($chatResult->getErrors());
		}

		$chatData = $chatResult->getData();
		$result->setData([
			'chatId' => $chatResult->getChatId(),
			'alreadyExists' => (bool)($chatData['ALREADY_EXISTS'] ?? $chatData['RESULT']['ALREADY_EXISTS'] ?? false),
		]);

		return $result;
	}

	public function sendRequestMessage(int $chatId, int $requesterId, string $comment): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		if ($comment !== '')
		{
			$messageText = Loc::getMessage(
				'MAIL_CONNECTION_REQUEST_CHAT_MESSAGE_NEW_REQUEST_WITH_COMMENT',
				['#COMMENT#' => $comment],
			);
		}
		else
		{
			$messageText = Loc::getMessage('MAIL_CONNECTION_REQUEST_CHAT_MESSAGE_NEW_REQUEST');
		}

		$keyboard = [[
			'TEXT' => Loc::getMessage('MAIL_CONNECTION_REQUEST_CHAT_BUTTON_CONNECT'),
			'LINK' => '/mail/mailbox-list?CONNECTION_REQUESTS=Y&apply_filter=Y',
			'BG_COLOR_TOKEN' => Color::PRIMARY->value,
			'TEXT_COLOR' => '#fff',
			'DISPLAY' => 'LINE',
		]];

		$this->sendMessage($chatId, $requesterId, $messageText, $keyboard);
	}

	public function sendRejectedMessage(int $chatId, int $adminId): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		$adminName = $this->getUserFormattedName($adminId);

		$this->sendMessage($chatId, $adminId, Loc::getMessage(
			'MAIL_CONNECTION_REQUEST_CHAT_MESSAGE_REJECTED',
			['#ADMIN_NAME#' => $adminName],
		));
	}

	public function sendCompletedMessage(int $chatId, int $adminId, string $email): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		$adminName = $this->getUserFormattedName($adminId);

		$this->sendMessage($chatId, $adminId, Loc::getMessage(
			'MAIL_CONNECTION_REQUEST_CHAT_MESSAGE_COMPLETED',
			['#EMAIL#' => $email, '#ADMIN_NAME#' => $adminName],
		));
	}

	public function sendCancelledMessage(int $chatId, int $requesterId): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		$this->sendMessage($chatId, $requesterId, Loc::getMessage('MAIL_CONNECTION_REQUEST_CHAT_MESSAGE_CANCELLED'));
	}

	private function sendMessage(
		int $chatId,
		int $authorId,
		string $messageText,
		?array $keyboard = null,
	): void
	{
		$chat = Im\V2\Chat::getInstance($chatId);
		if ($chat === null)
		{
			return;
		}

		$message = new Im\V2\Message();
		$message
			->setMessage($messageText)
			->setAuthorId($authorId)
			->setContextUser($authorId)
		;

		if ($keyboard !== null)
		{
			$message->setKeyboard($keyboard);
		}

		$chat->withContextUser($authorId)->sendMessage($message);
	}

	public function ensureAdminsInChat(int $chatId, array $adminIds): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		if (empty($adminIds))
		{
			return;
		}

		$chat = Im\V2\Chat::getInstance($chatId);
		$currentUserIds = $chat->getRelations()->getUserIds();

		$missingAdminIds = array_diff($adminIds, $currentUserIds);

		if (empty($missingAdminIds))
		{
			return;
		}

		$chat->addUsers(
			array_values($missingAdminIds),
			new AddUsersConfig(hideHistory: false, withMessage: false),
		);
	}

	private function getUserFormattedName(int $userId): string
	{
		$user = UserTable::getList([
			'select' => ['NAME', 'LAST_NAME'],
			'filter' => ['=ID' => $userId],
			'limit' => 1,
		])->fetch();

		if (!$user)
		{
			return '';
		}

		return \CUser::FormatName(\CSite::GetNameFormat(), $user);
	}
}
