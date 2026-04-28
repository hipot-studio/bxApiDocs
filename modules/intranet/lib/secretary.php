<?php
namespace Bitrix\Intranet;

use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mail\Integration\Im\Chat;

// workaround for ControlButton translations
Loc::loadMessages(__DIR__ . '/controlbutton.php');
Loc::loadMessages(__FILE__);

/**
 * @see \Bitrix\Intranet\ControlButton  moved from ControlButton
 */
class Secretary
{
	public static function createCalendarChat($calendarData, $userId, $parentCalendarData = []): int
	{
		if (!self::checkAccessForIm() || !self::checkAccessForCalendar())
		{
			throw new SystemException('create calendar chat: failed to load modules');
		}

		$chat = new \CIMChat(0);
		$chatFields = [
			'TITLE' => $calendarData['TITLE'],
			'TYPE' => IM_MESSAGE_CHAT,
			'ENTITY_TYPE' => \CCalendar::CALENDAR_CHAT_ENTITY_TYPE,
			'ENTITY_ID' => $calendarData['ID'],
			'SKIP_ADD_MESSAGE' => 'Y',
			'AUTHOR_ID' => $userId,
			'USERS' => $calendarData['NOT_DECLINED_IDS'] ?? $calendarData['USER_IDS'],
		];

		$chatId = $chat->add($chatFields);

		if ($chatId)
		{
			$pathToCalendar = \CCalendar::GetPathForCalendarEx($userId);
			$pathToEvent = \CHTTP::urlAddParams($pathToCalendar, ['EVENT_ID' => $calendarData['ID']]);
			$entryLinkTitle = '[url=' . $pathToEvent . ']' . $calendarData['TITLE'] . '[/url]';
			$chatMessageFields = [
				'FROM_USER_ID' => $userId,
				'MESSAGE' => Loc::getMessage(
					'INTRANET_CONTROL_BUTTON_CALENDAR_CHAT_FIRST_MESSAGE',
					[
						'#EVENT_TITLE#' => $entryLinkTitle,
						'#DATETIME_FROM#' => \CCalendar::Date(
							\CCalendar::Timestamp($calendarData['DATE_FROM']),
							$calendarData['DT_SKIP_TIME'] === 'N',
							true, true
						)
					]
				),
				'SYSTEM' => 'Y',
				'INCREMENT_COUNTER' => 'N',
				'PUSH' => 'Y',
				'TO_CHAT_ID' => $chatId,
				'SKIP_USER_CHECK' => 'Y',
				'SKIP_COMMAND' => 'Y'
			];

			\CIMChat::addMessage($chatMessageFields);

			$calendarData['MEETING']['CHAT_ID'] = $chatId;
			\CCalendar::SaveEvent([
				'arFields' => [
					'ID' => $calendarData['ID'],
					'MEETING' => $calendarData['MEETING']
				],
				'checkPermission' => false,
				'userId' => $calendarData['CREATED_BY']
			]);

			if ($parentCalendarData)
			{
				$parentCalendarData['MEETING']['CHAT_ID'] = $chatId;
				\CCalendar::SaveEvent([
					'arFields' => [
						'ID' => $parentCalendarData['ID'],
						'MEETING' => $parentCalendarData['MEETING']
					],
					'checkPermission' => false,
					'userId' => $parentCalendarData['CREATED_BY']
				]);
			}

			\CCalendar::ClearCache('event_list');
		}

		return $chatId;
	}

	public static function createTaskChat($taskData, $userId): int
	{
		if (!self::checkAccessForIm() || !self::checkAccessForCalendar())
		{
			throw new SystemException('create task chat: failed to load modules');
		}

		$chat = new \CIMChat(0);
		$chatFields = [
			'TITLE' => $taskData['TITLE'],
			'TYPE' => IM_MESSAGE_CHAT,
			'ENTITY_TYPE' => 'TASKS',
			'ENTITY_ID' => $taskData['ID'],
			'SKIP_ADD_MESSAGE' => 'Y',
			'AUTHOR_ID' => $userId,
			'USERS' => $taskData['USER_IDS']
		];

		$chatId = $chat->add($chatFields);

		if ($chatId)
		{
			$pathToTask = SITE_DIR . 'company/personal/user/' . $taskData['CREATED_BY'] . '/tasks/task/view/' . $taskData['ID'] . '/';
			$entryLinkTitle = '[url=' . $pathToTask . ']' . $taskData['TITLE'] . '[/url]';
			$chatMessageFields = [
				'FROM_USER_ID' => $userId,
				'MESSAGE' => Loc::getMessage(
					'INTRANET_CONTROL_BUTTON_TASK_CHAT_FIRST_MESSAGE',
					[
						'#TASK_TITLE#' => $entryLinkTitle,
					]
				),
				'SYSTEM' => 'Y',
				'INCREMENT_COUNTER' => 'N',
				'PUSH' => 'Y',
				'TO_CHAT_ID' => $chatId,
				'SKIP_USER_CHECK' => 'Y',
				'SKIP_COMMAND' => 'Y'
			];

			\CIMChat::addMessage($chatMessageFields);
		}

		return $chatId;
	}

	/**
	 * @throws SystemException
	 * @deprecated Use Bitrix\Mail\Integration\Im\Chat::createMailChat()
	 * @see Chat::createMailChat()
	 */
	public static function createMailChat(array $messageData, int $userId): ?int
	{
		if (!self::checkAccessForMail())
		{
			throw new SystemException('create mail chat: failed to load modules');
		}

		$createMailChatResult = Chat::createMailChat($messageData, $userId);
		if (!$createMailChatResult->isSuccess())
		{
			return null;
		}

		return $createMailChatResult->getData()['chatId'];
	}

	/**
	 * @throws SystemException
	 * @deprecated Use Bitrix\Mail\Integration\Im\Chat::postMailChatWelcomeMessage()
	 * @see Chat::postMailChatWelcomeMessage()
	 */
	public static function postMailChatWelcomeMessage(\Bitrix\Mail\Item\Message $message, int $chatId, int $userId): void
	{
		if (!self::checkAccessForMail())
		{
			throw new SystemException('post mail welcome message: failed to load modules');
		}

		Chat::postMailChatWelcomeMessage($message, $chatId, $userId);
	}

	public static function updateChatUsers($chatId, $addedUsers, $deletedUsers, array $parameters = []): void
	{
		if (!self::checkAccessForIm())
		{
			throw new SystemException('update chat: failed to load modules');
		}

		$chat = new \CIMChat(0);

		if (!empty($deletedUsers))
		{
			foreach ($deletedUsers as $key => $userId)
			{
				$chat->DeleteUser($chatId, $userId, false);
			}
		}

		if (!empty($addedUsers))
		{
			$hideHistory = isset($parameters['HIDE_HISTORY']) ? (bool)$parameters['HIDE_HISTORY'] : null;
			$chat->AddUser(chatId: $chatId, userId: $addedUsers, hideHistory: $hideHistory);
		}
	}

	public static function addUserToChat($chatId, $userId, $hideHistory = null): void
	{
		if (!self::checkAccessForIm())
		{
			throw new SystemException('update chat: failed to load modules');
		}

		$chat = new \CIMChat(0);
		$chat->AddUser($chatId, $userId, $hideHistory);
	}

	public static function isUserInChat($chatId, $userId = 0): bool
	{
		$chat = new \CIMChat($userId);
		$chatData = $chat->GetChatData(['ID' => $chatId]);
		if (isset($chatData['userInChat'][$chatId]) && in_array((int)$userId, $chatData['userInChat'][$chatId], true))
		{
			return true;
		}
		return false;
	}

	public static function getChatIdIfExists(int $entityId, string $entityType): ?int
	{
		if (!self::checkAccessForIm())
		{
			throw new SystemException('update chat: failed to load modules');
		}

		$chat = new \CIMChat(0);
		if ($chatId = $chat->getEntityChat($entityType, $entityId))
		{
			return $chatId;
		}

		return null;
	}

	private static function checkAccessForIm()
	{
		return Loader::includeModule('im');
	}

	private static function checkAccessForMail()
	{
		return Loader::includeModule('mail');
	}

	private static function checkAccessForCalendar()
	{
		return Loader::includeModule('calendar');
	}
}
