<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\CheckList;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Exception\CheckList\CheckListConflictException;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Exception;

class SaveCheckListCommand extends AbstractCommand
{
	public function __construct(
		public readonly Entity\Task $task,
		#[PositiveNumber]
		public readonly int $updatedBy,
		public readonly ?Entity\Task $taskBeforeUpdate = null,
		public readonly bool $useConsistency = false,
		public readonly bool $skipNotification = false,
		public readonly ?string $baseRevision = null,
		public readonly bool $fromAi = false,
		public readonly ?Entity\CheckList $pushCheckList = null,
		public readonly ?string $revision = null,
	)
	{

	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(SaveCheckListCommandHandler::class);

		try
		{
			$saveResult = $handler($this);

			return $result
				->setObject($saveResult->task)
				->setDataValues(['revision' => $saveResult->revision])
			;
		}
		catch (CheckListConflictException $e)
		{
			$result->setDataValues([
				'conflict' => true,
				'conflictCheckList' => $e->getCurrentCheckList(),
				'conflictRevision' => $e->getCurrentRevision(),
			]);

			return $result;
		}
		catch (Exception $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}
	}
}
