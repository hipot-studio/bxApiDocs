<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Public\Grid\PinMode;

class SwitchPinCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $groupId,
		#[PositiveNumber]
		public readonly int $userId,
		public readonly PinMode $mode = PinMode::Common,
	)
	{
	}

	protected function execute(): Result
	{
		$handler = Container::getInstance()->getSwitchPinHandler();

		return $handler($this);
	}
}
