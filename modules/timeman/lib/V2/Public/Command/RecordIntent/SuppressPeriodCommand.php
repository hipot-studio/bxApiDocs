<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\RecordIntent;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Timeman\V2\Internal\DI\Container;
use Bitrix\Timeman\V2\Public\Dto\RecordIntent\RecordIntentSchedule;

class SuppressPeriodCommand extends AbstractCommand
{
	/**
	 * @param int $userId
	 * @param string $periodKey
	 * @param RecordIntentSchedule|null $schedule the schedule the caller has already resolved in this
	 *   request; passing it spares the handler a second full resolve. Omitted, the handler resolves
	 *   the schedule itself.
	 */
	public function __construct(
		public readonly int $userId,
		public readonly string $periodKey,
		public readonly ?RecordIntentSchedule $schedule = null,
	)
	{
	}

	protected function execute(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(SuppressPeriodCommandHandler::class);

		try
		{
			return $handler($this);
		}
		catch (\Exception $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}
	}
}
