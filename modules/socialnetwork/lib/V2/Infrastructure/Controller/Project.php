<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Controller;

use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Error;
use Bitrix\Socialnetwork\V2\Infrastructure\Controller\Trait\ProjectAutoWireTrait;
use Bitrix\Socialnetwork\V2\Internal\Access\Project\Permission;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Integration\Bitrix24\Service\ProjectsTrialService;
use Bitrix\Socialnetwork\V2\Public\Command\Project\AddProjectCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\ArchiveProjectCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\CopyProjectCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\DeleteIncomingRequestCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\DeleteOutgoingRequestCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\DeleteProjectCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\JoinProjectCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\SwitchFavoriteCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\SwitchPinCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\UpdateProjectCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\UpdateProjectTagsCommand;
use Bitrix\Socialnetwork\V2\Public\Dto;
use Bitrix\Socialnetwork\V2\Public\Grid\PinMode;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\ProjectResponse;
use Bitrix\Socialnetwork\V2\Public\Provider\ProjectMemberProvider;
use Bitrix\Socialnetwork\V2\Public\Provider\ProjectProvider;
use CSocNetUser;

class Project extends BaseController
{
	use ProjectAutoWireTrait;

	public function getAutoWiredParameters(): array
	{
		return [
			...$this->getProjectAutoWiredParameters(),
			new ExactParameter(
				Dto\Project\Project::class,
				'sourceProject',
				fn (string $className, int $sourceProjectId): ?Dto\Project\Project
					=> $this->getWithAccess(
						$this,
						'sourceProject',
						new Dto\Project\Project(id: $sourceProjectId),
					),
			),
			new ExactParameter(
				Dto\Project\CopyProjectOptions::class,
				'copyOptions',
				static fn (string $className, array $copyOptions): ?Dto\Project\CopyProjectOptions
					=> Dto\Project\CopyProjectOptions::mapFromArray($copyOptions),
			),
		];
	}

	/**
	 * @ajaxAction socialnetwork.V2.Project.get
	 */
	public function getAction(
		#[Permission\Read]
		Dto\Project\Project $project,
		ProjectProvider $projectProvider,
	): ?ProjectResponse
	{
		if ($project->id === null)
		{
			return null;
		}

		$projectResponse = $projectProvider->getById($project->id);
		if ($projectResponse === null)
		{
			return null;
		}

		$isCurrentUserModuleAdmin = CSocNetUser::isCurrentUserModuleAdmin();
		$availableFeatures = $projectProvider->getAvailableFeatures(
			$project->id,
			$this->userId,
			$isCurrentUserModuleAdmin,
		)->toArray();

		$projectResponse->setAvailableFeatures($availableFeatures);

		return $projectResponse;
	}

	/**
	 * @ajaxAction socialnetwork.V2.Project.add
	 */
	public function addAction(
		#[Permission\Create]
		Dto\Project\Project $project,
		ProjectProvider $projectProvider,
	): ?ProjectResponse
	{
		$result = (new AddProjectCommand(
			input: $project,
			userId: $this->userId,
			isCurrentUserModuleAdmin: CSocNetUser::isCurrentUserModuleAdmin(),
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$projectId = (int)($result->getData()['projectId'] ?? 0);
		if ($projectId === 0)
		{
			$this->addError(new Error('Failed to retrieve created project ID'));

			return null;
		}

		$this->turnOnProjectsTrialIfNeeded();

		return $projectProvider->getById($projectId);
	}

	private function turnOnProjectsTrialIfNeeded(): void
	{
		try
		{
			Container::getInstance()->get(ProjectsTrialService::class)->turnOnTrialIfNeeded();
		}
		catch (\Throwable $e)
		{
			$this->writeToLogException($e);
		}
	}

	/**
	 * @ajaxAction socialnetwork.V2.Project.update
	 */
	public function updateAction(
		#[Permission\Update]
		Dto\Project\Project $project,
		ProjectProvider $projectProvider,
	): ?ProjectResponse
	{
		$result = (new UpdateProjectCommand(
			input: $project,
			userId: $this->userId,
			isCurrentUserModuleAdmin: CSocNetUser::isCurrentUserModuleAdmin(),
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$projectId = (int)($result->getData()['projectId'] ?? 0);
		if ($projectId === 0)
		{
			$this->addError(new Error('Failed to retrieve updated project ID'));

			return null;
		}

		return $projectProvider->getById($projectId);
	}

	/**
	 * @ajaxAction socialnetwork.V2.Project.updateTags
	 */
	public function updateTagsAction(
		#[Permission\UpdateTags]
		Dto\Project\Project $project,
		array $tags = [],
	): ?array
	{
		$result = (new UpdateProjectTagsCommand(projectId: $project->id, tags: $tags))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['success' => true];
	}

	/**
	 * @ajaxAction socialnetwork.V2.Project.setArchive
	 */
	public function setArchiveAction(
		#[Permission\Update]
		Dto\Project\Project $project,
		bool $archive,
	): ?array
	{
		$result = (new ArchiveProjectCommand(
			projectId: $project->id,
			archive: $archive,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['success' => true];
	}

	/**
	 * @ajaxAction socialnetwork.V2.Project.join
	 */
	public function joinAction(
		#[Permission\Join]
		Dto\Project\Project $project,
	): ?array
	{
		$result = (new JoinProjectCommand(
			projectId: $project->id,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['success' => true];
	}

	/**
	 * @ajaxAction socialnetwork.V2.Project.deleteIncomingRequest
	 */
	public function deleteIncomingRequestAction(
		#[Permission\DeleteIncomingRequest]
		Dto\Project\Project $project,
	): ?array
	{
		$result = (new DeleteIncomingRequestCommand(
			groupId: $project->id,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['success' => true];
	}

	/**
	 * @ajaxAction socialnetwork.V2.Project.deleteOutgoingRequest
	 */
	public function deleteOutgoingRequestAction(
		#[Permission\DeleteOutgoingRequest]
		Dto\Project\Project $project,
	): ?array
	{
		$result = (new DeleteOutgoingRequestCommand(
			groupId: $project->id,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['success' => true];
	}

	/**
	 * @ajaxAction socialnetwork.V2.Project.delete
	 */
	public function deleteAction(
		#[Permission\Delete]
		Dto\Project\Project $project,
	): ?array
	{
		$result = (new DeleteProjectCommand(
			projectId: $project->id,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['success' => true];
	}

	/**
	 * @ajaxAction socialnetwork.V2.Project.getMembers
	 */
	public function getMembersAction(
		#[Permission\Read]
		Dto\Project\Project $project,
		ProjectMemberProvider $memberProvider,
		string $type = Dto\Project\MemberFilterType::All->value,
		int $page = 1,
	): ?array
	{
		return $memberProvider->getPagedMembers(
			projectId: $project->id,
			type: Dto\Project\MemberFilterType::tryFrom($type) ?? Dto\Project\MemberFilterType::All,
			page: max(1, $page),
		);
	}

	/**
	 * @ajaxAction socialnetwork.V2.Project.getFeatures
	 */
	public function getFeaturesAction(
		#[Permission\Read]
		Dto\Project\Project $project,
		ProjectProvider $projectProvider,
	): ?array
	{
		$isCurrentUserModuleAdmin = CSocNetUser::isCurrentUserModuleAdmin();

		return $projectProvider->getFeatureMenuItems(
			$project->id,
			$this->userId,
			$isCurrentUserModuleAdmin,
		)->toArray();
	}

	/**
	 * @ajaxAction socialnetwork.V2.Project.getBaseFeature
	 */
	public function getBaseFeatureAction(
		#[Permission\ReadByChatOrProject]
		Dto\Project\Project $project,
		ProjectProvider $projectProvider,
	): ?array
	{
		$projectId = (
			$project->id
			?? Container::getInstance()->getProjectChatResolver()->getProjectIdByChatId((int)($project->chatId))
		);

		if (!$projectId)
		{
			return null;
		}

		$isCurrentUserModuleAdmin = CSocNetUser::isCurrentUserModuleAdmin();

		return $projectProvider->getBaseFeature(
			projectId: $projectId,
			userId: $this->userId,
			isCurrentUserModuleAdmin: $isCurrentUserModuleAdmin,
		)?->toArray();
	}

	/**
	 * @ajaxAction socialnetwork.V2.Project.changeFavorite
	 */
	public function changeFavoriteAction(
		#[Permission\Read]
		Dto\Project\Project $project,
	): ?array
	{
		$result = (new SwitchFavoriteCommand(
			groupId: $project->id,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * @ajaxAction socialnetwork.V2.Project.changePin
	 */
	public function changePinAction(
		#[Permission\Read]
		Dto\Project\Project $project,
		string $mode = '',
	): ?array
	{
		$result = (new SwitchPinCommand(
			groupId: $project->id,
			userId: $this->userId,
			mode: PinMode::fromMode($mode),
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * @ajaxAction socialnetwork.V2.Project.copy
	 */
	public function copyAction(
		#[Permission\Read]
		Dto\Project\Project $sourceProject,
		#[Permission\Create]
		Dto\Project\Project $project,
		?Dto\Project\CopyProjectOptions $copyOptions,
		ProjectProvider $projectProvider,
	): ?ProjectResponse
	{
		$result = (new CopyProjectCommand(
			sourceProjectId: (int)$sourceProject->id,
			project: $project,
			copyOptions: $copyOptions,
			userId: $this->userId,
			isCurrentUserModuleAdmin: CSocNetUser::isCurrentUserModuleAdmin(),
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$projectId = (int)($result->getData()['projectId'] ?? 0);
		if ($projectId === 0)
		{
			$this->addError(new Error('Failed to retrieve copied project ID'));

			return null;
		}

		return $projectProvider->getById($projectId);
	}
}
