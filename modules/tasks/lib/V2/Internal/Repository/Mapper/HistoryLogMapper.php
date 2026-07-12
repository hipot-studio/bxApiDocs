<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Control\Log\Change;
use Bitrix\Tasks\Control\Log\Command\AddCommand;
use Bitrix\Tasks\Control\Log\TaskLog;
use Bitrix\Tasks\Control\Log\TaskLogCollection;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\Enum\HistoryFields;

class HistoryLogMapper
{
	public function __construct(
		private readonly TaskStatusMapper $statusMapper,
	)
	{
	}

	public function mapToCollection(TaskLogCollection $collection): Entity\HistoryLogCollection
	{
		$entities = [];
		foreach ($collection as $log)
		{
			$entities[] = $this->mapToEntity($log);
		}

		return new Entity\HistoryLogCollection(...$entities);
	}

	public function mapToCommand(Entity\HistoryLog $historyLog): AddCommand
	{
		return (new AddCommand())
			->setUserId($historyLog->userId)
			->setTaskId($historyLog->taskId)
			->setField($historyLog->field)
			->setChange(new Change($historyLog->fromValue, $historyLog->toValue))
			->setCreatedDate(DateTime::createFromTimestamp($historyLog->createdDateTs ?? time()));
	}

	public function mapToEntity(TaskLog $log): Entity\HistoryLog
	{
		return new Entity\HistoryLog(
			id: $log->getId(),
			createdDateTs: $log->getCreatedDate()->getTimestamp(),
			userId: $log->getUserId(),
			taskId: $log->getTaskId(),
			field: $log->getField(),
			fromValue: $log->getChange()?->getFromValue(),
			toValue: $log->getChange()?->getToValue(),
		);
	}

	public function mapStatusFieldsValues(array $historyLogs): array
	{
		foreach ($historyLogs as $key => $log)
		{
			$field = $log['FIELD'] ?? null;
			if ($field !== HistoryFields::Status->value)
			{
				continue;
			}

			$fromValue = $log['FROM_VALUE'] ?? null;
			if (is_numeric($fromValue))
			{
				$historyLogs[$key]['FROM_VALUE'] = $this->statusMapper->mapToEnum((int)$fromValue)?->value;
			}

			$toValue = $log['TO_VALUE'] ?? null;
			if (is_numeric($toValue))
			{
				$historyLogs[$key]['TO_VALUE'] = $this->statusMapper->mapToEnum((int)$toValue)?->value;
			}
		}

		return $historyLogs;
	}
}
