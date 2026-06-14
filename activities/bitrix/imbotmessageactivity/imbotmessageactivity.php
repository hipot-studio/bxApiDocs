<?php

use Bitrix\Ai\Services\MarkdownToBBCodeTranslationService;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Im\Bot;
use Bitrix\Bizproc\Integration\ImBot\BizprocBot;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

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

		if (empty($arTestProperties[self::PARAM_RECIPIENT]) && empty($arTestProperties[self::PARAM_CHAT_ID]))
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
		$currentActivity['Properties'] = $properties;

		return true;
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
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
		];
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
		if ($chatId > 0)
		{
			$this->sendAndTrackError($botId, "chat{$chatId}", $message, $asError, $aiWarning);

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
			$this->sendAndTrackError($botId, (string)$recipient, $message, $asError, $aiWarning);
		}

		return CBPActivityExecutionStatus::Closed;
	}

	private function sendAndTrackError(
		int $botId,
		string $dialogId,
		string $message,
		bool $asError,
		bool $aiWarning,
	): void
	{
		$sent = $this->sendByDialogId($botId, $dialogId, $message, $asError, $aiWarning);
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

		return (bool)Bot::addMessage($botIdentifier, $messageFields);
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