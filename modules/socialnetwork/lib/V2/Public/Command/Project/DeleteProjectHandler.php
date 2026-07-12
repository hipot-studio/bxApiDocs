<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Collab\Control\CollabService;
use Bitrix\Socialnetwork\Collab\Control\Command\CollabDeleteCommand;
use Exception;

class DeleteProjectHandler
{
	public function __construct(
		private readonly CollabService $collabService,
	)
	{
	}

	public function __invoke(DeleteProjectCommand $command): Result
	{
		$result = new Result();

		try
		{
			$deleteCommand = (new CollabDeleteCommand())
				->setId($command->projectId)
				->setInitiatorId($command->userId)
			;

			$deleteResult = $this->collabService->delete($deleteCommand);

			$result->addErrors($deleteResult->getErrors());
		}
		catch (Exception $e)
		{
			$result->addError(new Error($e->getMessage()));
		}

		return $result;
	}
}
