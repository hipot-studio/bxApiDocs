<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\Admin;

use Bitrix\Intranet\Util;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class RemoveRightsHandler
{
	public function __invoke(RemoveRightsCommand $command): Result
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
