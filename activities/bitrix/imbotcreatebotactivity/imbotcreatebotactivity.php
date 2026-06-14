<?php

use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Integration\ImBot\BizprocBot;
use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Uf\FileUserType;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Disk\File;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPImBotCreateBotActivity extends CBPActivity implements IBPConfigurableActivity
{
	private const PARAM_BOT_NAME = 'botName';
	private const PARAM_WORK_POSITION = 'workPosition';
	private const PARAM_AVATAR = 'avatar';
	private const PARAM_BOT_CODE = 'botCode';
	private const PARAM_AVATAR_DISK_FILE = 'avatarDiskFile';
	private const RETURN_PARAM_BOT_ID = 'botId';

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			self::PARAM_BOT_NAME => '',
			self::PARAM_WORK_POSITION => '',
			self::PARAM_AVATAR => null,
			self::PARAM_BOT_CODE => '',
			self::PARAM_AVATAR_DISK_FILE => null,
			//return
			self::RETURN_PARAM_BOT_ID => null,
		];

		$this->setPropertiesTypes([
			self::RETURN_PARAM_BOT_ID => [
				'Type' => FieldType::INT,
			],
		]);
	}

	public static function validateProperties($arTestProperties = [], CBPWorkflowTemplateUser $user = null): array
	{
		$arErrors = [];

		if (empty($arTestProperties[self::PARAM_BOT_NAME]))
		{
			$arErrors[] = [
				'code' => 'NotExist',
				'parameter' => self::PARAM_BOT_NAME,
				'message' => Loc::getMessage('IMBOT_CREATE_BOT_ACTIVITY_PROPERTY_NAME_EMPTY'),
			];
		}

		return array_merge($arErrors, parent::ValidateProperties($arTestProperties, $user));
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

		$properties[self::PARAM_AVATAR_DISK_FILE] = null;
		$diskObjectId = (string)($arCurrentValues[self::PARAM_AVATAR_DISK_FILE] ?? '');
		if (self::isCurrentUserCanReadDiskFile($diskObjectId))
		{
			$properties[self::PARAM_AVATAR_DISK_FILE] = $diskObjectId;
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
			self::PARAM_BOT_NAME => [
				'Name' => Loc::getMessage('IMBOT_CREATE_BOT_ACTIVITY_PROPERTY_NAME'),
				'FieldName' => self::PARAM_BOT_NAME,
				'Type' => FieldType::STRING,
				'Required' => true,
			],
			self::PARAM_WORK_POSITION => [
				'Name' => Loc::getMessage('IMBOT_CREATE_BOT_ACTIVITY_PROPERTY_POSITION'),
				'FieldName' => self::PARAM_WORK_POSITION,
				'Type' => FieldType::STRING,
			],
			self::PARAM_BOT_CODE => [
				'Name' => Loc::getMessage('IMBOT_CREATE_BOT_ACTIVITY_PROPERTY_BOT_CODE'),
				'Description' => Loc::getMessage('IMBOT_CREATE_BOT_ACTIVITY_PROPERTY_BOT_CODE_DESCRIPTION'),
				'FieldName' => self::PARAM_BOT_CODE,
				'Type' => FieldType::STRING,
			],
			self::PARAM_AVATAR => [
				'Name' => Loc::getMessage('IMBOT_CREATE_BOT_ACTIVITY_PROPERTY_AVATAR'),
				'FieldName' => self::PARAM_AVATAR,
				'Type' => FieldType::FILE,
			],
			self::PARAM_AVATAR_DISK_FILE => [
				'Name' => Loc::getMessage('IMBOT_CREATE_BOT_ACTIVITY_PROPERTY_AVATAR_UPLOAD'),
				'FieldName' => self::PARAM_AVATAR_DISK_FILE,
				'Type' => FieldType::CUSTOM,
				'AllowSelection' => false,
				'CustomType' => 'diskFile',
			],
		];
	}

	public function execute(): int
	{
		if (empty($this->{self::PARAM_BOT_NAME}) || !CBPHelper::hasStringRepresentation($this->{self::PARAM_BOT_NAME}))
		{
			$this->trackError((string)Loc::getMessage('IMBOT_CREATE_BOT_ACTIVITY_PROPERTY_NAME_EMPTY'));

			return CBPActivityExecutionStatus::Closed;
		}

		if (!Loader::includeModule('im') || !Loader::includeModule('imbot'))
		{
			$this->trackError((string)Loc::getMessage('IMBOT_CREATE_BOT_ACTIVITY_MODULE_NOT_INSTALLED'));

			return CBPActivityExecutionStatus::Closed;
		}

		$botId = BizprocBot::registerOrUpdateByCode([
			BizprocBot::REGISTER_PARAM_NAME => $this->{self::PARAM_BOT_NAME},
			BizprocBot::REGISTER_PARAM_POSITION => $this->{self::PARAM_WORK_POSITION},
			BizprocBot::REGISTER_PARAM_AVATAR => $this->getAvatarFileId(),
			BizprocBot::REGISTER_PARAM_CODE => (string)$this->{self::PARAM_BOT_CODE},
		]);

		if (empty($botId))
		{
			$this->trackError((string)Loc::getMessage('IMBOT_CREATE_BOT_ACTIVITY_CREATE_ERROR'));

			return CBPActivityExecutionStatus::Closed;
		}

		$this->{self::RETURN_PARAM_BOT_ID} = $botId;

		return CBPActivityExecutionStatus::Closed;
	}

	private function getAvatarFileId(): ?int
	{
		return $this->getAvatarFileIdFromSelection() ?? $this->getAvatarFileFromDisk();
	}

	private function getAvatarFileIdFromSelection(): ?int
	{
		$avatarId = $this->{self::PARAM_AVATAR};

		return is_numeric($avatarId) ? (int)$avatarId : null;
	}

	private function getAvatarFileFromDisk(): ?int
	{
		$diskObjectId = $this->{self::PARAM_AVATAR_DISK_FILE};
		if (empty($diskObjectId))
		{
			return null;
		}

		if (!Loader::includeModule('disk'))
		{
			return null;
		}

		[$type, $realValue] = FileUserType::detectType($diskObjectId);
		if ($type === FileUserType::TYPE_NEW_OBJECT)
		{
			$fileModel = File::loadById($realValue, ['STORAGE']);

			return $fileModel?->getFileId();
		}

		$attachedModel = AttachedObject::loadById($realValue, ['OBJECT', 'VERSION']);

		return $attachedModel?->getFileId();
	}

	private static function isCurrentUserCanReadDiskFile(string $diskObjectId): bool
	{
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);
		$userId = $user->getId();
		if ($userId <= 0)
		{
			return false;
		}

		if (!Loader::includeModule('disk'))
		{
			return false;
		}

		[$type, $realValue] = FileUserType::detectType($diskObjectId);
		if ($type === FileUserType::TYPE_NEW_OBJECT)
		{
			$fileModel = File::loadById($realValue, ['STORAGE']);

			return $fileModel && $fileModel->canRead($fileModel->getStorage()->getCurrentUserSecurityContext());
		}

		$attachedModel = AttachedObject::loadById($realValue, ['OBJECT', 'VERSION']);

		return $attachedModel && $attachedModel->canRead($userId);
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return static::getPropertiesMap([]);
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}
}