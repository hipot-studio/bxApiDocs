<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Helper;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\Task\Status;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TaskStatusMapper;
use LogicException;

class TaskStatusesNotSuitableForAnalysisHelper
{
	private const STATUSES = [Status::Completed, Status::Deferred, Status::Declined];

	/**
	 * @return Status[]
	 */
	public static function getList(): array
	{
		return self::STATUSES;
	}

	public static function getRawList(): array
	{
		$mapper = Container::getInstance()->get(TaskStatusMapper::class);

		$result = [];
		foreach (self::getList() as $status)
		{
			$statusRaw = $mapper->mapFromEnum($status);
			if ($statusRaw === null)
			{
				throw new LogicException("No legacy mapping for status {$status->value}");
			}

			$result[] = $statusRaw;
		}

		return $result;
	}

	public static function getListForDescription(): string
	{
		return implode(', ', array_column(self::getList(), 'value'));
	}
}
