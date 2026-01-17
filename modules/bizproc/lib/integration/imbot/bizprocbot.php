<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Integration\ImBot;

use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\Public\Entity\Document\Workflow;
use Bitrix\Bizproc\Result;
use Bitrix\Bizproc\Starter\Dto\DocumentDto;
use Bitrix\Bizproc\Starter\Enum\Scenario;
use Bitrix\Bizproc\Starter\Result\StartResult;
use Bitrix\Bizproc\Starter\Starter;
use Bitrix\Im\Bot;
use Bitrix\Im\Model\BotTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Security\Random;
use Bitrix\ImBot\Bot\Base;
use Bitrix\Main\UserTable;

Loader::includeModule('imbot');

class BizprocBot extends Base
{
	public const MODULE_ID = 'bizproc';
	public const REGISTER_PARAM_NAME = 'name';
	public const REGISTER_PARAM_POSITION = 'position';
	public const REGISTER_PARAM_AVATAR = 'avatar';
	public const REGISTER_PARAM_CODE = 'code';

	public const FIELD_BOT_ID = 'BOT_ID';
	public const FIELD_CLASS = 'CLASS';
	public const FIELD_NAME = 'NAME';
	public const FIELD_MESSAGE = 'MESSAGE';
	public const FIELD_DIALOG_ID = 'DIALOG_ID';
	public const FIELD_MESSAGE_TYPE = 'MESSAGE_TYPE';
	public const FIELD_FROM_USER_ID = 'FROM_USER_ID';
	public const FIELD_ID = 'ID';
	public const FIELD_CODE = 'CODE';
	private const IM_BOT_NEW_MESSAGE_TRIGGER = 'ImBotNewMessageTrigger';
	private const FIELD_DOCUMENT_ID = 'DOCUMENT_ID';
	private const MESSAGE_FIELD_TO_CHAT_ID = 'TO_CHAT_ID';
	private const MESSAGE_FIELD_SYSTEM = 'SYSTEM';

	public const ACCESS_DENIED_CODE = 403;

	/**
	 * @var array<string, ?int> ['code' => 123, ...]
	 */
	private static array $botIdsByCodes = [];

	/**
	 * @param array{name: string, position?: string, code?: string, avatar?: string} $params
	 *
	 * @return int
	 * @throws \Bitrix\Main\LoaderException
	 */
	public static function register(array $params = []): int
	{
		if (!Loader::includeModule('im') || !Loader::includeModule('imbot'))
		{
			return 0;
		}

		if (!self::isNameFilledInParams($params))
		{
			return 0;
		}

		$lockName = '';
		$code = (string)($params[self::REGISTER_PARAM_CODE] ?? '');
		if ($code !== '')
		{
			$lockName = 'bizprocbot_'.md5($code);
			if (!Application::getConnection()->lock($lockName))
			{
				return 0;
			}

			try
			{
				$botId = self::getBotIdByCode($code, false);
				if ($botId)
				{
					return 0;
				}
			}
			finally
			{
				Application::getConnection()->unlock($lockName);
			}
		}

		try
		{
			$code =  $code ?: Random::getStringByAlphabet(10, Random::ALPHABET_ALPHALOWER);
			$avatarId = (int)($params[self::REGISTER_PARAM_AVATAR] ?? 0);
			$botId = Bot::register([
				'CODE' => $code,
				'TYPE' => Bot::TYPE_BOT,
				'MODULE_ID' => self::MODULE_ID,
				'CLASS' => self::class,
				'METHOD_MESSAGE_ADD' => 'onMessageAdd',/** @see BizprocBot::onMessageAdd */
				'METHOD_MESSAGE_UPDATE' => 'onMessageUpdate',/** @see BizprocBot::onMessageUpdate */
				'METHOD_MESSAGE_DELETE' => 'onMessageDelete',/** @see BizprocBot::onMessageDelete */
				'METHOD_BOT_DELETE' => 'onBotDelete',/** @see BizprocBot::onBotDelete */
				'METHOD_WELCOME_MESSAGE' => 'onChatStart',/** @see BizprocBot::onChatStart */
				'PROPERTIES' => [
					'NAME' => (string)($params[self::REGISTER_PARAM_NAME] ?? ''),
					'WORK_POSITION' => (string)($params[self::REGISTER_PARAM_POSITION] ?? ''),
					'PERSONAL_PHOTO' => self::isCorrectAvatarFileId($avatarId) ? \CFile::makeFileArray($avatarId) : null,
				],
			]);

			if ($botId)
			{
				self::$botIdsByCodes[$code] = (int)$botId;
			}

			return (int)$botId;
		}
		finally
		{
			if ($lockName)
			{
				Application::getConnection()->unlock($lockName);
			}
		}
	}

	public static function unRegister(int $botId = 0): bool
	{
		if (!Loader::includeModule('im'))
		{
			return false;
		}

		if ($botId)
		{
			self::$botIdsByCodes = [];

			return Bot::unRegister([self::FIELD_BOT_ID => $botId]);
		}

		return self::unregisterAll();
	}

	private static function unregisterAll(): bool
	{
		$result = BotTable::query()
		   ->where(self::FIELD_CLASS, self::class)
		   ->setSelect([self::FIELD_BOT_ID])
		   ->exec()
		;

		$somethingRemoved = false;
		while ($bot = $result->fetch())
		{
			$removed = Bot::unRegister([self::FIELD_BOT_ID => $bot[self::FIELD_BOT_ID] ?? 0]);
			if (!$somethingRemoved && $removed)
			{
				$somethingRemoved = true;
			}
		}

		self::$botIdsByCodes = [];

		return $somethingRemoved;
	}

	public static function onMessageAdd($messageId, $messageFields): bool
	{
		if (!Loader::includeModule('im') || !Loader::includeModule('imbot'))
		{
			return false;
		}

		$messageFields = (array)$messageFields;
		if (!self::canBotAnswer($messageFields))
		{
			return false;
		}

		$botId = (int)($messageFields[self::FIELD_BOT_ID] ?? 0);
		$chatId = (int)($messageFields[self::MESSAGE_FIELD_TO_CHAT_ID] ?? $messageFields['CHAT_ID'] ?? 0);
		if ($botId === 0 || $chatId === 0)
		{
			return false;
		}

		$preparedMessageFields = $messageFields;
		$preparedMessageFields[self::FIELD_ID] = $messageId;
		$targetMessage = (new Message())->fill(['PARAMS' => $preparedMessageFields['PARAMS'] ?? []]);
		$targetMessage->load($preparedMessageFields);
		if ($targetMessage->getAuthorId() === $botId)
		{
			return false;
		}

		self::readMessage($chatId, $botId, $targetMessage);
		self::sendTyping($chatId, $botId);

		$sentResult = self::handleMessageSent(
			new \Bitrix\Bizproc\Starter\Event(
				parameters: $preparedMessageFields,
			),
		);

		$triggerErrorSent = false;
		if (!$sentResult->isSuccess())
		{
			$triggerErrorSent = static::sendTriggerErrorMessageIfNeeded($messageFields, $sentResult);
		}

		if (!self::isSomeWorkflowStarted($sentResult) && !$triggerErrorSent)
		{
			$dialogId = (string)($messageFields[self::FIELD_DIALOG_ID] ?? '');
			self::sendError($botId, $dialogId, (string)Loc::getMessage('BIZPROC_IMBOT_BIZPROCBOT_NO_TRIGGERS_ERROR'));
		}

		return true;
	}

	public static function onChatStart($dialogId, $joinFields): bool
	{
		if (!Loader::includeModule('im'))
		{
			return false;
		}

		$joinFields = (array)$joinFields;
		$botId = (int)($joinFields[self::FIELD_BOT_ID] ?? 0);
		if (empty($botId))
		{
			return false;
		}

		if (self::canBotAnswer($joinFields))
		{
			// @TODO add welcome text

			return true;
		}

		static::addMessage($botId, $dialogId, Loc::getMessage('BIZPROC_IMBOT_BIZPROCBOT_GROUP_CHAT_DENIED'));
		self::leaveChat($dialogId, $botId);

		return false;
	}

	private static function canBotAnswer(array $fields): bool
	{
		$chatType = $fields[self::FIELD_MESSAGE_TYPE] ?? null;

		return in_array(
			$chatType,
			[
				IM_MESSAGE_CHAT,
				IM_MESSAGE_PRIVATE,
			],
			true,
		);
	}

	private static function leaveChat(string $dialogId, int $botId): void
	{
		$chat = Chat::getInstance((int)str_replace('chat', '', $dialogId));
		$chat->deleteUser($botId);
	}

	private static function addMessage(int $botId, string $dialogId, string $message): bool
	{
		/** @var bool|int $messageId */
		$messageId = Bot::addMessage(
			[
				self::FIELD_BOT_ID => $botId,
			],
			[
				self::FIELD_DIALOG_ID => $dialogId,
				self::FIELD_MESSAGE => $message,
			],
		);

		return (bool)$messageId;
	}

	/**
	 * Get bizproc bot names by ids
	 *
	 * @param int $limit
	 *
	 * @return array<int, string>
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function getBotNamesByIds(int $limit = 100): array
	{
		$result = BotTable::query()
			->where(self::FIELD_CLASS, self::class)
			->registerRuntimeField(
			  'USER',
			  new Reference(
				  'USER',
				  UserTable::class,
				  Join::on('this.BOT_ID', 'ref.ID'),
				  ['join_type' => Join::TYPE_INNER]
			  )
			)
			->setSelect([
			  self::FIELD_BOT_ID => self::FIELD_BOT_ID,
			  self::FIELD_NAME => 'USER.NAME',
			])
			->addOrder(self::FIELD_BOT_ID)
			->setLimit($limit)
			->exec()
		;

		$namesByIds = [];
		while ($row = $result->fetch())
		{
			$id = $row[self::FIELD_BOT_ID] ?? null;
			$name = $row[self::FIELD_NAME] ?? null;
			if ($id && $name)
			{
				$namesByIds[$id] = $name;
			}
		}

		return $namesByIds;
	}

	public static function isExistsById(int $botId): bool
	{
		$row = BotTable::query()
			->where(self::FIELD_CLASS, self::class)
			->where(self::FIELD_BOT_ID, $botId)
			->setLimit(1)
			->setSelect([self::FIELD_BOT_ID])
			->fetch()
		;

		return isset($row[self::FIELD_BOT_ID]);
	}

	private static function sendTriggerErrorMessageIfNeeded(array $messageFields, \Bitrix\Bizproc\Result $sentResult): bool
	{
		foreach ($sentResult->getErrors() as $error)
		{
			if ($error->getCode() === self::ACCESS_DENIED_CODE)
			{
				return self::sendError(
					(int)$messageFields[self::FIELD_BOT_ID],
					(string)$messageFields[self::FIELD_DIALOG_ID],
					$error->getMessage(),
				);
			}
		}

		return false;
	}

	/**
	 * @param array{name: string, position?: string, code?: string, avatar?: string} $params
	 *
	 * @return int
	 * @throws \Bitrix\Main\LoaderException
	 */
	public static function registerOrUpdateByCode(array $params = []): int
	{
		if (!Loader::includeModule('im') || !Loader::includeModule('imbot'))
		{
			return 0;
		}

		if (!self::isNameFilledInParams($params))
		{
			return 0;
		}

		$code = $params[self::REGISTER_PARAM_CODE] ?? '';
		if (empty($code))
		{
			return self::register($params);
		}

		$botId = self::getBotIdByCode($code);
		if (!$botId)
		{
			return self::register($params);
		}

		$updated = Bot::update(
			[self::FIELD_BOT_ID => $botId],
			['PROPERTIES' => self::prepareBotUpdateProperties($params)],
		);

		return $updated ? (int)$botId : 0;
	}

	public static function getBotIdByCode(string $code, bool $withCache = true): ?int
	{
		if ($withCache && array_key_exists($code, self::$botIdsByCodes))
		{
			return self::$botIdsByCodes[$code];
		}

		$row = BotTable::query()
		   ->where(self::FIELD_CLASS, self::class)
		   ->where(self::FIELD_CODE, $code)
		   ->setLimit(1)
		   ->setSelect([self::FIELD_BOT_ID])
		   ->fetch()
		;

		self::$botIdsByCodes[$code] = empty($row[self::FIELD_BOT_ID]) ? null : (int)$row[self::FIELD_BOT_ID];

		return self::$botIdsByCodes[$code];
	}

	private static function isNameFilledInParams(array $params): bool
	{
		return !empty((string)($params[self::REGISTER_PARAM_NAME] ?? null));
	}

	private static function prepareBotUpdateProperties(array $params): array
	{
		$botPropertiesToUpdate = [
			'NAME' => (string)($params[self::REGISTER_PARAM_NAME] ?? ''),
			'WORK_POSITION' => (string)($params[self::REGISTER_PARAM_POSITION] ?? ''),
		];

		$avatarId = (int)($params[self::REGISTER_PARAM_AVATAR] ?? 0);
		if ($avatarId > 0 && self::isCorrectAvatarFileId($avatarId))
		{
			$botPropertiesToUpdate['PERSONAL_PHOTO'] = \CFile::CloneFile($avatarId);
		}
		else
		{
			$botPropertiesToUpdate['DELETE_PERSONAL_PHOTO'] = 'Y';
		}

		return $botPropertiesToUpdate;
	}

	private static function handleMessageSent(\Bitrix\Bizproc\Starter\Event $event): \Bitrix\Bizproc\Result
	{
		$messageFields = $event->getParameters();
		$messageId = (int)($messageFields[BizprocBot::FIELD_ID] ?? 0);
		$botId = (int)($messageFields[BizprocBot::FIELD_BOT_ID] ?? 0);

		$result = new Result();
		if ($messageId <= 0 || $botId <= 0)
		{
			$result->addError(new Error('bot not configured correctly.'));

			return $result;
		}

		$document = Workflow::getComplexId((string)$messageId);
		$documentType = Workflow::getComplexType();
		$messageFields[self::FIELD_DOCUMENT_ID] = $document;

		return Starter::getByScenario(Scenario::onEvent)
			->addEvent( self::IM_BOT_NEW_MESSAGE_TRIGGER, [new DocumentDto($document, $documentType)], $messageFields)
			->setParameters($messageFields)
			->start()
		;
	}

	private static function sendTyping(int $chatId, int $botId): void
	{
		if (
			!enum_exists(Chat\InputAction\Type::class)
			|| !method_exists(Chat\InputAction\Action::class, 'notify')
		)
		{
			return;
		}

		$action = new Chat\InputAction\Action(Chat::getInstance($chatId), Chat\InputAction\Type::Writing);
		$action->setContextUser($botId)->notify();
		if (Loader::includeModule('pull'))
		{
			\Bitrix\Pull\Event::send();
		}
	}

	private static function isSomeWorkflowStarted(\Bitrix\Bizproc\Result $startResult): bool
	{
		return $startResult instanceof StartResult && !empty($startResult->getWorkflowIds());
	}

	private static function readMessage(int $chatId, int $botId, Message $message): void
	{
		$chat = Chat::getInstance($chatId);
		if ($chat->getRelationByUserId($botId))
		{
			$messages = (new MessageCollection())->add($message);
			$chat
				->withContextUser($botId)
				->readMessages($messages, true)
			;
		}
	}

	private static function sendError(int $botId, string $dialogId, string $message): bool
	{
		/** @var bool|int $messageId */
		$messageId = Bot::addMessage(
			[
				self::FIELD_BOT_ID => $botId,
			],
			[
				self::FIELD_DIALOG_ID => $dialogId,
				self::FIELD_MESSAGE => $message,
				self::MESSAGE_FIELD_SYSTEM => 'Y',
			],
		);

		return (bool)$messageId;
	}

	private static function isCorrectAvatarFileId(int $fileId): bool
	{
		if (empty($fileId))
		{
			return false;
		}

		$fileArray = \CFile::makeFileArray($fileId);
		$maxWidth = (int)Option::get('main', 'profile_image_width', 0);
		$maxHeight = (int)Option::get('main', 'profile_image_height', 0);
		$maxSize = (int)Option::get('main', 'profile_image_size', 0);

		$error = \CFile::CheckImageFile($fileArray, $maxSize, $maxWidth, $maxHeight);

		return empty($error);
	}
}