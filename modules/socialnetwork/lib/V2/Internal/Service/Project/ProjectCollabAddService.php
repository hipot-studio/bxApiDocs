<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use Bitrix\Socialnetwork\Collab\Control\CollabResult;
use Bitrix\Socialnetwork\Collab\Control\CollabService;
use Bitrix\Socialnetwork\Collab\Control\Command\CollabAddCommand;
use Bitrix\Socialnetwork\Control\Command\AddCommand;
use Bitrix\Socialnetwork\Control\Operation\AddOperation;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Project;

class ProjectCollabAddService extends CollabService
{
	private ?Project $project = null;

	public function addProject(CollabAddCommand $command, Project $project): CollabResult
	{
		$this->project = $project;

		try
		{
			/** @var CollabResult $result */
			$result = parent::add($command);

			return $result;
		}
		finally
		{
			$this->project = null;
		}
	}

	protected function createAddOperation(AddCommand $command): AddOperation
	{
		if ($this->project === null)
		{
			return parent::createAddOperation($command);
		}

		return new ProjectCollabAddOperation(
			command: $command,
			project: $this->project,
		);
	}
}
