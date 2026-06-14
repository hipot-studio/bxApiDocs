<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Integration\ImBot\BizprocBot;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPImBotNewMessageTrigger extends \Bitrix\Bizproc\Activity\BaseTrigger
{
	private const PARAM_BOT_ID = 'BotId';
	private const PARAM_SENDER_IDS = 'SenderIds';
	private const PARAM_BOT_CODE = 'BotCode';
	private const RETURN_PARAM_MESSAGE = 'Message';
	private const RETURN_PARAM_SENDER = 'SenderId';
	private const RETURN_PARAM_ACTUAL_BOT_ID = 'ActualBotId';
	private const RETURN_PARAM_CHAT_ID = 'chatId';
	private const RETURN_PARAM_IS_GROUP_CHAT = 'isGroupChat';
	private const FIELD_DOCUMENT_ID = 'DOCUMENT_ID';

	public function execute(): int
	{
		if (!Loader::includeModule('im') || !Loader::includeModule('imbot'))
		{
			$this->trackError((string)Loc::getMessage('IMBOT_MESSAGE_ACTIVITY_MODULE_NOT_INSTALLED'));

			return CBPActivityExecutionStatus::Closed;
		}

		$context = $this->getRootActivity()->{\CBPDocument::PARAM_TRIGGER_EVENT_DATA} ?? [];

		$senderId = (int)($context[BizprocBot::FIELD_FROM_USER_ID] ?? 0);
		$botId = $this->getBotIdWithCodeIfNeeded();
		if (empty($botId))
		{
			$this->trackError((string)Loc::getMessage('IMBOT_MESSAGE_ACTIVITY_PROPERTY_BOT_ID_EMPTY'));

			return CBPActivityExecutionStatus::Closed;
		}

		$this->{self::RETURN_PARAM_MESSAGE} = (string)($context[BizprocBot::FIELD_MESSAGE] ?? '');
		$this->{self::RETURN_PARAM_SENDER} = 'user_' . $senderId;
		$this->{self::RETURN_PARAM_ACTUAL_BOT_ID} = $botId;
		$this->{self::RETURN_PARAM_CHAT_ID} = (int)($context['TO_CHAT_ID'] ?? $context['CHAT_ID'] ?? 0);
		$this->{self::RETURN_PARAM_IS_GROUP_CHAT} = ($context['MESSAGE_TYPE'] ?? null) === IM_MESSAGE_CHAT;

		return CBPActivityExecutionStatus::Closed;
	}

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			self::PARAM_BOT_ID => '',
			self::PARAM_SENDER_IDS => '',
			self::PARAM_BOT_CODE => '',
			self::RETURN_PARAM_MESSAGE => null,
			self::RETURN_PARAM_SENDER => null,
			self::RETURN_PARAM_ACTUAL_BOT_ID => null,
			self::RETURN_PARAM_CHAT_ID => null,
			self::RETURN_PARAM_IS_GROUP_CHAT => null,
		];

		$this->SetPropertiesTypes([
			self::RETURN_PARAM_MESSAGE => ['Type' => FieldType::STRING],
			self::RETURN_PARAM_SENDER => ['Type' => FieldType::USER],
			self::RETURN_PARAM_ACTUAL_BOT_ID => ['Type' => FieldType::INT],
			self::RETURN_PARAM_CHAT_ID => ['Type' => FieldType::INT],
			self::RETURN_PARAM_IS_GROUP_CHAT => ['Type' => FieldType::BOOL],
		]);
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_BOT_ID => [
				'Name' => Loc::getMessage('IMBOT_NEW_MESSAGE_TRIGGER_PROPERTY_BOT_ID'),
				'FieldName' => self::PARAM_BOT_ID,
				'Type' => FieldType::SELECT,
				'Options' => self::getBizprocBotOptions(),
			],
			self::PARAM_BOT_CODE => [
				'Name' => Loc::getMessage('IMBOT_NEW_MESSAGE_TRIGGER_PROPERTY_BOT_CODE'),
				'FieldName' => self::PARAM_BOT_CODE,
				'Type' => FieldType::STRING,
			],
			self::PARAM_SENDER_IDS => [
				'Name' => Loc::getMessage('IMBOT_NEW_MESSAGE_TRIGGER_PROPERTY_SENDER_IDS'),
				'FieldName' => self::PARAM_SENDER_IDS,
				'Type' => FieldType::USER,
				'Required' => true,
				'Multiple' => true,
			],
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

		return array_merge($errors, parent::ValidateProperties($arTestProperties, $user));
	}

	private static function makeEmptyError(string $param): array
	{
		return [
			'code' => 'NotExist',
			'parameter' => $param,
			'message' => match ($param)
			{
				self::PARAM_SENDER_IDS => Loc::getMessage('IMBOT_NEW_MESSAGE_TRIGGER_PROPERTY_SENDER_IDS_EMPTY'),
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
		$siteId = '',
	): PropertiesDialog
	{
		$dialog = new PropertiesDialog(
			__FILE__, [
			'documentType' => $documentType,
			'activityName' => $activityName,
			'workflowTemplate' => $arWorkflowTemplate,
			'workflowParameters' => $arWorkflowParameters,
			'workflowVariables' => $arWorkflowVariables,
			'currentValues' => $arCurrentValues,
			'formName' => $formName,
			'siteId' => $siteId,
		],
		);

		$dialog->setMap(static::getPropertiesMap($documentType));

		return $dialog;
	}

	public static function getPropertiesDialogValues(
		$documentType,
		$activityName,
		&$workflowTemplate,
		&$workflowParameters,
		&$workflowVariables,
		$currentValues,
		&$errors,
	): bool
	{
		$properties = [];
		$errors = [];

		$documentService = CBPRuntime::getRuntime()->getDocumentService();
		foreach (static::getPropertiesMap($documentType) as $id => $property)
		{
			$value = $documentService->getFieldInputValue(
				$documentType,
				$property,
				$property['FieldName'],
				$currentValues,
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

		$currentActivity = &CBPWorkflowTemplateLoader::findActivityByName($workflowTemplate, $activityName);
		$currentActivity['Properties'] = $properties;

		return true;
	}

	public function checkApplyRules(array $rules, \Bitrix\Bizproc\Activity\Trigger\TriggerParameters $parameters): \Bitrix\Bizproc\Result
	{
		$senderId = (int)$parameters->get(BizprocBot::FIELD_FROM_USER_ID);
		if ($senderId === 0)
		{
			return $this->makeIncorrectBotErrorResult();
		}

		$eventBot = (int)$parameters->get(BizprocBot::FIELD_BOT_ID);
		if ($eventBot === 0)
		{
			return $this->makeIncorrectBotErrorResult();
		}

		if ($eventBot !== $this->getBotIdWithCodeIfNeeded())
		{
			return $this->makeIncorrectBotErrorResult();
		}

		$allowedSenderIds = CBPHelper::ExtractUsers(
			$this->{self::PARAM_SENDER_IDS},
			$parameters->get(self::FIELD_DOCUMENT_ID)
		);
		if (!$this->checkUsersUseAbility($allowedSenderIds, $senderId))
		{
			return $this->makeAccessDeniedErrorResult();
		}

		return \Bitrix\Bizproc\Result::createOk();
	}

	private function checkUsersUseAbility(array $senderIds, int $senderId): bool
	{
		if (!in_array($senderId, $senderIds, true))
		{
			return false;
		}

		return true;
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

	private function makeAccessDeniedErrorResult(): \Bitrix\Bizproc\Result
	{
		$message = Loc::getMessage('IMBOT_NEW_MESSAGE_TRIGGER_PROPERTY_SENDER_IMBOT_MESSAGE_ACTIVITY_PROPERTY_SENDER_FORBIDDEN_MSG_VER1', [
			'#ID#' => $this->workflow->getTemplateId(),
			'#NAME#' => $this->getTemplateName() ?? '',
		]);

		return \Bitrix\Bizproc\Result::createError(
			new \Bitrix\Bizproc\Error($message,BizprocBot::ACCESS_DENIED_CODE),
		);
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

	private function makeIncorrectBotErrorResult(): \Bitrix\Bizproc\Result
	{
		return \Bitrix\Bizproc\Result::createError(new \Bitrix\Bizproc\Error('Incorrect bot id, try other'));
	}

	private function getTemplateName(): ?string
	{
		$result = CBPWorkflowTemplateLoader::getList(
			['ID' => 'DESC'],
			['ID' => $this->workflow->getTemplateId()],
			false,
			false,
			['NAME'],
		);
		if ($result === false)
		{
			return null;
		}

		$row = $result->fetch();
		if ($row === false)
		{
			return null;
		}

		return $row['NAME'] ?? null;
	}
}
