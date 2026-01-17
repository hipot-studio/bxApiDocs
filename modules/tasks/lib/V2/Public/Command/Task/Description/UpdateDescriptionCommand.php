<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Description;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Bitrix\Tasks\V2\Internal\Entity;

class UpdateDescriptionCommand extends AbstractCommand
{
	public function __construct(
		public readonly Entity\Task $task,
		public readonly bool $forceUpdate,
		#[PositiveNumber]
		public readonly int $userId,
		public readonly bool $useConsistency = false,
	)
	{

	}

	protected function executeInternal(): Result
	{
		$handler = Container::getInstance()->get(UpdateDescriptionHandler::class);

		return $handler($this);
	}
}
