<?php
namespace Bitrix\StaffTrack\Internal\Integration\Im;

use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Result;
use Bitrix\StaffTrack\Public\Command\CheckInDto;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\StaffTrack\Dictionary\Option;
use Bitrix\StaffTrack\Service\OptionService;
use Bitrix\StaffTrack\Internal\Integration\HumanResources\DepartmentProvider;

class MessageService
{
	public const COMPONENT_MESSAGE_ID = 'CheckInMessage';
	private const BANNER_COMPONENT_ID = 'SupervisorEnableFeatureMessage';

	/**
	 * @param CheckInDto $checkInDto
	 * @return Result
	 * @throws LoaderException
	 * @throws \Exception
	 */
	public static function send(CheckInDto $checkInDto): Result
	{
		$result = new Result();
		if (!$checkInDto)
		{
			return $result->addError(new Error('parameter checkInDto is required'));
		}

		if (!self::isImModuleLoaded())
		{
			return $result->addError(new Error('IM module is not available'));
		}

		if (empty($checkInDto->dialogIds))
		{
			return $result->addError(new Error('Dialog ids not found'));
		}

		$userId = (int)CurrentUser::get()->getId();
		$messageIds = [];

		foreach ($checkInDto->dialogIds as $dialogId)
		{
			$chatId = self::getChatId($dialogId, $userId);
			if (empty($chatId))
			{
				continue;
			}

			self::sendBaseMessage($checkInDto, $dialogId, $chatId, $userId, $checkInDto->description);
			$messageId = self::sendGeoMessage($checkInDto, $dialogId, $userId, $checkInDto->description);
			if (!empty($messageId))
			{
				$messageIds[] = $messageId;
			}
		}

		$checkInDto->messageIds = $messageIds;

		return $result->setData([
			'messageIds' => $messageIds,
		]);
	}

	public static function createChatWithDepartmentHead(): Result
	{
		$result = new Result();
		$userId = (int)CurrentUser::get()->getId();
		$headId = DepartmentProvider::getHeadId($userId);
		if (!$headId)
		{
			return $result->addError(new Error('Department head not found'));
		}

		$createChatResult = Locator::getMessenger()->createChat([
			'ENTITY_TYPE' => 'SUPERVISOR_CHAT',
			'TITLE' => Loc::getMessage('STAFFTRACK_INTEGRATION_IM_CHAT_FEATURE_TITLE'),
			'SEND_GREETING_MESSAGES' => 'Y',
		]);
		if (!$createChatResult->isSuccess())
		{
			return $result->addErrors($createChatResult->getErrors());
		}

		$chatId = $createChatResult->getResult()['CHAT_ID'];


		self::addUserToChat($chatId, $headId);
		self::addBannerMessage($chatId);

		return $result->setData(['chatId' => $chatId]);
	}

	/**
	 * @param int $chatId
	 * @return void
	 */
	private static function addBannerMessage(int $chatId): void
	{
		\CIMMessenger::Add([
			'TO_CHAT_ID' => $chatId,
			'SYSTEM' => 'Y',
			'MESSAGE_TYPE' => 'C',
			'MESSAGE' => Loc::getMessage('STAFFTRACK_INTEGRATION_IM_CHAT_FEATURE_BANNER_MESSAGE'),
			'PARAMS' => [
				'COMPONENT_ID' => self::BANNER_COMPONENT_ID,
				'COMPONENT_PARAMS' => [
					'TOOL_ID' => 'checkIn',
				],
			],
		]);
	}

	/**
	 * @param int $chatId
	 * @param int $userId
	 * @return void
	 */
	private static function addUserToChat(int $chatId, int $userId): void
	{
		(new \CIMChat())->AddUser($chatId, [$userId]);
	}

	/**
	 * @param string $dialogId
	 * @param int $userId
	 * @return int
	 */
	private static function getChatId(string $dialogId, int $userId): int
	{
		return (int)\Bitrix\Im\Dialog::getChatId($dialogId, $userId);
	}

	private static function sendBaseMessage(CheckInDto $checkInDto, string $dialogId, int $chatId, int $userId, string $message): void
	{
		if (!empty($checkInDto->fileIds))
		{
			$fileIds = [];
			$params = [
				'USER_ID' => $userId,
			];

			foreach ($checkInDto->fileIds as $fileId)
			{
				$fileIds[] = 'disk' . $fileId;
			}

			\CIMDisk::UploadFileFromDisk(
				$chatId,
				$fileIds,
				$message,
				$params
			);
		}
		else
		{
			\CIMChat::AddMessage([
				'DIALOG_ID' => $dialogId,
				'FROM_USER_ID' => $userId,
				'MESSAGE' => $message,
			]);
		}
	}

	private static function sendGeoMessage(CheckInDto $checkInDto, string $dialogId, int $userId, string $message): int
	{
		$componentParams = [
			'URL' => $checkInDto->snapshotUrl,
		];

		$componentParams['LOCATION'] = $checkInDto->address;

		$messageId = \CIMChat::AddMessage([
			'DIALOG_ID' => $dialogId,
			'FROM_USER_ID' => $userId,
			'MESSAGE' => $message,
			'PUSH' => 'N',
			'SKIP_COUNTER_INCREMENTS' => 'Y',
			'PARAMS' => [
				'NOTIFY' => 'N',
				'COMPONENT_ID' => self::COMPONENT_MESSAGE_ID,
				'COMPONENT_PARAMS' => $componentParams,
			],
		]);

		return (int)$messageId;
	}

	public static function saveLastSelectedDialogId(int $userId, array $dialogIds): void
	{
		$dialogId = $dialogIds[0] ?? null;
		if ($dialogId === null)
		{
			return;
		}

		OptionService::getInstance()->save(
			$userId,
			Option::LAST_SELECTED_DIALOG_ID,
			$dialogId,
		);
	}

	/**
	 * @param string|null $dialogId
	 * @param int $userId
	 * @return array
	 * @throws LoaderException
	 */
	public static function getDialogInfo(?string $dialogId, int $userId): array
	{
		$defaultResult = [
			'dialogId' => null,
			'dialogName' => null,
		];

		if (!self::isImModuleLoaded())
		{
			return $defaultResult;
		}

		if (empty($dialogId))
		{
			return $defaultResult;
		}

		if (\Bitrix\Im\Common::isChatId($dialogId))
		{
			$dialogName = \Bitrix\Im\Dialog::getTitle($dialogId, $userId);
		}
		else
		{
			$chatId = \CIMMessage::GetChatId($dialogId, $userId);
			if (!$chatId)
			{
				return $defaultResult;
			}

			$dialogName = \Bitrix\Im\User::getInstance($dialogId)->getFullName(false);
		}

		return [
			'dialogId' => $dialogId,
			'dialogName' => $dialogName,
		];
	}

	/**
	 * @return bool
	 * @throws \Bitrix\Main\LoaderException
	 */
	private static function isImModuleLoaded(): bool
	{
		return Loader::includeModule('im');
	}
}