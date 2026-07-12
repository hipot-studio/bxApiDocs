<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Controller\Project;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\EnablePrefilters;
use Bitrix\Socialnetwork\Collab\Controller\Filter\IntranetUserFilter;
use Bitrix\Socialnetwork\V2\Infrastructure\Controller\BaseController;
use Bitrix\Socialnetwork\V2\Infrastructure\Controller\Trait\ProjectAutoWireTrait;
use Bitrix\Socialnetwork\V2\Internal\Access\Project\Permission;
use Bitrix\Socialnetwork\V2\Internal\Entity;
use Bitrix\Socialnetwork\V2\Public\Command\Project\Member\AddMembersCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\Member\DeleteMembersCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\Member\InviteMembersCommand;
use Bitrix\Socialnetwork\V2\Public\Dto;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\ProjectResponse;
use Bitrix\Socialnetwork\V2\Public\Provider\ProjectProvider;

class Member extends BaseController
{
	use ProjectAutoWireTrait;

	public function getAutoWiredParameters(): array
	{
		return $this->getProjectAutoWiredParameters();
	}

	/**
	 * @ajaxAction socialnetwork.V2.Project.Member.add
	 */
	public function addAction(
		#[Permission\Invite]
		Dto\Project\Project $project,
		ProjectProvider $projectProvider,
	): ?ProjectResponse
	{
		$projectId = (int)$project->getId();

		$result = (new AddMembersCommand(
			projectId: $projectId,
			members: $project->members,
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
	 * @ajaxAction socialnetwork.V2.Project.Member.invite
	 */
	public function inviteAction(
		#[Permission\Invite]
		Dto\Project\Project $project,
		ProjectProvider $projectProvider,
	): ?ProjectResponse
	{
		$projectId = (int)$project->getId();

		$result = (new InviteMembersCommand(
			projectId: $projectId,
			members: $project->members,
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
	 * @ajaxAction socialnetwork.V2.Project.Member.delete
	 */
	#[EnablePrefilters([
		new IntranetUserFilter(),
	])]
	public function deleteAction(
		#[Permission\Exclude]
		Dto\Project\Project $project,
		ProjectProvider $projectProvider,
	): ?ProjectResponse
	{
		$projectId = (int)$project->getId();

		$result = (new DeleteMembersCommand(
			projectId: $projectId,
			members: $project->members,
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
	 * @ajaxAction socialnetwork.V2.Project.Member.leave
	 */
	#[EnablePrefilters([
		new IntranetUserFilter(),
	])]
	public function leaveAction(
		#[Permission\Leave]
		Dto\Project\Project $project,
		ProjectProvider $projectProvider,
	): ?ProjectResponse
	{
		$projectId = (int)$project->getId();

		$members = new Dto\Project\MemberCollection(
			new Dto\Project\Member(
				id: $this->userId,
				type: Entity\Project\Member\MemberEntityType::User,
			),
		);

		$result = (new DeleteMembersCommand(
			projectId: $projectId,
			members: $members,
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
