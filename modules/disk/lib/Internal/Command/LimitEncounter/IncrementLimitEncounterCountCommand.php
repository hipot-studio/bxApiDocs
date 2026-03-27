<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Command\LimitEncounter;

use Bitrix\Disk\Internal\Enum\LimitEncounterType;
use Bitrix\Disk\Internal\Service\ItemsCountResult;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectNotFoundException;
use Throwable;

class IncrementLimitEncounterCountCommand extends AbstractCommand
{
	private IncrementLimitEncounterCountCommandHandler $handler;

	/**
	 * @param LimitEncounterType $type
	 * @param int $max
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 */
	public function __construct(
		public readonly LimitEncounterType $type,
		public readonly int $max,
	)
	{
		$this->handler = ServiceLocator::getInstance()->get(IncrementLimitEncounterCountCommandHandler::class);
	}

	protected function execute(): ItemsCountResult
	{
		try
		{
			return ($this->handler)($this);
		}
		catch (Throwable $e)
		{
			$result = new ItemsCountResult();
			$result->addError(new Error($e->getMessage(), $e->getCode()));

			return $result;
		}
	}
}
