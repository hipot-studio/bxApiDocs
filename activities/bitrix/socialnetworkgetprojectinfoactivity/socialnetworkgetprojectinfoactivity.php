<?php

declare(strict_types=1);

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Error;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;

use Bitrix\Socialnetwork\Internals\Group\GroupEntity;
use Bitrix\Socialnetwork\WorkgroupTable;
use Bitrix\Socialnetwork\FeaturePermTable;
use Bitrix\Socialnetwork\Integration\Im\Chat\Workgroup;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPSocialnetworkGetProjectInfoActivity extends BaseActivity implements IBPConfigurableActivity
{
	private const PARAM_PROJECT_ID = 'ProjectId';

	private const RETURN_PARAM_PROJECT_NAME = 'ProjectName';
	private const RETURN_PARAM_PROJECT_CHAT_ID = 'ProjectChatId';
	private const RETURN_PARAM_CAN_MEMBERS_VIEW_ALL_TASKS = 'CanMembersViewAllTasks';

	private const PROPERTY_MAP_FIELD_NAME_FOR_ERROR_MESSAGE = 'nameForErrorMessage';
	private const PROPERTY_MAP_FIELD_NAME = 'Name';

	protected static $requiredModules = [
		'socialnetwork',
		'im',
	];

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			self::PARAM_PROJECT_ID => null,
			self::RETURN_PARAM_PROJECT_NAME => null,
			self::RETURN_PARAM_PROJECT_CHAT_ID => null,
			self::RETURN_PARAM_CAN_MEMBERS_VIEW_ALL_TASKS => null,
		];

		$this->setPropertiesTypes([
			self::RETURN_PARAM_PROJECT_NAME => [
				'Type' => FieldType::STRING,
			],
			self::RETURN_PARAM_PROJECT_CHAT_ID => [
				'Type' => FieldType::INT,
			],
			self::RETURN_PARAM_CAN_MEMBERS_VIEW_ALL_TASKS => [
				'Type' => FieldType::BOOL,
			],
		]);
	}

	protected function reInitialize(): void
	{
		parent::reInitialize();

		$this->{self::RETURN_PARAM_PROJECT_NAME} = null;
		$this->{self::RETURN_PARAM_PROJECT_CHAT_ID} = null;
		$this->{self::RETURN_PARAM_CAN_MEMBERS_VIEW_ALL_TASKS} = null;
	}

	protected function internalExecute(): ErrorCollection
	{
		$errors = new ErrorCollection();
		$projectId = (int)$this->{self::PARAM_PROJECT_ID};

		try
		{
			$project = WorkgroupTable::query()
				->setSelect(['ID', 'NAME'])
				->where('ID', $projectId)
				->fetchObject()
			;

			if (!$project)
			{
				$errors->setError(new Error(
					Loc::getMessage('SN_GET_PROJECT_INFO_ERROR_NOT_FOUND') ?? '',
				));

				return $errors;
			}

			$this->fillFields($project, $projectId);
		}
		catch (\Throwable $exception)
		{
			$errors->setError(new Error(
				Loc::getMessage('SN_GET_PROJECT_INFO_ERROR_DURING_FETCHING_DATA') ?? '',
			));
		}

		return $errors;
	}

	private function fillFields(GroupEntity $project, int $projectId): void
	{
		$this->{self::RETURN_PARAM_PROJECT_NAME} = $project->getName() ?? '';

		$chatData = Workgroup::getChatData(['group_id' => $projectId]);
		$this->{self::RETURN_PARAM_PROJECT_CHAT_ID} = (int)($chatData[$projectId] ?? 0);

		$viewAllTasksPerm = \CSocNetFeaturesPerms::GetOperationPerm(
			SONET_ENTITY_GROUP,
			$projectId,
			'tasks',
			'view_all',
		);
		$this->{self::RETURN_PARAM_CAN_MEMBERS_VIEW_ALL_TASKS} =
			$viewAllTasksPerm >= FeaturePermTable::PERM_USER ? 'Y' : 'N'
		;
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_PROJECT_ID => [
				'Name' => Loc::getMessage('SN_GET_PROJECT_INFO_FIELD_PROJECT_ID'),
				'FieldName' => self::PARAM_PROJECT_ID,
				'Type' => FieldType::ENTITYSELECTOR,
				'Required' => true,
				'Settings' => [
					'entity' => ['id' => 'project'],
				],
				self::PROPERTY_MAP_FIELD_NAME_FOR_ERROR_MESSAGE => Loc::getMessage('SN_GET_PROJECT_INFO_FIELD_PROJECT_ID_NAME'),
			],
		];
	}

	protected function checkProperties(): ErrorCollection
	{
		$errorCollection = new ErrorCollection();

		$projectId = (int)$this->{self::PARAM_PROJECT_ID};
		if ($projectId <= 0)
		{
			$errorCollection->setError(new Error(
				Loc::getMessage('SN_GET_PROJECT_INFO_FIELD_ERROR_INVALID_VALUE', [
					'#PROPERTY_NAME#' => self::getPropertyMapName(self::PARAM_PROJECT_ID),
				]) ?? '',
			));
		}

		return $errorCollection;
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return array_map(
			static function (array $property): array
			{
				$property[self::PROPERTY_MAP_FIELD_NAME] = $property[self::PROPERTY_MAP_FIELD_NAME_FOR_ERROR_MESSAGE];

				return $property;
			},
			self::getPropertiesMap([]),
		);
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}

	private static function getPropertyMapName(string $propertyId): ?string
	{
		return self::getPropertiesDialogMap()[$propertyId]['Name'] ?? null;
	}

}
