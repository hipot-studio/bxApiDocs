<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Scrum;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Control\Command\DeleteCommand;
use Bitrix\Socialnetwork\Control\GroupService;
use Exception;

class DeleteScrumHandler
{
	public function __construct(
		private readonly GroupService $groupService,
	)
	{
	}

	public function __invoke(DeleteScrumCommand $command): Result
	{
		$result = new Result();

		try
		{
			$deleteCommand = (new DeleteCommand())
				->setId($command->scrumId)
				->setInitiatorId($command->userId)
			;

			$deleteResult = $this->groupService->delete($deleteCommand);

			$result->addErrors($deleteResult->getErrors());
		}
		catch (Exception $e)
		{
			$result->addError(new Error($e->getMessage()));
		}

		return $result;
	}
}
