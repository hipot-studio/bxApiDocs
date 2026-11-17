<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Replication\Template;

use Bitrix\Tasks\Internals\Task\Template\TemplateObject;
use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\Replication\Repository\TemplateRepository;
use Bitrix\Tasks\Replication\Template\Repetition\Parameter\ReplicateParameter;
use Bitrix\Tasks\Replication\Template\Time\Enum\RepeatType;
use Bitrix\Tasks\Util\User;

final class AfterCompletionTransitionService
{
	public function getTailTaskId(TemplateObject $template): int
	{
		$row = TaskTable::getList([
			'select' => ['ID'],
			'filter' => ['=FORKED_BY_TEMPLATE_ID' => (int)$template->getId()],
			'order' => ['CREATED_DATE' => 'DESC', 'ID' => 'DESC'],
			'limit' => 1,
		])->fetch();

		$taskId = (int)($row['ID'] ?? 0);

		return $taskId > 0 ? $taskId : (int)$template->getTaskId();
	}

	public function isReady(TemplateObject $template, int $predecessorTaskId): bool
	{
		$tailTaskId = $this->getTailTaskId($template);
		if ($predecessorTaskId <= 0)
		{
			return $tailTaskId === 0 && (int)$template->getTparamReplicationCount() === 0;
		}

		if ($tailTaskId !== $predecessorTaskId)
		{
			return false;
		}

		$row = TaskTable::getList([
			'select' => ['STATUS'],
			'filter' => ['=ID' => $predecessorTaskId],
			'limit' => 1,
		])->fetch();

		return (int)($row['STATUS'] ?? 0) === Status::COMPLETED;
	}

	public function isChainFinished(TemplateObject $template, int $runAtTs): bool
	{
		$parameter = new ReplicateParameter(TemplateRepository::getInstance((int)$template->getId()));
		$repeatTill = $parameter->get('REPEAT_TILL');

		if (!$repeatTill || $repeatTill === RepeatType::ENDLESS)
		{
			return false;
		}

		if ($repeatTill === RepeatType::DATE)
		{
			$endDate = $parameter->get('END_DATE');
			if (!$endDate)
			{
				return false;
			}

			$timeZoneOffset = $parameter->get('TIMEZONE_OFFSET');
			$creatorTimeZoneOffset = (int)(
				$timeZoneOffset ?? User::getTimeZoneOffset((int)$template->getCreatedBy())
			);
			$endTs = (int)MakeTimeStamp($endDate) - $creatorTimeZoneOffset;

			return $endTs > 0 && $runAtTs > $endTs;
		}

		if ($repeatTill === RepeatType::TIMES)
		{
			$times = (int)$parameter->get('TIMES');

			return (int)$template->getTparamReplicationCount() >= $times;
		}

		return false;
	}

	public function taskExists(int $taskId): bool
	{
		if ($taskId <= 0)
		{
			return false;
		}

		return TaskTable::getCount(['=ID' => $taskId]) > 0;
	}

	public function isForkedTask(int $templateId, int $taskId): bool
	{
		if ($templateId <= 0 || $taskId <= 0)
		{
			return false;
		}

		return TaskTable::getCount([
			'=ID' => $taskId,
			'=FORKED_BY_TEMPLATE_ID' => $templateId,
		]) > 0;
	}
}
