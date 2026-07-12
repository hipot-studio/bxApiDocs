<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Helper\Workgroup;
use Throwable;

class ArchiveProjectHandler
{
	public function __invoke(ArchiveProjectCommand $command): Result
	{
		$result = new Result();

		try
		{
			Workgroup::setArchive([
				'groupId' => $command->projectId,
				'archive' => $command->archive,
			]);
		}
		catch (Throwable $e)
		{
			$result->addError(new Error($e->getMessage()));
		}

		return $result;
	}
}
