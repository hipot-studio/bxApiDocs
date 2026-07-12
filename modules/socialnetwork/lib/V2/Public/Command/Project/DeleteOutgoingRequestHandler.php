<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Helper;
use Throwable;

class DeleteOutgoingRequestHandler
{
	public function __invoke(DeleteOutgoingRequestCommand $command): Result
	{
		$result = new Result();

		try
		{
			Helper\Workgroup::rejectOutgoingRequest([
				'groupId' => $command->groupId,
				'userId' => $command->userId,
			]);
		}
		catch (Throwable $e)
		{
			$result->addError(new Error($e->getMessage()));
		}

		return $result;
	}
}
