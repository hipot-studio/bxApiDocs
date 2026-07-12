<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Command\Project;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Public\Provider\ProjectProvider;
use Exception;
use Throwable;

class SetSummaryAgentOptionHandler
{
	public function __invoke(SetSummaryAgentOptionCommand $command): Result
	{
		$result = new Result();

		$container = Container::getInstance();

		try
		{
			$projectProvider = $container->get(ProjectProvider::class);

			if (!$projectProvider->isProject($command->projectId))
			{
				throw new Exception('Project not found');
			}

			$container
				->getCollabOptionRepository()
				->setProjectSummaryAgentOption(
					collabId: $command->projectId,
					value: $command->value,
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
