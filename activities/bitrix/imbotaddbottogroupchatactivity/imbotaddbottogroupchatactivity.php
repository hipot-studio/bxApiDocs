<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Integration\ImBot\BizprocBot;

use Bitrix\Im\Dialog;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Relation\AddUsersConfig;

class CBPImBotAddBotToGroupChatActivity extends CBPActivity implements IBPConfigurableActivity
{
	public const PROPERTY_TITLE = 'Title';
	private const PARAM_CHAT_ID = 'ChatId';
	private const PARAM_BOT_ID = 'BotId';
	private const PARAM_BOT_CODE = 'BotCode';
	private const RETURN_PARAM_ERROR_MESSAGE = 'ErrorMessage';

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			self::PROPERTY_TITLE => '',
			self::PARAM_CHAT_ID => null,
			self::PARAM_BOT_ID => null,
			self::PARAM_BOT_CODE => null,
			self::RETURN_PARAM_ERROR_MESSAGE => null,
		];

		$this->setPropertiesTypes([
			self::PARAM_CHAT_ID => [
				'Type' => FieldType::STRING,
			],
			self::PARAM_BOT_ID => [
				'Type' => FieldType::INT,
			],
			self::PARAM_BOT_CODE => [
				'Type' => FieldType::STRING,
			],
			self::RETURN_PARAM_ERROR_MESSAGE => [
				'Type' => FieldType::STRING,
			],
		]);
	}

	public function reInitialize(): void
	{
		parent::reInitialize();

		$this->{self::RETURN_PARAM_ERROR_MESSAGE} = null;
	}

	public function execute(): int
	{
		if (!Loader::includeModule('im') || !Loader::includeModule('imbot'))
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$chatId = $this->getChatId();

		if ($this->workflow->isDebug())
		{
			$this->writeDebugInfo($this->getDebugInfo([self::PARAM_CHAT_ID => $chatId]));
		}

		if ($chatId === null || $chatId === 0)
		{
			return $this->closeWithError(
				Loc::getMessage('IMBOT_ADD_BOT_TO_GROUP_CHAT_ACTIVITY_ERROR_NO_CHAT') ?? '',
			);
		}

		$botId = $this->getBotIdWithCodeIfNeeded();
		if (empty($botId))
		{
			return $this->closeWithError(
				Loc::getMessage('IMBOT_ADD_BOT_TO_GROUP_CHAT_ACTIVITY_ERROR_NO_BOT') ?? '',
			);
		}

		if (!BizprocBot::isExistsById($botId))
		{
			return $this->closeWithError(
				Loc::getMessage('IMBOT_ADD_BOT_TO_GROUP_CHAT_ACTIVITY_ERROR_BOT_NOT_FOUND') ?? '',
			);
		}

		$chat = Chat::getInstance($chatId)
			->withContextUser(0)
		;

		if (!$chat->isExist())
		{
			return $this->closeWithError(
				Loc::getMessage('IMBOT_ADD_BOT_TO_GROUP_CHAT_ACTIVITY_ERROR_NO_CHAT') ?? '',
			);
		}

		if (!$this->canAddMembers($chat))
		{
			return $this->closeWithError(
				Loc::getMessage('IMBOT_ADD_BOT_TO_GROUP_CHAT_ACTIVITY_ERROR_CANT_ADD_TO_CHAT') ?? '',
			);
		}

		try
		{
			$config = new AddUsersConfig(
				managerIds: [],
				hideHistory: false,
			);

			$chat->addUsers([$botId], $config);
		}
		catch (\Exception)
		{
			return $this->closeWithError(
				Loc::getMessage('IMBOT_ADD_BOT_TO_GROUP_CHAT_ACTIVITY_ERROR_DURING_ADD') ?? '',
			);
		}

		return CBPActivityExecutionStatus::Closed;
	}

	private function closeWithError(string $errorMessage): int
	{
		if (!empty($errorMessage))
		{
			$this->trackError($errorMessage);
			$this->{self::RETURN_PARAM_ERROR_MESSAGE} = $errorMessage;
		}

		return CBPActivityExecutionStatus::Closed;
	}

	private function getChatId(): ?int
	{
		$chatId = $this->{self::PARAM_CHAT_ID};
		if (!is_scalar($chatId))
		{
			return null;
		}

		if ((int)$chatId > 0)
		{
			return (int)$chatId;
		}

		$chatId = Dialog::getChatId((string)$chatId);

		return $chatId ? (int)$chatId : null;
	}

	private function getBotIdWithCodeIfNeeded(): int
	{
		$configuredBot = (int)$this->{self::PARAM_BOT_ID};
		if ($configuredBot !== 0)
		{
			return $configuredBot;
		}

		$configuredBotCode = (string)$this->{self::PARAM_BOT_CODE};
		if ($configuredBotCode === '')
		{
			return 0;
		}

		return (int)BizprocBot::getBotIdByCode($configuredBotCode);
	}

	private function canAddMembers(Chat $chat): bool
	{
		if (
			$chat instanceof Chat\GroupChat
		)
		{
			return true;
		}

		return false;
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_CHAT_ID => [
				'Name' => Loc::getMessage('IMBOT_ADD_BOT_TO_GROUP_CHAT_ACTIVITY_FIELD_CHAT_ID'),
				'FieldName' => self::PARAM_CHAT_ID,
				'Type' => FieldType::ENTITYSELECTOR,
				'Settings' => [
					'entity' => [
						'id' => 'im-recent-v2',
						'dynamicLoad' => true,
						'dynamicSearch' => true,
						"searchable" => true,
						"fillRecentItems" => true,
						'options' => [
							'excludeChatTypes' => [
								Chat::IM_TYPE_COPILOT,
							],
							'fillDialogByRecent' => true,
							'includeOnly' => ['chats'],
						],
					],
					'dialogOptions' => [
						'width' => 445,
						'height' => 300,
						'recentTabOptions' => [
							'itemOrder' => [
								'sort' => 'desc',
							],
						],
						'searchTabOptions' => [
							'itemOrder' => [
								'sort' => 'desc',
							],
						],
					],
				],
				'Required' => true,
			],
			self::PARAM_BOT_ID => [
				'Name' => Loc::getMessage('IMBOT_ADD_BOT_TO_GROUP_CHAT_ACTIVITY_FIELD_BOT_ID'),
				'FieldName' => self::PARAM_BOT_ID,
				'Type' => FieldType::SELECT,
				'Options' => self::getBizprocBotOptions(),
			],
			self::PARAM_BOT_CODE => [
				'Name' => Loc::getMessage('IMBOT_ADD_BOT_TO_GROUP_CHAT_ACTIVITY_FIELD_BOT_CODE'),
				'FieldName' => self::PARAM_BOT_CODE,
				'Type' => FieldType::STRING,
			],
		];
	}

	private static function getBizprocBotOptions(): array
	{
		if (!Loader::includeModule('imbot') || !Loader::includeModule('im'))
		{
			return [];
		}

		return BizprocBot::getBotNamesByIds();
	}

	public static function GetPropertiesDialogValues(
		$documentType,
		$activityName,
		&$workflowTemplate,
		&$workflowParameters,
		&$workflowVariables,
		$currentValues,
		&$errors,
	)
	{
		if (!Loader::includeModule('im'))
		{
			return false;
		}

		$documentService = CBPRuntime::getRuntime()->getDocumentService();

		$errors = [];
		$properties = [];
		foreach (static::getPropertiesMap($documentType) as $id => $property)
		{
			$value = $documentService->getFieldInputValue(
				$documentType,
				$property,
				$property['FieldName'],
				$currentValues,
				$errors,
			);

			if ($errors)
			{
				return false;
			}

			$properties[$id] = $value;
		}

		$chatIdText = $currentValues['ChatId_text'] ?? [];
		if (
			!empty($chatIdText)
			&& !static::isExpression($chatIdText)
		)
		{
			$properties['ChatId'] = $chatIdText;
		}

		$workflowTemplateUser = new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser);
		$errors = self::validateProperties($properties, $workflowTemplateUser);

		if ($errors)
		{
			return false;
		}

		$currentActivity = &self::findActivityInTemplate($workflowTemplate, $activityName);
		$currentActivity['Properties'] = $properties;

		return true;
	}

	public static function validateProperties($arTestProperties = [], ?CBPWorkflowTemplateUser $user = null): array
	{
		$errors = [];

		if (empty($arTestProperties[self::PARAM_CHAT_ID]))
		{
			$errors[] = [
				'message' => Loc::getMessage('IMBOT_ADD_BOT_TO_GROUP_CHAT_ACTIVITY_ERROR_EMPTY_CHAT_ID'),
			];
		}

		if (empty($arTestProperties[self::PARAM_BOT_ID]) && empty($arTestProperties[self::PARAM_BOT_CODE]))
		{
			$errors[] = [
				'message' => Loc::getMessage('IMBOT_ADD_BOT_TO_GROUP_CHAT_ACTIVITY_ERROR_BOT_ID_OR_CODE_EMPTY'),
			];
		}

		return array_merge($errors, parent::validateProperties($arTestProperties, $user));
	}
}
