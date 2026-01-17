<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Deadline\Command;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use Bitrix\Tasks\Deadline\Entity\DeadlineUserOption;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Exception;

/**
 * @method self setEntity(DeadlineUserOption $entity)
 */
class SetDefaultDeadlineCommand extends AbstractCommand
{
	public function __construct(
		#[Validatable]
		public readonly DeadlineUserOption $entity,
	)
	{
	}

	protected function execute(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(SetDefaultDeadlineHandler::class);

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

	public function toArray(): array
	{
		return [
			'entity' => $this->entity->toArray(),
		];
	}
}
