<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity;
use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Result;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Integration\Bizproc\Document\Task;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\Slider\Path\TaskPathMaker;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPTasksCreateTrigger extends Activity\BaseTrigger
{
	private const RETURN_PARAM_TASK_ID = 'TASK_ID';
	private const RETURN_PARAM_TASK_TITLE = 'TASK_TITLE';
	private const RETURN_PARAM_TASK_CREATED_DATE = 'TASK_CREATED_DATE';
	private const RETURN_PARAM_TASK_RESPONSIBLE = 'TASK_RESPONSIBLE';
	private const RETURN_PARAM_TASK_URL = 'TASK_URL';
	private const RETURN_PARAM_RETURN_DOCUMENT = 'ReturnDocument';
	private const FIELD_TASK_ID = 'ID';
	private const FIELD_TASK_TITLE = 'TITLE';
	private const FIELD_TASK_CREATED_DATE = 'CREATED_DATE';
	private const FIELD_TASK_RESPONSIBLE_ID = 'RESPONSIBLE_ID';
	private const FIELD_TASK_CREATED_BY = 'CREATED_BY';

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			self::RETURN_PARAM_TASK_ID => null,
			self::RETURN_PARAM_TASK_TITLE => null,
			self::RETURN_PARAM_TASK_CREATED_DATE => null,
			self::RETURN_PARAM_TASK_RESPONSIBLE => null,
			self::RETURN_PARAM_TASK_URL => null,
			self::RETURN_PARAM_RETURN_DOCUMENT => null,
		];

		$this->setPropertiesTypes([
			self::RETURN_PARAM_TASK_ID => [
				'Type' => FieldType::INT,
			],
			self::RETURN_PARAM_TASK_TITLE => [
				'Type' => FieldType::STRING,
			],
			self::RETURN_PARAM_TASK_CREATED_DATE => [
				'Type' => FieldType::DATETIME,
			],
			self::RETURN_PARAM_TASK_RESPONSIBLE => [
				'Type' => FieldType::USER,
			],
			self::RETURN_PARAM_TASK_URL => [
				'Type' => FieldType::STRING,
			],
			self::RETURN_PARAM_RETURN_DOCUMENT => [
				'Type' => FieldType::DOCUMENT,
			],
		]);
	}

	public function execute(): int
	{
		if (!Loader::includeModule('tasks'))
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$context = $this->getEventData();

		$taskId = (int)($context[self::FIELD_TASK_ID] ?? 0);
		$taskTitle = (string)($context[self::FIELD_TASK_TITLE] ?? '');
		$taskCreatedDate = $this->normalizeCreatedDate($context[self::FIELD_TASK_CREATED_DATE] ?? null);
		if ($taskCreatedDate === null)
		{
			$taskCreatedDate = $this->fetchCreatedDate($taskId);
		}
		$taskResponsibleId = (int)($context[self::FIELD_TASK_RESPONSIBLE_ID] ?? 0);
		$taskCreatedBy = (int)($context[self::FIELD_TASK_CREATED_BY] ?? 0);

		if (!empty($taskId))
		{
			$this->{self::RETURN_PARAM_TASK_ID} = $taskId;
		}

		if (!empty($taskTitle))
		{
			$this->{self::RETURN_PARAM_TASK_TITLE} = $taskTitle;
		}

		if ($taskCreatedDate !== null)
		{
			$this->{self::RETURN_PARAM_TASK_CREATED_DATE} = $taskCreatedDate;
		}

		if (!empty($taskResponsibleId))
		{
			$this->{self::RETURN_PARAM_TASK_RESPONSIBLE} = 'user_' . $taskResponsibleId;
		}

		$taskOwnerId = $taskResponsibleId > 0 ? $taskResponsibleId : $taskCreatedBy;
		$this->{self::RETURN_PARAM_TASK_URL} = $this->makeTaskUrl($taskId, $taskOwnerId);

		if (!empty($taskId))
		{
			$this->{self::RETURN_PARAM_RETURN_DOCUMENT} = Task::resolveDocumentId($taskId);
		}

		return CBPActivityExecutionStatus::Closed;
	}

	public function checkApplyRules(array $rules, TriggerParameters $parameters): Result
	{
		return Result::createOk();
	}

	protected static function getModuleId(): ?string
	{
		return 'tasks';
	}

	private function fetchCreatedDate(int $taskId): ?DateTime
	{
		if ($taskId <= 0)
		{
			return null;
		}

		$row = TaskTable::getList([
			'select' => [self::FIELD_TASK_CREATED_DATE],
			'filter' => ['=ID' => $taskId],
			'limit' => 1,
		])->fetch();

		return $this->normalizeCreatedDate($row[self::FIELD_TASK_CREATED_DATE] ?? null);
	}

	private function normalizeCreatedDate($value): ?DateTime
	{
		if ($value instanceof DateTime)
		{
			return $value;
		}

		if ($value instanceof Date)
		{
			return DateTime::createFromTimestamp($value->getTimestamp());
		}

		if (is_string($value) && $value !== '')
		{
			$timestamp = MakeTimeStamp($value);
			if ($timestamp > 0)
			{
				return DateTime::createFromTimestamp($timestamp);
			}
		}

		return null;
	}

	private function makeTaskUrl(int $taskId, int $userId): ?string
	{
		if ($taskId <= 0)
		{
			return null;
		}

		$fields = [
			'task_id' => $taskId,
		];

		if ($userId > 0)
		{
			$fields['user_id'] = $userId;
		}

		$path = TaskPathMaker::getPath($fields);

		if (empty($path))
		{
			return null;
		}

		return $path;
	}
}
