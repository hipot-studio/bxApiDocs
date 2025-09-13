<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Command\Structure\Node;

use Bitrix\HumanResources\Command\AbstractCommand;
use Bitrix\HumanResources\Command\Structure\Node\Handler\CreateDepartmentCommandHandler;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Result\Command\Structure\CreateNodeCommandResult;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * @extends AbstractCommand<CreateNodeCommandResult>
 */
class CreateDepartmentCommand extends AbstractCommand
{
	public function __construct(
		public readonly Node $node,
		public readonly array $userIds = [],
	)
	{
	}

	protected function validate(): bool
	{
		if ($this->node->type !== NodeEntityType::DEPARTMENT)
		{
			$this->errors[] = new Error('Node type must be department');

			return false;
		}

		return true;
	}

	protected function execute(): CreateNodeCommandResult
	{
		try
		{
			return (new CreateDepartmentCommandHandler())($this);
		}
		catch (\Exception $e)
		{
			$result = (new CreateNodeCommandResult());
			$result->addError(
				new Error(
					$e->getMessage(),
					$e->getCode(),
				),
			);

			return $result;
		}
	}
}