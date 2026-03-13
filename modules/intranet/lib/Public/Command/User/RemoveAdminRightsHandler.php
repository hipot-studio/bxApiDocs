<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\User;

use Bitrix\Intranet\Util;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class RemoveAdminRightsHandler
{
	public function __invoke(RemoveAdminRightsCommand $command): Result
	{
		$result = new Result();

		try
		{
			Util::removeAdminRights(
				[
					'userId' => $command->userId,
					'currentUserId' => $command->currentUserId,
				],
			);
		}
		catch (\Exception $e)
		{
			$result->addError(new Error($e->getMessage()));
		}

		return $result;
	}
}
