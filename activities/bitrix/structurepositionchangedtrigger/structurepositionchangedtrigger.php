<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity\BaseTrigger;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Result;
use Bitrix\HumanResources\Integration\Bizproc\StructurePositionChangedTrigger;
use Bitrix\HumanResources\Integration\Bizproc\StructurePositionChangeType;
use Bitrix\HumanResources\Type\NodeMemberRole;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!Loader::includeModule('humanresources'))
{
	return;
}

class CBPStructurePositionChangedTrigger extends BaseTrigger
{
	private const PARAM_USERS = 'USERS';
	private const PARAM_CHANGE_TYPES = 'CHANGE_TYPES';

	private const CHANGE_GROUPS = [
		'added' => [StructurePositionChangeType::Added],
		'removed_or_moved' => [StructurePositionChangeType::Removed, StructurePositionChangeType::Moved],
		'role_changed' => [StructurePositionChangeType::RoleAssigned, StructurePositionChangeType::RoleRevoked],
	];

	private const RETURN_PARAM_USER = 'USER';
	private const RETURN_PARAM_CHANGE_TYPE = 'CHANGE_TYPE';
	private const RETURN_PARAM_PREVIOUS_DEPARTMENT_IDS = 'PREVIOUS_DEPARTMENT_IDS';
	private const RETURN_PARAM_NEW_DEPARTMENT_IDS = 'NEW_DEPARTMENT_IDS';
	private const RETURN_PARAM_PREVIOUS_DEPARTMENTS = 'PREVIOUS_DEPARTMENTS';
	private const RETURN_PARAM_NEW_DEPARTMENTS = 'NEW_DEPARTMENTS';
	private const RETURN_PARAM_PREVIOUS_ROLE = 'PREVIOUS_ROLE';
	private const RETURN_PARAM_NEW_ROLE = 'NEW_ROLE';
	private const RETURN_PARAM_EVENT_DATE_TIME = 'EventDateTime';

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			self::PARAM_USERS => null,
			self::PARAM_CHANGE_TYPES => null,
			self::RETURN_PARAM_USER => null,
			self::RETURN_PARAM_CHANGE_TYPE => null,
			self::RETURN_PARAM_PREVIOUS_DEPARTMENT_IDS => null,
			self::RETURN_PARAM_NEW_DEPARTMENT_IDS => null,
			self::RETURN_PARAM_PREVIOUS_DEPARTMENTS => null,
			self::RETURN_PARAM_NEW_DEPARTMENTS => null,
			self::RETURN_PARAM_PREVIOUS_ROLE => null,
			self::RETURN_PARAM_NEW_ROLE => null,
			self::RETURN_PARAM_EVENT_DATE_TIME => null,
		];

		$roleOptions = static::getRoleOptions();
		$this->SetPropertiesTypes([
			self::RETURN_PARAM_USER => ['Type' => FieldType::USER],
			self::RETURN_PARAM_CHANGE_TYPE => [
				'Type' => FieldType::SELECT,
				'Options' => static::getChangeTypeOptions(),
			],
			self::RETURN_PARAM_PREVIOUS_DEPARTMENT_IDS => ['Type' => FieldType::INT, 'Multiple' => true],
			self::RETURN_PARAM_NEW_DEPARTMENT_IDS => ['Type' => FieldType::INT, 'Multiple' => true],
			self::RETURN_PARAM_PREVIOUS_DEPARTMENTS => ['Type' => FieldType::STRING, 'Multiple' => true],
			self::RETURN_PARAM_NEW_DEPARTMENTS => ['Type' => FieldType::STRING, 'Multiple' => true],
			self::RETURN_PARAM_PREVIOUS_ROLE => ['Type' => FieldType::SELECT, 'Options' => $roleOptions],
			self::RETURN_PARAM_NEW_ROLE => ['Type' => FieldType::SELECT, 'Options' => $roleOptions],
			self::RETURN_PARAM_EVENT_DATE_TIME => ['Type' => FieldType::DATETIME],
		]);
	}

	public function execute(): int
	{
		$event = $this->getEventData();

		$userId = (int)($event[StructurePositionChangedTrigger::FIELD_USER_ID] ?? 0);
		if ($userId > 0)
		{
			$this->{self::RETURN_PARAM_USER} = 'user_' . $userId;
		}

		$this->{self::RETURN_PARAM_CHANGE_TYPE} =
			(string)($event[StructurePositionChangedTrigger::FIELD_CHANGE_TYPE] ?? '');
		$this->{self::RETURN_PARAM_PREVIOUS_DEPARTMENT_IDS} =
			self::intList($event[StructurePositionChangedTrigger::FIELD_PREVIOUS_NODE_IDS] ?? []);
		$this->{self::RETURN_PARAM_NEW_DEPARTMENT_IDS} =
			self::intList($event[StructurePositionChangedTrigger::FIELD_NEW_NODE_IDS] ?? []);
		$this->{self::RETURN_PARAM_PREVIOUS_DEPARTMENTS} =
			array_values((array)($event[StructurePositionChangedTrigger::FIELD_PREVIOUS_NODE_NAMES] ?? []));
		$this->{self::RETURN_PARAM_NEW_DEPARTMENTS} =
			array_values((array)($event[StructurePositionChangedTrigger::FIELD_NEW_NODE_NAMES] ?? []));
		$this->{self::RETURN_PARAM_PREVIOUS_ROLE} =
			(string)($event[StructurePositionChangedTrigger::FIELD_PREVIOUS_ROLE] ?? '');
		$this->{self::RETURN_PARAM_NEW_ROLE} = (string)($event[StructurePositionChangedTrigger::FIELD_NEW_ROLE] ?? '');

		$eventDateTime = $event[StructurePositionChangedTrigger::FIELD_EVENT_DATE_TIME] ?? null;
		if (is_string($eventDateTime) && $eventDateTime !== '')
		{
			$this->{self::RETURN_PARAM_EVENT_DATE_TIME} = $eventDateTime;
		}

		return CBPActivityExecutionStatus::Closed;
	}

	/**
	 * @return array<string, string>
	 */
	public static function getChangeTypeOptions(): array
	{
		$options = [];
		foreach (StructurePositionChangeType::cases() as $type)
		{
			$options[$type->value] = Loc::getMessage(match ($type)
			{
				StructurePositionChangeType::Added => 'HUMAN_RESOURCES_STRUCTURE_POSITION_TRIGGER_TYPE_ADDED',
				StructurePositionChangeType::Removed => 'HUMAN_RESOURCES_STRUCTURE_POSITION_TRIGGER_TYPE_REMOVED',
				StructurePositionChangeType::Moved => 'HUMAN_RESOURCES_STRUCTURE_POSITION_TRIGGER_TYPE_MOVED',
				StructurePositionChangeType::RoleAssigned =>
					'HUMAN_RESOURCES_STRUCTURE_POSITION_TRIGGER_TYPE_ROLE_ASSIGNED',
				StructurePositionChangeType::RoleRevoked =>
					'HUMAN_RESOURCES_STRUCTURE_POSITION_TRIGGER_TYPE_ROLE_REVOKED',
			}) ?? '';
		}

		return $options;
	}

	/**
	 * @return array<string, string>
	 */
	public static function getChangeGroupOptions(): array
	{
		$options = [];
		foreach (array_keys(self::CHANGE_GROUPS) as $group)
		{
			$options[$group] = Loc::getMessage(
				'HUMAN_RESOURCES_STRUCTURE_POSITION_TRIGGER_GROUP_' . strtoupper($group),
			) ?? '';
		}

		return $options;
	}

	/**
	 * @return array<string, string>
	 */
	public static function getRoleOptions(): array
	{
		$options = [];
		foreach (NodeMemberRole::cases() as $role)
		{
			$options[$role->value] = Loc::getMessage(match ($role)
			{
				NodeMemberRole::Head => 'HUMAN_RESOURCES_STRUCTURE_POSITION_TRIGGER_ROLE_HEAD',
				NodeMemberRole::DeputyHead => 'HUMAN_RESOURCES_STRUCTURE_POSITION_TRIGGER_ROLE_DEPUTY_HEAD',
				NodeMemberRole::Employee => 'HUMAN_RESOURCES_STRUCTURE_POSITION_TRIGGER_ROLE_EMPLOYEE',
				NodeMemberRole::TeamHead => 'HUMAN_RESOURCES_STRUCTURE_POSITION_TRIGGER_ROLE_TEAM_HEAD',
				NodeMemberRole::TeamDeputyHead => 'HUMAN_RESOURCES_STRUCTURE_POSITION_TRIGGER_ROLE_TEAM_DEPUTY_HEAD',
				NodeMemberRole::TeamEmployee => 'HUMAN_RESOURCES_STRUCTURE_POSITION_TRIGGER_ROLE_TEAM_EMPLOYEE',
			}) ?? '';
		}

		return $options;
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_USERS => [
				'Name' => Loc::getMessage('HUMAN_RESOURCES_STRUCTURE_POSITION_TRIGGER_PROPERTY_USERS') ?? '',
				'FieldName' => self::PARAM_USERS,
				'Type' => FieldType::USER,
				'Required' => false,
				'Multiple' => true,
				'Hint' => Loc::getMessage('HUMAN_RESOURCES_STRUCTURE_POSITION_TRIGGER_PROPERTY_USERS_HINT') ?? '',
			],
			self::PARAM_CHANGE_TYPES => [
				'Name' => Loc::getMessage('HUMAN_RESOURCES_STRUCTURE_POSITION_TRIGGER_PROPERTY_CHANGE_TYPES') ?? '',
				'FieldName' => self::PARAM_CHANGE_TYPES,
				'Type' => FieldType::SELECT,
				'Options' => static::getChangeGroupOptions(),
				'Required' => true,
				'Multiple' => true,
				'AllowSelection' => false,
			],
		];
	}

	public static function validateProperties($arTestProperties = [], ?\CBPWorkflowTemplateUser $user = null): array
	{
		$errors = [];
		foreach (static::getPropertiesMap([]) as $propertyId => $property)
		{
			if ($property['Required'] && \CBPHelper::isEmptyValue($arTestProperties[$propertyId] ?? null))
			{
				$errors[] = [
					'code' => 'NotExist',
					'parameter' => $propertyId,
					'message' => Loc::getMessage(
						'HUMAN_RESOURCES_STRUCTURE_POSITION_TRIGGER_PROPERTY_EMPTY',
						['#PROPERTY#' => $property['Name']],
					) ?? '',
				];
			}
		}

		foreach (\CBPHelper::flatten($arTestProperties[self::PARAM_USERS] ?? []) as $pickedUser)
		{
			if (!static::isExpression($pickedUser) && !preg_match('/^user_\d+$/', (string)$pickedUser))
			{
				$errors[] = [
					'code' => 'NotUser',
					'parameter' => self::PARAM_USERS,
					'message' => Loc::getMessage('HUMAN_RESOURCES_STRUCTURE_POSITION_TRIGGER_USERS_ONLY') ?? '',
				];
				break;
			}
		}

		return array_merge($errors, parent::validateProperties($arTestProperties, $user));
	}

	public static function getPropertiesDialog(
		$documentType,
		$activityName,
		$arWorkflowTemplate,
		$arWorkflowParameters,
		$arWorkflowVariables,
		$arCurrentValues = null,
		$formName = '',
		$popupWindow = null,
		$siteId = '',
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

	public function checkApplyRules(array $rules, TriggerParameters $parameters): Result
	{
		if (!$this->isChangeTypePicked((string)$parameters->get(StructurePositionChangedTrigger::FIELD_CHANGE_TYPE)))
		{
			return self::rejected(
				StructurePositionChangedTrigger::REFUSAL_CHANGE_TYPE,
				'the change type is not picked',
			);
		}

		$userId = (int)$parameters->get(StructurePositionChangedTrigger::FIELD_USER_ID);
		if ($userId <= 0)
		{
			return self::rejected(StructurePositionChangedTrigger::REFUSAL_NO_EMPLOYEE, 'the event carries no employee');
		}

		if ($this->hasPickedUsers() && !$this->isUserPicked($userId))
		{
			return self::rejected(
				StructurePositionChangedTrigger::REFUSAL_OUTSIDE_USERS,
				'the employee is not among the picked ones',
			);
		}

		return Result::createOk();
	}

	protected static function getModuleId(): ?string
	{
		return 'humanresources';
	}

	private function isChangeTypePicked(string $changeType): bool
	{
		foreach (\CBPHelper::flatten($this->getRawProperty(self::PARAM_CHANGE_TYPES)) as $group)
		{
			foreach (self::CHANGE_GROUPS[(string)$group] ?? [] as $type)
			{
				if ($type->value === $changeType)
				{
					return true;
				}
			}
		}

		return false;
	}

	private function hasPickedUsers(): bool
	{
		return !\CBPHelper::isEmptyValue($this->getRawProperty(self::PARAM_USERS));
	}

	private function isUserPicked(int $userId): bool
	{
		$pickedUserIds = \CBPHelper::extractUsers($this->{self::PARAM_USERS}, $this->getDocumentId());

		return in_array($userId, array_map('intval', $pickedUserIds), true);
	}

	/**
	 * @return list<int>
	 */
	private static function intList(mixed $values): array
	{
		return array_values(array_map('intval', (array)$values));
	}

	private static function rejected(string $code, string $message): Result
	{
		return Result::createError(new Error($message, $code));
	}
}
