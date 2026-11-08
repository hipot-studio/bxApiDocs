<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Interaction;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Internal\Service\Interaction\InteractionOrigin;
use Bitrix\Tasks\V2\Internal\Service\Ranking\EventType;
use Bitrix\Tasks\V2\Internal\Service\Ranking\RelationKind;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Exception;

/**
 * Public entry point over the {@see InteractionRecorder} for cross-module
 * callers (e.g. mobile `tasksmobile`). Web capture points call the internal
 * recorder directly.
 */
class RecordInteractionCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $userId,
		#[PositiveNumber]
		public readonly int $taskId,
		public readonly EventType $eventType,
		public readonly ?RelationKind $relationKind = null,
		public readonly InteractionOrigin $origin = InteractionOrigin::System,
	)
	{
	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(RecordInteractionHandler::class);

		try
		{
			$handler($this);

			return $result;
		}
		catch (Exception $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}
	}
}
