<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Scrum;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;

class DeleteScrumCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $scrumId,
		#[PositiveNumber]
		public readonly int $userId,
	)
	{
	}

	protected function execute(): Result
	{
		$handler = Container::getInstance()->get(DeleteScrumHandler::class);

		return $handler($this);
	}
}
