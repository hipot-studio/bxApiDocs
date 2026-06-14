<?php

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
use Bitrix\Tasks\UI\Task\Status;
use Bitrix\Main\Web\Json;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Tasks\V2\Internal\DI\Container;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPTasksGetInfoActivity extends BaseActivity implements IBPConfigurableActivity
{
	private const MAX_MESSAGE_COUNT_LIMIT_MAX = 500;
	private const MESSAGE_COUNT_DEFAULT = 50;
	private const MESSAGE_COUNT_MIN = 0;
	private const TASKS_LIMIT_MAX = 500;
	private const TASKS_LIMIT_DEFAULT = 50;
	private const TASKS_LIMIT_MIN = 1;
	private const USER_NAME_SYSTEM = 'System';
	private const TASK_ACTIVITY_DAYS_DEFAULT = 7;
	private const TASK_ACTIVITY_DAYS_MAX = 365;
	private const TASK_ACTIVITY_DAYS_MIN = 1;
	private const MESSAGES_DAYS_DEFAULT = 7;
	private const MESSAGES_DAYS_MAX = 365;
	private const MESSAGES_DAYS_MIN = 0;
	private const PARAM_USER_ID = 'PARAM_USER_ID';
	private const PARAM_MESSAGE_COUNT_LIMIT = 'MESSAGE_COUNT_LIMIT';
	private const PARAM_TASK_LIMIT = 'TaskLimit';
	private const PARAM_TASK_ACTIVITY_DAYS = 'TaskActivityDays';
	private const PARAM_MESSAGES_DAYS = 'MessagesDays';
	private const PARAM_USER_TASK_ROLE = 'UserTaskRole';
	private const PARAM_TASK_SELECT_FIELD = 'TaskSelectField';
	private const RETURN_PARAM_TASKS_INFO_JSON = 'TASKS_INFO_JSON';
	private const RETURN_PARAM_COUNTER_TASKS_INFO = 'COUNTER_TASKS_INFO';
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

	protected static $requiredModules = [
		'tasks',
		'im',
	];

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			self::PARAM_USER_ID => null,
			self::PARAM_MESSAGE_COUNT_LIMIT => null,
			self::PARAM_MESSAGES_DAYS => null,
			self::PARAM_TASK_ACTIVITY_DAYS => null,
			self::PARAM_TASK_LIMIT => null,
			self::PARAM_USER_TASK_ROLE => null,
			self::PARAM_TASK_SELECT_FIELD => null,
			self::RETURN_PARAM_TASKS_INFO_JSON => null,
			self::RETURN_PARAM_COUNTER_TASKS_INFO => null,
		];

		$this->setPropertiesTypes([
			self::RETURN_PARAM_TASKS_INFO_JSON => [
				'Type' => FieldType::JSON,
			],
			self::RETURN_PARAM_COUNTER_TASKS_INFO => [
				'Type' => FieldType::INT,
			],
		]);
	}

	protected function reInitialize(): void
	{
		parent::reInitialize();

		$this->{self::RETURN_PARAM_TASKS_INFO_JSON} = null;
		$this->{self::RETURN_PARAM_COUNTER_TASKS_INFO} = null;
	}

	protected function internalExecute(): ErrorCollection
	{
		$errors = new ErrorCollection();
		$userId = $this->getTargetUserId();

		try
		{
			[$tasks, $userIdList, $chatIdList] = $this->getTasks((int)$userId);
			$formattedTasks = $this->prepareTasks($tasks, $userIdList, $chatIdList);
			$this->{self::RETURN_PARAM_TASKS_INFO_JSON} = Json::encode(
				$formattedTasks,
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
			);
			$this->{self::RETURN_PARAM_COUNTER_TASKS_INFO} = count($tasks);
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
				'Required' => true,
			],
			self::PARAM_MESSAGE_COUNT_LIMIT => [
				'Name' => Loc::getMessage('TASKS_GET_INFO_FIELD_MESSAGE_COUNT_LIMIT'),
				'FieldName' => self::PARAM_MESSAGE_COUNT_LIMIT,
				'Type' => FieldType::INT,
				'Default' => self::MESSAGE_COUNT_DEFAULT,
			],
			self::PARAM_TASK_LIMIT => [
				'Name' => Loc::getMessage('TASKS_GET_INFO_FIELD_TASK_LIMIT'),
				'FieldName' => self::PARAM_TASK_LIMIT,
				'Type' => FieldType::INT,
				'Default' => self::TASKS_LIMIT_DEFAULT,
			],
			self::PARAM_TASK_ACTIVITY_DAYS => [
				'Name' => Loc::getMessage('TASKS_GET_INFO_FIELD_TASK_ACTIVITY_DAYS'),
				'FieldName' => self::PARAM_TASK_ACTIVITY_DAYS,
				'Type' => FieldType::INT,
				'Default' => self::TASK_ACTIVITY_DAYS_DEFAULT,
			],
			self::PARAM_MESSAGES_DAYS => [
				'Name' => Loc::getMessage('TASKS_GET_INFO_FIELD_MESSAGE_DAYS'),
				'FieldName' => self::PARAM_MESSAGES_DAYS,
				'Type' => FieldType::INT,
				'Default' => self::MESSAGES_DAYS_DEFAULT,
			],
			self::PARAM_USER_TASK_ROLE => [
				'Name' => Loc::getMessage('TASKS_GET_INFO_FIELD_USER_TASK_ROLE'),
				'FieldName' => self::PARAM_USER_TASK_ROLE,
				'Type' => FieldType::SELECT,
				'Default' => array_keys(self::getTaskRoles()),
				'Options' => self::getTaskRoles(),
				'Required' => true,
				'Multiple' => true,
			],
			self::PARAM_TASK_SELECT_FIELD  => [
				'Name' => Loc::getMessage('TASKS_GET_INFO_FIELD_TASK_SELECT_FIELD'),
				'FieldName' => self::PARAM_TASK_SELECT_FIELD,
				'Type' => FieldType::SELECT,
				'Default' => array_keys(self::getSelectedFieldsOptions()),
				'Options' => self::getSelectedFieldsOptions(),
				'Multiple' => true,
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
		if (empty($this->getTargetUserId()))
		{
			$errorCollection->setError($this->getIncorrectPropertyError(self::PARAM_USER_ID));
		}

		foreach (self::getPropertiesDialogMap() as $propertyId => $propertyFields)
		{
			$type = $propertyFields['Type'] ?? null;
			$isRequired = (bool)($propertyFields['Required'] ?? false);
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

	public static function validateProperties($arTestProperties = [], CBPWorkflowTemplateUser $user = null): array
	{
		$errors = [];
		foreach (self::getPropertiesMap([]) as $id => $property)
		{
			$value = $arTestProperties[$id] ?? null;
			$error = match ($property['Type'] ?? null)
			{
				FieldType::INT => self::validateIntRangeValue($id, $value),
				default => null,
			};

			if ($error)
			{
				$errors[] = $error->jsonSerialize();
			}
		}

		return array_merge($errors, parent::ValidateProperties($arTestProperties, $user));
	}

	private function getTasks(int $userId): array
	{
		$activityDays = (int)$this->{self::PARAM_TASK_ACTIVITY_DAYS};

		$tasksResult = TaskTable::query()
			->setSelect($this->getTaskQuerySelectFields())
			->setFilter([
				'>ACTIVITY_DATE' => (new DateTime())->add("-$activityDays days"),
				'=MEMBERS.USER_ID' => $userId,
				'@MEMBERS.TYPE' => (array)$this->{self::PARAM_USER_TASK_ROLE},
			])
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
			$status = $row['STATUS'] ?? null;
			$status = Status::getList()[$status] ?? $status;
			$tasks[$row['ID']] = [
				'TITLE' => $row['TITLE'],
				'DESCRIPTION' => $row['DESCRIPTION'] ?? null,
				'STATUS' => $status,
				'RESPONSIBLE' => $row['RESPONSIBLE_ID'] ?? null,
				'DEADLINE' => $row['DEADLINE'] ?? null,
				'CREATOR' => $row['CREATED_BY'] ?? null,
				'ACTIVITY_DATE' => $row['ACTIVITY_DATE'] ?? null,
				'CHAT_ID' => $row['CHAT_ID'] ?? null,
				'PRIORITY' => $row['PRIORITY'] ?? null,
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
				]
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
		$showDeadline = in_array(self::SELECT_FIELD_DEADLINE, $userSelectedFields, true);
		$showIsOverdue = in_array(self::SELECT_FIELD_IS_OVERDUE, $userSelectedFields, true);
		$showUrl = in_array(self::SELECT_FIELD_URL, $userSelectedFields, true);
		$linkService = Container::getInstance()->getLinkService();
		$userId = (int)$this->getTargetUserId();

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

			if (!empty($task['STATUS']))
			{
				$formattedTask['STATUS'] = $task['STATUS'];
			}

			if (isset($task['ACTIVITY_DATE']) && $task['ACTIVITY_DATE'] instanceof DateTime)
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

			if ($showUrl)
			{
				$taskEntity = new Bitrix\Tasks\V2\Internal\Entity\Task(id: (int)$taskId);
				$formattedTask['URL'] = '/' . ltrim($linkService->get($taskEntity, $userId), '/');
			}

			if (isset($task['PRIORITY']))
			{
				$formattedTask['PRIORITY'] = (int)$task['PRIORITY'];
			}

			$formattedTasks[] = $formattedTask;
		}

		return $formattedTasks;
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

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return self::getPropertiesMap([]);
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
			self::PARAM_TASK_ACTIVITY_DAYS => self::TASK_ACTIVITY_DAYS_MIN,
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
			self::PARAM_TASK_ACTIVITY_DAYS => self::TASK_ACTIVITY_DAYS_MAX,
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

		if (in_array(self::SELECT_FIELD_STATUS, $userSelectedFields, true))
		{
			$select[] = 'STATUS';
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

		if (in_array(self::SELECT_FIELD_ACTIVITY_DATE, $userSelectedFields, true))
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

		return $select;
	}
}
