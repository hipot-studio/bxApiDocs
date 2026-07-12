<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Controller\Project;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\EnablePrefilters;
use Bitrix\Socialnetwork\Collab\Controller\Filter\IntranetUserFilter;
use Bitrix\Socialnetwork\V2\Infrastructure\Controller\BaseController;
use Bitrix\Socialnetwork\V2\Infrastructure\Controller\Trait\ProjectAutoWireTrait;
use Bitrix\Socialnetwork\V2\Internal\Access\Project\Permission;
use Bitrix\Socialnetwork\V2\Public\Command\Project\Moderator\AddModeratorsCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\Moderator\DeleteModeratorsCommand;
use Bitrix\Socialnetwork\V2\Public\Dto;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\ProjectResponse;
use Bitrix\Socialnetwork\V2\Public\Provider\ProjectProvider;

class Moderator extends BaseController
{
	use ProjectAutoWireTrait;

	public function getAutoWiredParameters(): array
	{
		return $this->getProjectAutoWiredParameters();
	}

	/**
	 * @ajaxAction socialnetwork.V2.Project.Moderator.add
	 */
	#[EnablePrefilters([
		new IntranetUserFilter(),
	])]
	public function addAction(
		#[Permission\SetModerator]
		Dto\Project\Project $project,
		ProjectProvider $projectProvider,
	): ?ProjectResponse
	{
		$projectId = (int)$project->getId();

		$result = (new AddModeratorsCommand(
			projectId: $projectId,
			moderatorMembers: $project->moderatorMembers,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $projectProvider->getById($projectId);
	}

	/**
	 * @ajaxAction socialnetwork.V2.Project.Moderator.delete
	 */
	#[EnablePrefilters([
		new IntranetUserFilter(),
	])]
	public function deleteAction(
		#[Permission\ExcludeModerator]
		Dto\Project\Project $project,
		ProjectProvider $projectProvider,
	): ?ProjectResponse
	{
		$projectId = (int)$project->getId();

		$result = (new DeleteModeratorsCommand(
			projectId: $projectId,
			moderatorMembers: $project->moderatorMembers,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $projectProvider->getById($projectId);
	}
}
