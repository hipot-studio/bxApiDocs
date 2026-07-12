<?php

use Bitrix\Bizproc\Automation\Helper;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UserTable;
use Bitrix\Im\Model\MessageTable;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Error;
use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Tasks\Internals\Task\Result\ResultTable;
use Bitrix\Im\V2\Message\Text\BbCode\User;
use Bitrix\Tasks\Internals\Task\CheckListTable;
use Bitrix\Tasks\Internals\Task\MemberTable;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Main\Web\Json;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\Automation\Engine\ConditionGroup;
use Bitrix\Tasks\V2\Internal\DI\Container;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPTasksGetInfoActivity extends BaseActivity implements IBPConfigurableActivity
{
	use \Bitrix\Bizproc\Activity\Mixins\EntityFilter;

	private const MAX_MESSAGE_COUNT_LIMIT_MAX = 500;
	private const MESSAGE_COUNT_DEFAULT = 50;
	private const MESSAGE_COUNT_MIN = 0;
	private const TASKS_LIMIT_MAX = 500;
	private const TASKS_LIMIT_DEFAULT = 50;
	private const TASKS_LIMIT_MIN = 1;
	private const USER_NAME_SYSTEM = 'System';
	private const MESSAGES_DAYS_DEFAULT = 7;
	private const MESSAGES_DAYS_MAX = 365;
	private const MESSAGES_DAYS_MIN = 0;
	private const PARAM_USER_ID = 'PARAM_USER_ID';
	private const PARAM_MESSAGE_COUNT_LIMIT = 'MESSAGE_COUNT_LIMIT';
	private const PARAM_TASK_LIMIT = 'TaskLimit';
	private const PARAM_TASK_ACTIVITY_DAYS = 'TaskActivityDays';
	private const PARAM_MESSAGES_DAYS = 'MessagesDays';
	private const PARAM_PROJECT_ID = 'ProjectId';
	private const PARAM_USER_TASK_ROLE = 'UserTaskRole';
	private const PARAM_TASK_STATUS_FILTER = 'TaskStatusFilter';
	private const PARAM_TASK_SELECT_FIELD = 'TaskSelectField';
	private const PARAM_ONLY_OVERDUE = 'OnlyOverdue';
	private const PARAM_EXCLUDE_RESPONSIBLE_ROLE = 'ExcludeIfAlsoResponsible';
	private const PARAM_CONDITION = 'DynamicFilterFields';
	private const FILTER_CUSTOM_TYPE = 'filterFields';
	private const FILTER_FIELD_NAME = 'filter_fields';
	private const RETURN_PARAM_TASKS_INFO_JSON = 'TASKS_INFO_JSON';
	private const RETURN_PARAM_COUNTER_TASKS_INFO = 'COUNTER_TASKS_INFO';
	private const RETURN_PARAM_TASKS_TITLES_LIST = 'TASKS_TITLES_LIST';
	private const RETURN_PARAM_TASKS_TITLES_BB_LIST = 'TASKS_TITLES_BB_LIST';
	private const SELECT_FIELD_DESCRIPTION = 'description';
	private const SELECT_FIELD_STATUS = 'status';
	private const SELECT_FIELD_DEADLINE = 'deadline';
	private const SELECT_FIELD_ORIGINATOR = 'originator';
	private const SELECT_FIELD_RESPONSIBLE = 'responsible';
	private const SELECT_FIELD_ACCOMPLICE = 'accomplice';
	private const SELECT_FIELD_AUDITOR = 'auditor';
	private const SELECT_FIELD_CHECKLISTS = 'checklists';
	private const SELECT_FIELD_RESULTS = 'results';
	private const SELECT_FIELD_CHAT = 'chat';
	private const SELECT_FIELD_ACTIVITY_DATE = 'activityDate';
	private const SELECT_FIELD_IS_OVERDUE = 'isOverdue';
	private const SELECT_FIELD_URL = 'url';
	private const SELECT_FIELD_PRIORITY = 'priority';
	private const SELECT_FIELD_PROJECT = 'project';
	private const SELECT_FIELD_DAYS_WITHOUT_UPDATES = 'daysWithoutUpdates';
	private const SELECT_FIELD_DAYS_ON_CONTROL = 'daysOnControl';
	private const PROPERTY_MAP_FIELD_NAME_FOR_ERROR_MESSAGE = 'nameForErrorMessage';
	private const PROPERTY_MAP_FIELD_NAME = 'Name';

	protected static $requiredModules = [
		'tasks',
		'im',
	];

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			self::PARAM_USER_ID => null,
			self::PARAM_PROJECT_ID => null,
			self::PARAM_MESSAGE_COUNT_LIMIT => null,
			self::PARAM_MESSAGES_DAYS => null,
			self::PARAM_TASK_ACTIVITY_DAYS => null,
			self::PARAM_TASK_LIMIT => null,
			self::PARAM_USER_TASK_ROLE => null,
			self::PARAM_TASK_STATUS_FILTER => null,
			self::PARAM_TASK_SELECT_FIELD => null,
			self::PARAM_ONLY_OVERDUE => null,
			self::PARAM_EXCLUDE_RESPONSIBLE_ROLE => null,
			self::PARAM_CONDITION => ['items' => []],
			self::RETURN_PARAM_TASKS_INFO_JSON => null,
			self::RETURN_PARAM_COUNTER_TASKS_INFO => null,
			self::RETURN_PARAM_TASKS_TITLES_LIST => null,
			self::RETURN_PARAM_TASKS_TITLES_BB_LIST => null,
		];

		$this->setPropertiesTypes([
			self::RETURN_PARAM_TASKS_INFO_JSON => [
				'Type' => FieldType::JSON,
			],
			self::RETURN_PARAM_COUNTER_TASKS_INFO => [
				'Type' => FieldType::INT,
			],
			self::RETURN_PARAM_TASKS_TITLES_LIST => [
				'Type' => FieldType::TEXT,
			],
			self::RETURN_PARAM_TASKS_TITLES_BB_LIST => [
				'Type' => FieldType::TEXT,
			],
		]);
	}

	protected function reInitialize(): void
	{
		parent::reInitialize();

		$this->{self::RETURN_PARAM_TASKS_INFO_JSON} = null;
		$this->{self::RETURN_PARAM_COUNTER_TASKS_INFO} = null;
		$this->{self::RETURN_PARAM_TASKS_TITLES_LIST} = null;
		$this->{self::RETURN_PARAM_TASKS_TITLES_BB_LIST} = null;
	}

	protected function internalExecute(): ErrorCollection
	{
		$errors = new ErrorCollection();
		$userId = $this->getTargetUserId();
		$projectId = $this->getTargetProjectId();

		try
		{
			[$tasks, $userIdList, $chatIdList] = $this->getTasks($userId, $projectId);
			$formattedTasks = $this->prepareTasks($tasks, $userIdList, $chatIdList);
			[$titles, $bbTitles] = $this->buildTitleLists($tasks, $userId, $projectId);
			$this->{self::RETURN_PARAM_TASKS_INFO_JSON} = Json::encode(
				$formattedTasks,
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
			);
			$this->{self::RETURN_PARAM_COUNTER_TASKS_INFO} = count($tasks);
			$this->{self::RETURN_PARAM_TASKS_TITLES_LIST} = implode("\n", $titles);
			$this->{self::RETURN_PARAM_TASKS_TITLES_BB_LIST} = implode("\n", $bbTitles);
		}
		catch (\Throwable $exception)
		{
			$errors->setError(new Error($exception->getMessage()));
		}

		return $errors;
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_USER_ID => [
				'Name' => Loc::getMessage('TASKS_GET_INFO_FIELD_USER_ID'),
				'FieldName' => self::PARAM_USER_ID,
				'Type' => FieldType::USER,
				self::PROPERTY_MAP_FIELD_NAME_FOR_ERROR_MESSAGE => Loc::getMessage('TASKS_GET_INFO_FIELD_USER_ID_NAME'),
			],
			self::PARAM_PROJECT_ID => [
				'Name' => Loc::getMessage('TASKS_GET_INFO_FIELD_PROJECT_ID'),
				'FieldName' => self::PARAM_PROJECT_ID,
				'Type' => FieldType::ENTITYSELECTOR,
				'Settings' => [
					'entity' => [
						'id' => 'project',
						'dynamicLoad' => true,
						'dynamicSearch' => true,
					],
				],
				self::PROPERTY_MAP_FIELD_NAME_FOR_ERROR_MESSAGE => Loc::getMessage('TASKS_GET_INFO_FIELD_PROJECT_ID_NAME'),
			],
			self::PARAM_MESSAGE_COUNT_LIMIT => [
				'Name' => Loc::getMessage('TASKS_GET_INFO_FIELD_MESSAGE_COUNT_LIMIT'),
				'FieldName' => self::PARAM_MESSAGE_COUNT_LIMIT,
				'Type' => FieldType::INT,
				'Default' => self::MESSAGE_COUNT_DEFAULT,
				self::PROPERTY_MAP_FIELD_NAME_FOR_ERROR_MESSAGE => Loc::getMessage('TASKS_GET_INFO_FIELD_MESSAGE_COUNT_LIMIT_NAME'),
			],
			self::PARAM_TASK_LIMIT => [
				'Name' => Loc::getMessage('TASKS_GET_INFO_FIELD_TASK_LIMIT'),
				'FieldName' => self::PARAM_TASK_LIMIT,
				'Type' => FieldType::INT,
				'Default' => self::TASKS_LIMIT_DEFAULT,
				self::PROPERTY_MAP_FIELD_NAME_FOR_ERROR_MESSAGE => Loc::getMessage('TASKS_GET_INFO_FIELD_TASK_LIMIT_NAME'),
			],
			self::PARAM_MESSAGES_DAYS => [
				'Name' => Loc::getMessage('TASKS_GET_INFO_FIELD_MESSAGE_DAYS'),
				'FieldName' => self::PARAM_MESSAGES_DAYS,
				'Type' => FieldType::INT,
				'Default' => self::MESSAGES_DAYS_DEFAULT,
				self::PROPERTY_MAP_FIELD_NAME_FOR_ERROR_MESSAGE => Loc::getMessage('TASKS_GET_INFO_FIELD_MESSAGE_DAYS_NAME'),
			],
			self::PARAM_USER_TASK_ROLE => [
				'Name' => Loc::getMessage('TASKS_GET_INFO_FIELD_USER_TASK_ROLE'),
				'FieldName' => self::PARAM_USER_TASK_ROLE,
				'Type' => FieldType::SELECT,
				'Default' => array_keys(self::getTaskRoles()),
				'Options' => self::getTaskRoles(),
				'Multiple' => true,
				self::PROPERTY_MAP_FIELD_NAME_FOR_ERROR_MESSAGE => Loc::getMessage('TASKS_GET_INFO_FIELD_USER_TASK_ROLE_NAME'),
			],
			self::PARAM_TASK_STATUS_FILTER => [
				'Name' => Loc::getMessage('TASKS_GET_INFO_FIELD_TASK_STATUS_FILTER'),
				'FieldName' => self::PARAM_TASK_STATUS_FILTER,
				'Type' => FieldType::SELECT,
				'Default' => array_keys(self::getTaskStatuses()),
				'Options' => self::getTaskStatuses(),
				'Multiple' => true,
				self::PROPERTY_MAP_FIELD_NAME_FOR_ERROR_MESSAGE => Loc::getMessage('TASKS_GET_INFO_FIELD_TASK_STATUS_FILTER_NAME'),
			],
			self::PARAM_TASK_SELECT_FIELD  => [
				'Name' => Loc::getMessage('TASKS_GET_INFO_FIELD_TASK_SELECT_FIELD'),
				'FieldName' => self::PARAM_TASK_SELECT_FIELD,
				'Type' => FieldType::SELECT,
				'Default' => array_keys(self::getSelectedFieldsOptions()),
				'Options' => self::getSelectedFieldsOptions(),
				'Multiple' => true,
				self::PROPERTY_MAP_FIELD_NAME_FOR_ERROR_MESSAGE => Loc::getMessage('TASKS_GET_INFO_FIELD_TASK_SELECT_FIELD_NAME'),
			],
			self::PARAM_EXCLUDE_RESPONSIBLE_ROLE => [
				'Name' => Loc::getMessage('TASKS_GET_INFO_FIELD_EXCLUDE_RESPONSIBLE_ROLE'),
				'FieldName' => self::PARAM_EXCLUDE_RESPONSIBLE_ROLE,
				'Type' => FieldType::BOOL,
				'Default' => 'N',
				self::PROPERTY_MAP_FIELD_NAME_FOR_ERROR_MESSAGE => Loc::getMessage('TASKS_GET_INFO_FIELD_EXCLUDE_RESPONSIBLE_ROLE_NAME'),
			],
			self::PARAM_CONDITION => [
				'Name' => Loc::getMessage('TASKS_GET_INFO_FIELD_CONDITION'),
				'FieldName' => self::FILTER_FIELD_NAME,
				'Type' => FieldType::CUSTOM,
				'CustomType' => self::FILTER_CUSTOM_TYPE,
				'Required' => false,
				'AllowSelection' => true,
				'Options' => [
					'documentType' => self::getFilterDocumentType(),
					'filteringFieldsPrefix' => self::FILTER_FIELD_NAME . '_',
					'filterFieldsMap' => array_values(self::getFilteringFieldsMap()),
					'conditions' => self::resolveConditions($context),
					'collapsedCaption' => Loc::getMessage('TASKS_GET_INFO_FIELD_CONDITION_COLLAPSED'),
				],
				self::PROPERTY_MAP_FIELD_NAME_FOR_ERROR_MESSAGE => Loc::getMessage('TASKS_GET_INFO_FIELD_CONDITION'),
			],
		];
	}

	protected function prepareProperties(): void
	{
		parent::prepareProperties();

		$this->setDefaultValuesToNullProperties();
		$this->filterIncorrectSelectTypeProperties();
	}

	protected function checkProperties(): ErrorCollection
	{
		$errorCollection = new ErrorCollection();

		$hasUserId = !empty($this->getTargetUserId());
		$hasProjectId = !empty($this->getTargetProjectId());

		if (!$hasUserId && !$hasProjectId)
		{
			$errorCollection->setError(
				new Error(Loc::getMessage('TASKS_GET_INFO_FIELD_ERROR_REQUIRED_ONE_OF') ?? ''),
			);
		}

		foreach (self::getPropertiesDialogMap() as $propertyId => $propertyFields)
		{
			$type = $propertyFields['Type'] ?? null;
			$isRequired = (bool)($propertyFields['Required'] ?? false);

			if ($propertyId === self::PARAM_USER_TASK_ROLE)
			{
				$isRequired = $hasUserId;
			}

			$error = match ($type)
			{
				FieldType::INT => $this->checkIntRuntimePropertyValue($propertyId),
				FieldType::SELECT => $this->checkSelectRuntimePropertyValue($propertyId, $isRequired),
				default => null,
			};

			if ($error)
			{
				$errorCollection->setError($error);
			}
		}

		return $errorCollection;
	}

	public static function validateProperties($arTestProperties = [], ?CBPWorkflowTemplateUser $user = null): array
	{
		$errors = [];

		$hasUserId = !empty($arTestProperties[self::PARAM_USER_ID]);
		$hasProjectId = !empty($arTestProperties[self::PARAM_PROJECT_ID]);

		if (!$hasUserId && !$hasProjectId)
		{
			$errors[] = [
				'message' => Loc::getMessage('TASKS_GET_INFO_FIELD_ERROR_REQUIRED_ONE_OF') ?? '',
			];
		}

		foreach (self::getPropertiesMap([]) as $id => $property)
		{
			$value = $arTestProperties[$id] ?? null;
			$error = match ($property['Type'] ?? null)
			{
				FieldType::INT => self::validateIntRangeValue($id, $value),
				default => null,
			};

			if ($id === self::PARAM_USER_TASK_ROLE && $hasUserId && \CBPHelper::isEmptyValue($value))
			{
				$errors[] = [
					'message' => Loc::getMessage('TASKS_GET_INFO_FIELD_ERROR_REQUIRED_USER_ROLE'),
				];
			}

			if ($error)
			{
				$errors[] = $error->jsonSerialize();
			}
		}

		return array_merge($errors, parent::ValidateProperties($arTestProperties, $user));
	}

	private function getTasks(?int $userId, ?int $projectId): array
	{
		$filter = [];

		if ($projectId)
		{
			$filter['=GROUP_ID'] = $projectId;
		}

		if ($userId)
		{
			$filter['=MEMBERS.USER_ID'] = $userId;
		}

		$filter = array_merge($filter, $this->getFieldFilter($userId));

		$conditionFilter = $this->getConditionOrmFilter();
		if ($conditionFilter)
		{
			$filter[] = $conditionFilter;
		}
		else
		{
			$filter = array_merge($filter, $this->getLegacyDateFilter());
		}

		$tasksResult = TaskTable::query()
			->setSelect($this->getTaskQuerySelectFields())
			->setFilter($filter)
			->setGroup(['ID'])
			->setOrder(['ACTIVITY_DATE' => 'DESC'])
			->setLimit((int)$this->{self::PARAM_TASK_LIMIT})
			->exec()
		;

		$userIdList = [];
		$tasks = [];
		$chatIdList = [];
		while ($row = $tasksResult->fetch())
		{
			$statusCode = $row['STATUS'] ?? null;
			$status = Bitrix\Tasks\UI\Task\Status::getList()[$statusCode] ?? $statusCode;
			$tasks[$row['ID']] = [
				'TITLE' => $row['TITLE'],
				'DESCRIPTION' => $row['DESCRIPTION'] ?? null,
				'STATUS' => $status,
				'STATUS_CODE' => $statusCode,
				'STATUS_CHANGED_DATE' => $row['STATUS_CHANGED_DATE'] ?? null,
				'RESPONSIBLE' => $row['RESPONSIBLE_ID'] ?? null,
				'DEADLINE' => $row['DEADLINE'] ?? null,
				'CREATOR' => $row['CREATED_BY'] ?? null,
				'ACTIVITY_DATE' => $row['ACTIVITY_DATE'] ?? null,
				'CHAT_ID' => $row['CHAT_ID'] ?? null,
				'PRIORITY' => $row['PRIORITY'] ?? null,
				'PROJECT_NAME' => $row['PROJECT_NAME'] ?? null,
			];

			if (!empty($row['CHAT_ID']))
			{
				$chatIdList[$row['CHAT_ID']] = $row['CHAT_ID'];
			}

			if (!empty($row['RESPONSIBLE_ID']))
			{
				$userIdList[$row['RESPONSIBLE_ID']] = $row['RESPONSIBLE_ID'];
			}

			if (!empty($row['CREATED_BY']))
			{
				$userIdList[$row['CREATED_BY']] = $row['CREATED_BY'];
			}
		}

		return [$tasks, $userIdList, $chatIdList];
	}

	private function fetchChecklists(array $taskIds, array &$userIdList): array
	{
		if (empty($taskIds))
		{
			return [];
		}

		$userSelectedFields = (array)$this->{self::PARAM_TASK_SELECT_FIELD};
		if (!in_array(self::SELECT_FIELD_CHECKLISTS, $userSelectedFields, true))
		{
			return [];
		}

		$checkListResult = CheckListTable::query()
			->setSelect([
				'TITLE',
				'TOGGLED_BY',
				'IS_COMPLETE',
				'TASK_ID',
			])
			->whereIn('TASK_ID', $taskIds)
			->exec()
		;

		$list = [];
		while ($row = $checkListResult->fetch())
		{
			$list[$row['TASK_ID']][] = [
				'TITLE' => $row['TITLE'],
				'TOGGLED_BY' => $row['TOGGLED_BY'],
				'IS_COMPLETE' => $row['IS_COMPLETE'],
			];

			$userIdList[$row['TOGGLED_BY']] ??= $row['TOGGLED_BY'];
		}

		return $list;
	}

	private function fetchResults(array $taskIds, array &$userIdList): array
	{
		if (empty($taskIds))
		{
			return [];
		}

		$userSelectedFields = (array)$this->{self::PARAM_TASK_SELECT_FIELD};
		if (!in_array(self::SELECT_FIELD_RESULTS, $userSelectedFields, true))
		{
			return [];
		}

		$result = ResultTable::query()
			->setSelect([
				'TASK_ID',
				'TEXT',
				'CREATED_BY',
			])
			->whereIn('TASK_ID', $taskIds)
			->exec()
		;

		$list = [];
		while ($row = $result->fetch())
		{
			$list[$row['TASK_ID']][] = [
				'TEXT' => $row['TEXT'],
				'CREATOR' => $row['CREATED_BY'],
			];

			$userIdList[$row['CREATED_BY']] ??= $row['CREATED_BY'];
		}

		return $list;
	}

	private function fetchMessagesByChatId(
		int $chatId,
		int $limit,
		array &$userIdList,
	): array
	{
		$messageDays = (int)$this->{self::PARAM_MESSAGES_DAYS};

		$chatResult = MessageTable::query()
			->setSelect([
				'AUTHOR_ID',
				'MESSAGE',
				'DATE_CREATE',
			])
			->where('DATE_CREATE', '>=', (new DateTime())->add("-$messageDays days"))
			->where('CHAT_ID', $chatId)
			->where('MESSAGE', '!=', '')
			->whereNotNull('MESSAGE')
			->setOrder(['DATE_CREATE' => 'DESC'])
			->setLimit($limit)
			->exec()
		;

		$list = [];
		while ($row = $chatResult->fetch())
		{
			if (empty($row['MESSAGE']))
			{
				continue;
			}

			$dateCreate = $row['DATE_CREATE'] instanceof DateTime
				? $row['DATE_CREATE']->format('c')
				: $row['DATE_CREATE'];

			$authorId = (int)$row['AUTHOR_ID'];

			$list[] = [
				'AUTHOR' => $authorId,
				'MESSAGE' => $row['MESSAGE'],
				'DATE' => $dateCreate,
			];

			$userIdList[$authorId] ??= $authorId;
		}

		return array_values(array_reverse($list));
	}

	private function fetchMembers(array $taskIds, array &$userIdList): array
	{
		if (empty($taskIds))
		{
			return [];
		}

		$userSelectedFields = (array)$this->{self::PARAM_TASK_SELECT_FIELD};
		$memberTypes = [];
		if (in_array(self::SELECT_FIELD_ACCOMPLICE, $userSelectedFields, true))
		{
			$memberTypes[] = MemberTable::MEMBER_TYPE_ACCOMPLICE;
		}

		if (in_array(self::SELECT_FIELD_AUDITOR, $userSelectedFields, true))
		{
			$memberTypes[] = MemberTable::MEMBER_TYPE_AUDITOR;
		}

		if (empty($memberTypes))
		{
			return [];
		}

		$memberResult = MemberTable::query()
			->setSelect([
				'TASK_ID',
				'USER_ID',
				'TYPE',
			])
			->whereIn('TASK_ID', $taskIds)
			->whereIn('TYPE', $memberTypes)
			->exec()
		;

		$list = [];
		while ($row = $memberResult->fetch())
		{
			$list[$row['TASK_ID']] ??= [
				MemberTable::MEMBER_TYPE_ACCOMPLICE => [],
				MemberTable::MEMBER_TYPE_AUDITOR => [],
			];

			$list[$row['TASK_ID']][$row['TYPE']][] = $row['USER_ID'];

			$userIdList[$row['USER_ID']] ??= $row['USER_ID'];
		}

		return $list;
	}

	private function formatUser(array $author): string
	{
		$userId = (int)$author['ID'];
		$userName = self::USER_NAME_SYSTEM;
		if (!empty($userId))
		{
			$userName = \CUser::formatName(
				\CSite::GetNameFormat(),
				[
					'NAME' => $author['NAME'],
					'LAST_NAME' => $author['LAST_NAME'],
					'LOGIN' => $author['LOGIN'],
				],
			);
		}

		return User::build(
			$userId,
			$userName,
		)->compile();
	}

	private function getFormatUserByIds(array $ids): array
	{
		$ids = array_filter($ids);
		if (empty($ids))
		{
			return [];
		}

		$userResult = UserTable::query()
			->setSelect([
				'ID',
				'NAME',
				'LAST_NAME',
				'LOGIN',
			])
			->whereIn('ID', $ids)
			->exec()
		;

		$list = [];
		while ($row = $userResult->fetch())
		{
			$list[$row['ID']] = $this->formatUser($row);
		}

		return $list;
	}

	private function prepareTasks(array $tasks, array $userIdList, array $chatIds): array
	{
		if (empty($tasks))
		{
			return [];
		}

		$taskIds = array_keys($tasks);

		$memberList = $this->fetchMembers($taskIds, $userIdList);
		$checklistList = $this->fetchChecklists($taskIds, $userIdList);
		$resultList = $this->fetchResults($taskIds, $userIdList);

		$chatMessages = [];
		foreach ($chatIds as $chatId)
		{
			$chatMessages[$chatId] = $this->fetchMessagesByChatId(
				$chatId,
				$this->{self::PARAM_MESSAGE_COUNT_LIMIT},
				$userIdList,
			);
		}

		$formatUsers = $this->getFormatUserByIds(array_keys($userIdList));

		$this->replaceUserField($checklistList, 'TOGGLED_BY', $formatUsers);
		$this->replaceUserField($resultList, 'CREATOR', $formatUsers);

		foreach ($memberList as &$member)
		{
			$this->replaceUserIds($member[MemberTable::MEMBER_TYPE_ACCOMPLICE], $formatUsers);
			$this->replaceUserIds($member[MemberTable::MEMBER_TYPE_AUDITOR], $formatUsers);
		}
		unset($member);

		$this->replaceUserField($chatMessages, 'AUTHOR', $formatUsers, true);

		$userSelectedFields = (array)$this->{self::PARAM_TASK_SELECT_FIELD};
		$showStatus = in_array(self::SELECT_FIELD_STATUS, $userSelectedFields, true);
		$showActivityDate = in_array(self::SELECT_FIELD_ACTIVITY_DATE, $userSelectedFields, true);
		$showDeadline = in_array(self::SELECT_FIELD_DEADLINE, $userSelectedFields, true);
		$showIsOverdue = in_array(self::SELECT_FIELD_IS_OVERDUE, $userSelectedFields, true);
		$showUrl = in_array(self::SELECT_FIELD_URL, $userSelectedFields, true);
		$showDaysWithoutUpdates = in_array(self::SELECT_FIELD_DAYS_WITHOUT_UPDATES, $userSelectedFields, true);
		$showDaysOnControl = in_array(self::SELECT_FIELD_DAYS_ON_CONTROL, $userSelectedFields, true);
		$linkService = Container::getInstance()->getLinkService();
		$userId = (int)$this->getTargetUserId();
		$projectId = $this->getTargetProjectId();
		$group = $projectId !== null
			? new Bitrix\Tasks\V2\Internal\Entity\Group(id: $projectId)
			: null
		;

		$formattedTasks = [];
		foreach ($tasks as $taskId => $task)
		{
			$formattedTask = [
				'TITLE' => $task['TITLE'],
			];

			if (!empty($task['DESCRIPTION']))
			{
				$formattedTask['DESCRIPTION'] = $task['DESCRIPTION'];
			}

			if ($showStatus && !empty($task['STATUS']))
			{
				$formattedTask['STATUS'] = $task['STATUS'];
			}

			if ($showActivityDate && isset($task['ACTIVITY_DATE']) && $task['ACTIVITY_DATE'] instanceof DateTime)
			{
				$formattedTask['ACTIVITY_DATE'] = $task['ACTIVITY_DATE']->format('c');
			}

			if ($showDeadline && isset($task['DEADLINE']) && $task['DEADLINE'] instanceof DateTime)
			{
				$formattedTask['DEADLINE'] = $task['DEADLINE']->format('c');
			}

			if (!empty($task['RESPONSIBLE']))
			{
				$formattedTask['RESPONSIBLE'] = $formatUsers[$task['RESPONSIBLE']] ?? $task['RESPONSIBLE'];
			}

			if (!empty($task['CREATOR']))
			{
				$formattedTask['CREATOR'] = $formatUsers[$task['CREATOR']] ?? $task['CREATOR'];
			}

			if (!empty($checklistList[$taskId]))
			{
				$formattedTask['CHECKLISTS'] = $checklistList[$taskId];
			}

			if (!empty($resultList[$taskId]))
			{
				$formattedTask['RESULTS'] = $resultList[$taskId];
			}

			if (!empty($memberList[$taskId][MemberTable::MEMBER_TYPE_ACCOMPLICE]))
			{
				$formattedTask['ACCOMPLICES'] = $memberList[$taskId][MemberTable::MEMBER_TYPE_ACCOMPLICE];
			}

			if (!empty($memberList[$taskId][MemberTable::MEMBER_TYPE_AUDITOR]))
			{
				$formattedTask['AUDITORS'] = $memberList[$taskId][MemberTable::MEMBER_TYPE_AUDITOR];
			}

			if (!empty($task['CHAT_ID']))
			{
				$formattedTask['CHAT_INFO'] = $chatMessages[$task['CHAT_ID']] ?? [];
			}

			if ($showIsOverdue && isset($task['DEADLINE']) && $task['DEADLINE'] instanceof DateTime)
			{
				$formattedTask['IS_OVERDUE'] = $task['DEADLINE']->getTimestamp() <= time();
			}

			if ($showDaysWithoutUpdates && isset($task['ACTIVITY_DATE']) && $task['ACTIVITY_DATE'] instanceof DateTime)
			{
				$formattedTask['DAYS_WITHOUT_UPDATES'] = self::calculateDaysSince($task['ACTIVITY_DATE']);
			}

			if (
				$showDaysOnControl
				&& (int)($task['STATUS_CODE'] ?? 0) === Status::SUPPOSEDLY_COMPLETED
				&& isset($task['STATUS_CHANGED_DATE'])
				&& $task['STATUS_CHANGED_DATE'] instanceof DateTime
			)
			{
				$daysOnControl = self::calculateDaysSince($task['STATUS_CHANGED_DATE']);

				if ($daysOnControl > 0)
				{
					$formattedTask['DAYS_ON_CONTROL'] = $daysOnControl;
				}
			}

			if ($showUrl)
			{
				$taskEntity = new Bitrix\Tasks\V2\Internal\Entity\Task(id: (int)$taskId, group: $group);
				$formattedTask['URL'] = '/' . ltrim($linkService->get($taskEntity, $userId), '/');
			}

			if (isset($task['PRIORITY']))
			{
				$formattedTask['PRIORITY'] = (int)$task['PRIORITY'];
			}

			if (!empty($task['PROJECT_NAME']))
			{
				$formattedTask['PROJECT_NAME'] = $task['PROJECT_NAME'];
			}

			$formattedTasks[] = $formattedTask;
		}

		return $formattedTasks;
	}

	private function buildTitleLists(array $tasks, ?int $userId, ?int $projectId): array
	{
		$titles = [];
		$bbTitles = [];

		if (empty($tasks))
		{
			return [$titles, $bbTitles];
		}

		$linkService = Container::getInstance()->getLinkService();
		$group = $projectId !== null
			? new Bitrix\Tasks\V2\Internal\Entity\Group(id: $projectId)
			: null
		;
		$linkUserId = (int)$userId;

		foreach ($tasks as $taskId => $task)
		{
			$title = (string)($task['TITLE'] ?? '');
			$titles[] = '- ' . $title;

			$taskEntity = new Bitrix\Tasks\V2\Internal\Entity\Task(id: (int)$taskId, group: $group);
			$url = '/' . ltrim($linkService->get($taskEntity, $linkUserId), '/');
			$bbTitles[] = '- [url=' . $url . ']' . $title . '[/url]';
		}

		return [$titles, $bbTitles];
	}

	private function replaceUserField(array &$list, string $field, array $users, bool $useUnsetEmptyId = false): void
	{
		foreach ($list as &$group)
		{
			foreach ($group as &$item)
			{
				$id = $item[$field] ?? null;
				if ($id !== null)
				{
					if ($useUnsetEmptyId && empty($id))
					{
						unset($item[$field]);
					}
					elseif (isset($users[$id]))
					{
						$item[$field] = $users[$id];
					}
				}
			}
		}
		unset($group, $item);
	}

	private function replaceUserIds(array &$list, array $users): void
	{
		foreach ($list as &$id)
		{
			if (isset($users[$id]))
			{
				$id = $users[$id];
			}
		}
		unset($id);
	}

	private static function calculateDaysSince(DateTime $dateTime): int
	{
		$diff = (new DateTime())->getTimestamp() - $dateTime->getTimestamp();

		return max(0, (int)floor($diff / 86400));
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return array_map(
			static function(array $property): array
			{
				$property[self::PROPERTY_MAP_FIELD_NAME] = $property[self::PROPERTY_MAP_FIELD_NAME_FOR_ERROR_MESSAGE];

				return $property;
			},
			self::getPropertiesMap([]),
		);
	}

	protected static function extractPropertiesValues(PropertiesDialog $dialog, array $fieldsMap): \Bitrix\Main\Result
	{
		$simpleMap = $fieldsMap;
		unset($simpleMap[self::PARAM_CONDITION]);
		$result = parent::extractPropertiesValues($dialog, $simpleMap);

		if ($result->isSuccess())
		{
			$currentValues = $result->getData();
			$currentValues[self::PARAM_CONDITION] = static::extractFilterFromProperties($dialog, $fieldsMap)->getData();
			$result->setData($currentValues);
		}

		return $result;
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}

	/**
	 * @return array<string, string>
	 */
	private static function getTaskRoles(): array
	{
		if (!Loader::includeModule('tasks'))
		{
			return [];
		}

		return [
			MemberTable::MEMBER_TYPE_ORIGINATOR => Loc::getMessage('TASKS_GET_INFO_TASK_ROLE_ORIGINATOR'),
			MemberTable::MEMBER_TYPE_RESPONSIBLE => Loc::getMessage('TASKS_GET_INFO_TASK_ROLE_RESPONSIBLE'),
			MemberTable::MEMBER_TYPE_ACCOMPLICE => Loc::getMessage('TASKS_GET_INFO_TASK_ROLE_ACCOMPLICE'),
			MemberTable::MEMBER_TYPE_AUDITOR => Loc::getMessage('TASKS_GET_INFO_TASK_ROLE_AUDITOR'),
		];
	}

	/**
	 * @return array<int, string>
	 */
	private static function getTaskStatuses(): array
	{
		return [
			Status::NEW => Status::getMessage(Status::NEW),
			Status::PENDING => Status::getMessage(Status::PENDING),
			Status::IN_PROGRESS => Status::getMessage(Status::IN_PROGRESS),
			Status::SUPPOSEDLY_COMPLETED => Status::getMessage(Status::SUPPOSEDLY_COMPLETED),
			Status::COMPLETED => Status::getMessage(Status::COMPLETED),
			Status::DEFERRED => Status::getMessage(Status::DEFERRED),
			Status::DECLINED => Status::getMessage(Status::DECLINED),
		];
	}

	private static function getFilteringFieldsMap(): array
	{
		$documentFields = Helper::getDocumentFields(self::getFilterDocumentType());

		$map = [];
		foreach (['CREATED_DATE', 'CLOSED_DATE', 'DEADLINE', 'ACTIVITY_DATE'] as $fieldId)
		{
			if (isset($documentFields[$fieldId]))
			{
				$map[$fieldId] = $documentFields[$fieldId];
			}
		}

		return $map;
	}

	private static function getFilterDocumentType(): array
	{
		return \Bitrix\Tasks\Integration\Bizproc\Document\Task::resolveDocumentType();
	}

	private function getConditionOrmFilter(): ?array
	{
		$conditionGroup = new ConditionGroup($this->{self::PARAM_CONDITION});
		$ormFilter = $this->getOrmFilter($conditionGroup, self::getFilterDocumentType(), self::getFilteringFieldsMap());

		if (!$this->isOrmFilterValid())
		{
			return null;
		}

		foreach ($ormFilter as $key => $part)
		{
			if ($key !== 'LOGIC' && !empty($part))
			{
				return $ormFilter;
			}
		}

		return null;
	}

	private function getFieldFilter(?int $userId): array
	{
		$filter = [];

		if ($userId)
		{
			$filter['@MEMBERS.TYPE'] = (array)$this->{self::PARAM_USER_TASK_ROLE};

			if (\CBPHelper::getBool($this->{self::PARAM_EXCLUDE_RESPONSIBLE_ROLE}))
			{
				$filter['!=RESPONSIBLE_ID'] = $userId;
			}
		}

		$statusFilter = (array)$this->{self::PARAM_TASK_STATUS_FILTER};
		$allStatuses = array_keys(self::getTaskStatuses());
		$statusFilter = array_intersect($statusFilter, $allStatuses);
		if (!empty($statusFilter) && !empty(array_diff($allStatuses, $statusFilter)))
		{
			$filter['@STATUS'] = array_map('intval', $statusFilter);
		}

		return $filter;
	}

	private function getLegacyDateFilter(): array
	{
		$filter = [];

		$activityDays = (int)$this->{self::PARAM_TASK_ACTIVITY_DAYS};
		if ($activityDays > 0)
		{
			$filter['>ACTIVITY_DATE'] = (new DateTime())->add("-$activityDays days");
		}

		if (\CBPHelper::getBool($this->{self::PARAM_ONLY_OVERDUE}))
		{
			$filter['<=DEADLINE'] = new DateTime();
		}

		return $filter;
	}

	private static function resolveConditions(array $context): array
	{
		$properties = $context['Properties'] ?? [];
		$condition = $properties[self::PARAM_CONDITION] ?? null;

		if (is_array($condition['items'] ?? null) && !empty($condition['items']))
		{
			return $condition;
		}

		return self::migrateLegacyDateConditions($properties);
	}

	private static function migrateLegacyDateConditions(array $properties): array
	{
		$items = [];

		$activityDays = $properties[self::PARAM_TASK_ACTIVITY_DAYS] ?? null;
		$hasActivityDays = is_numeric($activityDays)
			? (int)$activityDays > 0
			: !\CBPHelper::isEmptyValue($activityDays)
		;
		if ($hasActivityDays)
		{
			$items[] = [
				[
					'object' => 'Document',
					'field' => 'ACTIVITY_DATE',
					'operator' => '>=',
					'value' => '=dateadd({=System:Now}, "-' . $activityDays . 'd")',
				],
				'AND',
			];
		}

		if (\CBPHelper::getBool($properties[self::PARAM_ONLY_OVERDUE] ?? 'N'))
		{
			$items[] = [
				[
					'object' => 'Document',
					'field' => 'DEADLINE',
					'operator' => '<=',
					'value' => '{=System:Now}',
				],
				'AND',
			];
		}

		return ['items' => $items];
	}

	/**
	 * @return array<string, string>
	 */
	private static function getSelectedFieldsOptions(): array
	{
		return [
			self::SELECT_FIELD_DESCRIPTION => Loc::getMessage('TASKS_GET_INFO_SELECT_FIELD_DESCRIPTION'),
			self::SELECT_FIELD_STATUS => Loc::getMessage('TASKS_GET_INFO_SELECT_FIELD_STATUS'),
			self::SELECT_FIELD_DEADLINE => Loc::getMessage('TASKS_GET_INFO_SELECT_FIELD_DEADLINE'),
			self::SELECT_FIELD_ORIGINATOR => Loc::getMessage('TASKS_GET_INFO_SELECT_FIELD_ORIGINATOR'),
			self::SELECT_FIELD_RESPONSIBLE => Loc::getMessage('TASKS_GET_INFO_SELECT_FIELD_RESPONSIBLE'),
			self::SELECT_FIELD_ACCOMPLICE => Loc::getMessage('TASKS_GET_INFO_SELECT_FIELD_ACCOMPLICE'),
			self::SELECT_FIELD_AUDITOR => Loc::getMessage('TASKS_GET_INFO_SELECT_FIELD_AUDITOR'),
			self::SELECT_FIELD_CHECKLISTS => Loc::getMessage('TASKS_GET_INFO_SELECT_FIELD_CHECKLISTS'),
			self::SELECT_FIELD_RESULTS => Loc::getMessage('TASKS_GET_INFO_SELECT_FIELD_RESULTS'),
			self::SELECT_FIELD_CHAT => Loc::getMessage('TASKS_GET_INFO_SELECT_FIELD_CHAT'),
			self::SELECT_FIELD_ACTIVITY_DATE => Loc::getMessage('TASKS_GET_INFO_SELECT_FIELD_ACTIVITY_DATE'),
			self::SELECT_FIELD_IS_OVERDUE => Loc::getMessage('TASKS_GET_INFO_SELECT_FIELD_IS_OVERDUE'),
			self::SELECT_FIELD_URL => Loc::getMessage('TASKS_GET_INFO_SELECT_FIELD_URL'),
			self::SELECT_FIELD_PRIORITY => Loc::getMessage('TASKS_GET_INFO_SELECT_FIELD_PRIORITY'),
			self::SELECT_FIELD_PROJECT => Loc::getMessage('TASKS_GET_INFO_SELECT_FIELD_PROJECT'),
			self::SELECT_FIELD_DAYS_WITHOUT_UPDATES => Loc::getMessage('TASKS_GET_INFO_SELECT_FIELD_DAYS_WITHOUT_UPDATES'),
			self::SELECT_FIELD_DAYS_ON_CONTROL => Loc::getMessage('TASKS_GET_INFO_SELECT_FIELD_DAYS_ON_CONTROL'),
		];
	}

	private static function getPropertyMapName(string $propertyId): ?string
	{
		return self::getPropertiesDialogMap()[$propertyId]['Name'] ?? null;
	}

	private function getTargetUserId(): ?int
	{
		return CBPHelper::extractFirstUser($this->{self::PARAM_USER_ID}, $this->getDocumentId());
	}

	private function getTargetProjectId(): ?int
	{
		$projectId = (int)$this->{self::PARAM_PROJECT_ID};

		return $projectId > 0 ? $projectId : null;
	}

	private function setDefaultValuesToNullProperties(): void
	{
		foreach (self::getPropertiesDialogMap() as $propertyId => $propertyFields)
		{
			$defaultValue = $propertyFields['Default'] ?? null;
			if ($defaultValue === null)
			{
				continue;
			}

			$this->{$propertyId} ??= $defaultValue;
		}
	}

	private function filterIncorrectSelectTypeProperties(): void
	{
		foreach (self::getPropertiesDialogMap() as $propertyId => $propertyFields)
		{
			$type = $propertyFields['Type'] ?? null;
			$options = $propertyFields['Options'] ?? null;
			$value = $this->{$propertyId};
			if ($type !== FieldType::SELECT || empty($options) || !is_array($value) || !is_array($options))
			{
				continue;
			}

			$this->{$propertyId} = array_intersect(array_keys($options), $value);
		}
	}

	private function getIncorrectPropertyValueMessage(string $propertyId): ?string
	{
		return Loc::getMessage(
			'TASKS_GET_INFO_FIELD_ERROR_INVALID_VALUE',
			['#PROPERTY_NAME#' => $this->getPropertyMapName($propertyId)],
		);
	}

	private function getIncorrectPropertyError(string $propertyId): Error
	{
		return new Error($this->getIncorrectPropertyValueMessage($propertyId) ?? '');
	}

	private static function getIntPropertyMin(string $propertyId): ?int
	{
		return match ($propertyId)
		{
			self::PARAM_TASK_LIMIT => self::TASKS_LIMIT_MIN,
			self::PARAM_MESSAGE_COUNT_LIMIT => self::MESSAGE_COUNT_MIN,
			self::PARAM_MESSAGES_DAYS => self::MESSAGES_DAYS_MIN,
			default => null,
		};
	}

	private static function getIntPropertyMax(string $propertyId): ?int
	{
		return match ($propertyId)
		{
			self::PARAM_TASK_LIMIT => self::TASKS_LIMIT_MAX,
			self::PARAM_MESSAGE_COUNT_LIMIT => self::MAX_MESSAGE_COUNT_LIMIT_MAX,
			self::PARAM_MESSAGES_DAYS => self::MESSAGES_DAYS_MAX,
			default => null,
		};
	}

	private static function validateIntRangeValue(string $propertyId, mixed $value): ?Error
	{
		if (!is_numeric($value))
		{
			return null;
		}

		$min = self::getIntPropertyMin($propertyId);
		$max = self::getIntPropertyMax($propertyId);
		$name = self::getPropertyMapName($propertyId);
		if ($min === null || $max === null || $name === null)
		{
			return null;
		}

		$value = (int)$value;
		if ($value >= $min && $value <= $max)
		{
			return null;
		}

		$message = Loc::getMessage('TASKS_GET_INFO_FIELD_ERROR_INVALID_VALUE_IN_RANGE', [
			'#PROPERTY_NAME#' => $name,
			'#MIN_VALUE#' => $min,
			'#MAX_VALUE#' => $max,
		]);

		return new Error($message ?? '');
	}

	private function checkIntRuntimePropertyValue(string $propertyId): ?Error
	{
		$value = $this->{$propertyId};
		if ($value === null)
		{
			$isRequired = (bool)(self::getPropertiesDialogMap()[$propertyId]['Required'] ?? false);

			return $isRequired ? $this->getIncorrectPropertyError($propertyId) : null;
		}

		if (!is_numeric($value))
		{
			return $this->getIncorrectPropertyError($propertyId);
		}

		return self::validateIntRangeValue($propertyId, (int)$value);
	}

	private function checkSelectRuntimePropertyValue(string $propertyId, bool $isRequired): ?Error
	{
		$value = $this->{$propertyId};
		if (!is_array($value) || ($isRequired && empty($value)))
		{
			return $this->getIncorrectPropertyError($propertyId);
		}

		return null;
	}

	private function getTaskQuerySelectFields(): array
	{
		$select = [
			'ID',
			'TITLE',
		];

		$userSelectedFields = (array)$this->{self::PARAM_TASK_SELECT_FIELD};
		if (in_array(self::SELECT_FIELD_DESCRIPTION, $userSelectedFields, true))
		{
			$select[] = 'DESCRIPTION';
		}

		if (
			in_array(self::SELECT_FIELD_STATUS, $userSelectedFields, true)
			|| in_array(self::SELECT_FIELD_DAYS_ON_CONTROL, $userSelectedFields, true)
		)
		{
			$select[] = 'STATUS';
		}

		if (in_array(self::SELECT_FIELD_DAYS_ON_CONTROL, $userSelectedFields, true))
		{
			$select[] = 'STATUS_CHANGED_DATE';
		}

		if (in_array(self::SELECT_FIELD_RESPONSIBLE, $userSelectedFields, true))
		{
			$select[] = 'RESPONSIBLE_ID';
		}

		if (
			in_array(self::SELECT_FIELD_DEADLINE, $userSelectedFields, true)
			|| in_array(self::SELECT_FIELD_IS_OVERDUE, $userSelectedFields, true)
		)
		{
			$select[] = 'DEADLINE';
		}

		if (in_array(self::SELECT_FIELD_ORIGINATOR, $userSelectedFields, true))
		{
			$select[] = 'CREATED_BY';
		}

		if (
			in_array(self::SELECT_FIELD_ACTIVITY_DATE, $userSelectedFields, true)
			|| in_array(self::SELECT_FIELD_DAYS_WITHOUT_UPDATES, $userSelectedFields, true)
		)
		{
			$select[] = 'ACTIVITY_DATE';
		}

		if (
			in_array(self::SELECT_FIELD_CHAT, $userSelectedFields, true)
			&& !empty($this->{self::PARAM_MESSAGE_COUNT_LIMIT})
		)
		{
			$select['CHAT_ID'] = 'CHAT_TASK.CHAT_ID';
		}

		if (in_array(self::SELECT_FIELD_PRIORITY, $userSelectedFields, true))
		{
			$select[] = 'PRIORITY';
		}

		if (in_array(self::SELECT_FIELD_PROJECT, $userSelectedFields, true))
		{
			$select['PROJECT_NAME'] = 'GROUP.NAME';
		}

		return $select;
	}
}
