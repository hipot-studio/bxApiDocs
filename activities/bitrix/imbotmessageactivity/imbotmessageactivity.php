<?php

use Bitrix\Ai\Services\MarkdownToBBCodeTranslationService;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Im\Bot;
use Bitrix\Im\Bot\Keyboard;
use Bitrix\Bizproc\Integration\ImBot\BizprocBot;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Message\BlocksBuilder\BuilderService;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPImBotMessageActivity extends CBPActivity implements IBPConfigurableActivity
{
	private const PARAM_BOT_ID = 'botId';
	private const PARAM_RECIPIENT = 'recipient';
	private const PARAM_MESSAGE = 'message';
	private const PARAM_BOT_CODE = 'botCode';
	private const PARAM_AS_ERROR = 'asError';
	private const PARAM_CHAT_ID = 'chatId';
	private const PARAM_AI_WARNING = 'aiWarning';
	private const PARAM_EXPAND_URL_PREVIEW = 'expandUrlPreview';
	private const PARAM_BUTTONS = 'buttons';
	private const PARAM_MESSAGE_BLOCKS_JSON = 'messageBlocksJson';

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			self::PARAM_BOT_ID => null,
			self::PARAM_RECIPIENT => null,
			self::PARAM_MESSAGE => '',
			self::PARAM_BOT_CODE => null,
			self::PARAM_AS_ERROR => null,
			self::PARAM_CHAT_ID => null,
			self::PARAM_AI_WARNING => null,
			self::PARAM_EXPAND_URL_PREVIEW => null,
			self::PARAM_BUTTONS => null,
			self::PARAM_MESSAGE_BLOCKS_JSON => null,
		];
	}

	public static function validateProperties($arTestProperties = [], CBPWorkflowTemplateUser $user = null): array
	{
		$errors = [];
		foreach (self::getPropertiesMap([]) as $id => $property)
		{
			if (!empty($property['Required']) && empty($arTestProperties[$id]))
			{
				$errors[] = self::makeEmptyError($id);
			}
		}

		if (empty($arTestProperties[self::PARAM_BOT_ID]) && empty($arTestProperties[self::PARAM_BOT_CODE]))
		{
			$errors[] = [
				'code' => 'NotExist',
				'message' => Loc::getMessage('IMBOT_MESSAGE_ACTIVITY_PROPERTY_BOT_ID_OR_CODE_EMPTY'),
			];
		}

		if (
			empty($arTestProperties[self::PARAM_RECIPIENT])
			&& empty($arTestProperties[self::PARAM_CHAT_ID])
		)
		{
			$errors[] = [
				'code' => 'NotExist',
				'message' => Loc::getMessage('IMBOT_MESSAGE_ACTIVITY_PROPERTY_CHAT_ID_OR_RECIPIENT_EMPTY'),
			];
		}

		return array_merge($errors, parent::ValidateProperties($arTestProperties, $user));
	}

	private static function makeEmptyError(string $param): array
	{
		return [
			'code' => 'NotExist',
			'parameter' => $param,
			'message' => match ($param)
			{
				self::PARAM_MESSAGE => Loc::getMessage('IMBOT_MESSAGE_ACTIVITY_PROPERTY_MESSAGE_EMPTY'),
			},
		];
	}

	public static function getPropertiesDialog(
		$documentType,
		$activityName,
		$arWorkflowTemplate,
		$arWorkflowParameters,
		$arWorkflowVariables,
		$arCurrentValues = null,
		$formName = "",
		$popupWindow = null,
		$siteId = ''
	): PropertiesDialog
	{
		$dialog = new PropertiesDialog(__FILE__, [
			'documentType' => $documentType,
			'activityName' => $activityName,
			'workflowTemplate' => $arWorkflowTemplate,
			'workflowParameters' => $arWorkflowParameters,
			'workflowVariables' => $arWorkflowVariables,
			'currentValues' => $arCurrentValues,
			'formName' => $formName,
			'siteId' => $siteId,
		]);

		$dialog->setMap(static::getPropertiesMap($documentType));

		return $dialog;
	}

	public static function getPropertiesDialogValues(
		$documentType,
		$activityName,
		&$arWorkflowTemplate,
		&$arWorkflowParameters,
		&$arWorkflowVariables,
		$arCurrentValues,
		&$errors
	): bool
	{
		$errors = [];
		$properties = [];

		$documentService = CBPRuntime::getRuntime()->getDocumentService();
		foreach (static::getPropertiesMap($documentType) as $id => $property)
		{
			$value = $documentService->getFieldInputValue(
				$documentType,
				$property,
				$property['FieldName'],
				$arCurrentValues,
				$errors
			);

			if (!empty($errors))
			{
				return false;
			}

			$properties[$id] = $value;
		}

		$errors = self::validateProperties(
			$properties,
			new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser)
		);

		if ($errors)
		{
			return false;
		}

		$currentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);

		// `buttons` is configured through the system template, not this dialog;
		// preserve it so a manual save in the visual designer does not drop the keyboard.
		foreach (self::getAiPassthroughSettingNames($documentType) as $propertyId)
		{
			if (isset($currentActivity['Properties'][$propertyId]))
			{
				$properties[$propertyId] = $currentActivity['Properties'][$propertyId];
			}
		}

		$currentActivity['Properties'] = $properties;

		return true;
	}

	private static function isAiNodeAvailable(): bool
	{
		$serviceLocator = \Bitrix\Main\DI\ServiceLocator::getInstance();
		$interface = \Bitrix\Bizproc\Public\Service\AiAgent\NodeAvailabilityServiceInterface::class;
		if (!$serviceLocator->has($interface))
		{
			return true;
		}

		return $serviceLocator->get($interface)->isAvailable();
	}

	public static function getAiPassthroughSettingNames(array $documentType): array
	{
		// `buttons` is persisted from the system template but intentionally absent from getPropertiesMap.
		return [
			self::PARAM_BUTTONS,
			self::PARAM_MESSAGE_BLOCKS_JSON,
		];
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		$map = [
			self::PARAM_BOT_ID => [
				'Name' => Loc::getMessage('IMBOT_MESSAGE_ACTIVITY_PROPERTY_BOT_ID'),
				'FieldName' => self::PARAM_BOT_ID,
				'Type' => FieldType::SELECT,
				'Options' => self::getBizprocBotOptions(),
			],
			self::PARAM_BOT_CODE => [
				'Name' => Loc::getMessage('IMBOT_MESSAGE_ACTIVITY_PROPERTY_BOT_CODE'),
				'FieldName' => self::PARAM_BOT_CODE,
				'Type' => FieldType::STRING,
			],
			self::PARAM_RECIPIENT => [
				'Name' => Loc::getMessage('IMBOT_MESSAGE_ACTIVITY_PROPERTY_RECIPIENT'),
				'FieldName' => self::PARAM_RECIPIENT,
				'Type' => FieldType::USER,
				'Multiple' => true,
			],
			self::PARAM_CHAT_ID => [
				'Name' => Loc::getMessage('IMBOT_MESSAGE_ACTIVITY_PROPERTY_CHAT_ID'),
				'FieldName' => self::PARAM_CHAT_ID,
				'Type' => FieldType::INT,
			],
			self::PARAM_MESSAGE => [
				'Name' => Loc::getMessage('IMBOT_MESSAGE_ACTIVITY_PROPERTY_MESSAGE'),
				'FieldName' => self::PARAM_MESSAGE,
				'Type' => FieldType::STRING,
				'Required' => true,
			],
			self::PARAM_AS_ERROR => [
				'Name' => Loc::getMessage('IMBOT_MESSAGE_ACTIVITY_PROPERTY_AS_ERROR'),
				'FieldName' => self::PARAM_AS_ERROR,
				'Type' => FieldType::BOOL,
			],
			self::PARAM_AI_WARNING => [
				'Name' => Loc::getMessage('IMBOT_MESSAGE_ACTIVITY_PROPERTY_AI_WARNING'),
				'FieldName' => self::PARAM_AI_WARNING,
				'Type' => FieldType::BOOL,
			],
			self::PARAM_EXPAND_URL_PREVIEW => [
				'Name' => Loc::getMessage('IMBOT_MESSAGE_ACTIVITY_PROPERTY_EXPAND_URL_PREVIEW'),
				'FieldName' => self::PARAM_EXPAND_URL_PREVIEW,
				'Type' => FieldType::BOOL,
			],
		];

		if (self::isAiNodeAvailable())
		{
			$map[self::PARAM_AI_WARNING] = [
				'Name' => Loc::getMessage('IMBOT_MESSAGE_ACTIVITY_PROPERTY_AI_WARNING'),
				'FieldName' => self::PARAM_AI_WARNING,
				'Type' => FieldType::BOOL,
			];
		}

		return $map;
	}

	/**
	 * @return array<int, string>
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\LoaderException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	private static function getBizprocBotOptions(): array
	{
		if (!Loader::includeModule('imbot') || !Loader::includeModule('im'))
		{
			return [];
		}

		return BizprocBot::getBotNamesByIds();
	}

	public function execute(): int
	{
		if (!Loader::includeModule('im') || !Loader::includeModule('imbot'))
		{
			$this->trackError((string)Loc::getMessage('IMBOT_MESSAGE_ACTIVITY_MODULE_NOT_INSTALLED'));

			return CBPActivityExecutionStatus::Closed;
		}

		$message = (string)$this->{self::PARAM_MESSAGE};
		if (empty($message))
		{
			$this->trackError((string)Loc::getMessage('IMBOT_MESSAGE_ACTIVITY_PROPERTY_MESSAGE_EMPTY'));

			return CBPActivityExecutionStatus::Closed;
		}

		$botId = $this->getBotIdWithCodeIfNeeded();
		if (empty($botId))
		{
			$this->trackError((string)Loc::getMessage('IMBOT_MESSAGE_ACTIVITY_PROPERTY_BOT_ID_EMPTY'));

			return CBPActivityExecutionStatus::Closed;
		}

		if (!BizprocBot::isExistsById($botId))
		{
			$this->trackError((string)Loc::getMessage('IMBOT_MESSAGE_ACTIVITY_PROPERTY_BOT_ID_INCORRECT'));

			return CBPActivityExecutionStatus::Closed;
		}

		$asError = CBPHelper::getBool($this->{self::PARAM_AS_ERROR});
		$message = $this->prepareMessageToSend($message);
		$chatId = (int)$this->{self::PARAM_CHAT_ID};
		$aiWarning = CBPHelper::getBool($this->{self::PARAM_AI_WARNING});
		$expandUrlPreview = CBPHelper::getBool($this->{self::PARAM_EXPAND_URL_PREVIEW});
		$keyboard = $this->buildKeyboard($botId);
		if ($chatId > 0)
		{
			$this->sendAndTrackError($botId, "chat{$chatId}", $message, $asError, $aiWarning, $expandUrlPreview, $keyboard);

			return CBPActivityExecutionStatus::Closed;
		}

		$recipients = CBPHelper::ExtractUsers($this->{self::PARAM_RECIPIENT}, $this->getDocumentId());
		if (empty($recipients))
		{
			$this->trackError((string)Loc::getMessage('IMBOT_MESSAGE_ACTIVITY_PROPERTY_RECIPIENT_EMPTY'));

			return CBPActivityExecutionStatus::Closed;
		}
		foreach ($recipients as $recipient)
		{
			$this->sendAndTrackError($botId, (string)$recipient, $message, $asError, $aiWarning, $expandUrlPreview, $keyboard);
		}

		return CBPActivityExecutionStatus::Closed;
	}

	private function sendAndTrackError(
		int $botId,
		string $dialogId,
		string $message,
		bool $asError,
		bool $aiWarning,
		bool $expandUrlPreview,
		?Keyboard $keyboard = null,
	): void
	{
		$sent = $this->sendByDialogId($botId, $dialogId, $message, $asError, $aiWarning, $expandUrlPreview, $keyboard);
		if (!$sent)
		{
			$this->trackErrorFromGlobalVariables();
		}
	}

	private function sendByDialogId(
		int $botId,
		string $dialogId,
		string $message,
		bool $asError,
		bool $aiWarning,
		bool $expandUrlPreview,
		?Keyboard $keyboard = null,
	): bool
	{
		$botIdentifier = ['BOT_ID' => $botId];
		$messageFields = [
			'DIALOG_ID' => $dialogId,
			'MESSAGE' => $message,
		];

		if ($asError)
		{
			$messageFields['PARAMS'] = ['COMPONENT_ID' => 'ErrorMessage'];
		}
		elseif ($aiWarning)
		{
			$messageFields['PARAMS'] = ['COMPONENT_ID' => 'AiBizprocMessage'];
		}

		$blocksBuilder = $this->buildMessageBlocks($dialogId, $botId);
		if ($blocksBuilder !== null)
		{
			$messageFields['PARAMS'][Params::BLOCK] = $blocksBuilder;
		}

		if (!$expandUrlPreview)
		{
			$messageFields['URL_PREVIEW'] = 'N';
		}

		if ($keyboard !== null)
		{
			$messageFields['KEYBOARD'] = $keyboard;
		}

		return (bool)Bot::addMessage($botIdentifier, $messageFields);
	}

	private function buildMessageBlocks(string $dialogId, int $botId): ?object
	{
		// older `im` versions know the blocks builder but not the message param, so blocks cannot be attached
		if (!defined(Params::class . '::BLOCK'))
		{
			return null;
		}

		$json = (string)$this->{self::PARAM_MESSAGE_BLOCKS_JSON};
		if ($json === '')
		{
			return null;
		}

		try
		{
			$data = Json::decode($json);
			$chatId = (int)\Bitrix\Im\Dialog::getChatId($dialogId, $botId);
			if (!is_array($data) || $chatId <= 0)
			{
				return null;
			}

			$chat = ChatFactory::getInstance()->getChatById($chatId);
			$result = ServiceLocator::getInstance()->get(BuilderService::class)->create($data, $chat);

			return $result->isSuccess() ? $result->getBlocksBuilder() : null;
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * Builds an inline keyboard from the optional multiple `buttons` property.
	 * Each entry is {COMMAND, COMMAND_PARAMS, TEXT, CONTEXT}. A COMMAND button
	 * requires a resolved bot id (> 0); when the property is empty or no valid
	 * button is produced, no keyboard is attached and current behaviour is kept.
	 */
	private function buildKeyboard(int $botId): ?Keyboard
	{
		if ($botId <= 0)
		{
			return null;
		}

		$buttons = $this->{self::PARAM_BUTTONS};
		if (!is_array($buttons) || empty($buttons))
		{
			return null;
		}

		$keyboard = new Keyboard($botId);
		foreach ($buttons as $button)
		{
			if (!is_array($button))
			{
				continue;
			}

			$command = (string)($button['COMMAND'] ?? '');
			$text = (string)($button['TEXT'] ?? '');
			if ($command === '' || $text === '')
			{
				continue;
			}

			$keyboard->addButton([
				'COMMAND' => $command,
				'COMMAND_PARAMS' => (string)($button['COMMAND_PARAMS'] ?? ''),
				'TEXT' => $text,
				'CONTEXT' => (string)($button['CONTEXT'] ?? 'ALL'),
			]);
		}

		return empty($keyboard->getArray()) ? null : $keyboard;
	}

	private function trackErrorFromGlobalVariables(): void
	{
		global $APPLICATION;

		$exception = $APPLICATION->GetException();
		if ($exception)
		{
			$this->trackError($exception->GetString() ?? '');
		}
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

	private function prepareMessageToSend(string $message): string
	{
		if (
			!Loader::includeModule('ai')
			|| !class_exists(MarkdownToBBCodeTranslationService::class)
		)
		{
			return $message;
		}

		return ServiceLocator::getInstance()
			->get(MarkdownToBBCodeTranslationService::class)
			->convert($message)
		;
	}
}
