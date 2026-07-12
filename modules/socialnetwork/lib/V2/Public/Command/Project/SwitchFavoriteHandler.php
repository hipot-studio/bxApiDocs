<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Throwable;

class SwitchFavoriteHandler
{
	public function __invoke(SwitchFavoriteCommand $command): Result
	{
		$result = new Result();

		try
		{
			$favorited = Container::getInstance()
				->getFavoritesService()
				->switchFavorite(
					groupId: $command->groupId,
				userId: $command->userId,
			);

			$result->setData(['favorited' => $favorited]);

			Container::getInstance()
				->getProjectRealtimePublisher()
				->publishFavoriteChanged(
					groupId: $command->groupId,
					userId: $command->userId,
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
