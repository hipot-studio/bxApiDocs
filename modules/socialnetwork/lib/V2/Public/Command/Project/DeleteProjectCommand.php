<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;

class DeleteProjectCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $projectId,
		#[PositiveNumber]
		public readonly int $userId,
	)
	{
	}

	protected function execute(): Result
	{
		$handler = Container::getInstance()->getDeleteProjectHandler();

		return $handler($this);
	}
}
