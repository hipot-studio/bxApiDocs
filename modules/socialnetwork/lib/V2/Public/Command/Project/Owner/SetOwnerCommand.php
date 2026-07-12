<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project\Owner;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;

class SetOwnerCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $projectId,
		#[PositiveNumber]
		public readonly int $ownerId,
		#[PositiveNumber]
		public readonly int $userId,
	)
	{
	}

	protected function execute(): Result
	{
		$handler = Container::getInstance()->get(SetOwnerHandler::class);

		return $handler($this);
	}
}
