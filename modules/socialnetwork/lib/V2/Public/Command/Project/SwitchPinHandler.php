<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Space\Toolbar\Switcher\Option\Pin;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Throwable;

class SwitchPinHandler
{
	public function __invoke(SwitchPinCommand $command): Result
	{
		$result = new Result();

		try
		{
			$mode = Container::getInstance()->getPinModeMapper()->mapToInternal($command->mode);
			$pin = new Pin($command->userId, $command->groupId, $mode->value);
			$switchResult = $pin->switch();

			if (!$switchResult->isSuccess())
			{
				$result->addErrors($switchResult->getErrors());

				return $result;
			}

			$isPinned = $pin->isEnabled();
			$result->setData(['pinned' => $isPinned]);

			Container::getInstance()
				->getProjectRealtimePublisher()
				->publishPinChanged(
					groupId: $command->groupId,
					userId: $command->userId,
					isPinned: $isPinned,
				)
			;
		}
		catch (Throwable $e)
		{
			$result->addError(new Error($e->getMessage()));
		}

		return $result;
	}
}
