<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access\Service;

use Bitrix\SocialNetwork\Collab\Access\Model\CollabModel;
use Bitrix\Socialnetwork\Collab\Collab;
use Bitrix\Socialnetwork\Collab\Registry\CollabRegistry;
use Bitrix\Socialnetwork\Helper\Workgroup\Access;
use Bitrix\Socialnetwork\Permission\AbstractAccessController;
use Bitrix\Socialnetwork\V2\Internal\Access\AccessUserErrorTrait;
use Bitrix\Socialnetwork\V2\Internal\Access\Factory\ControllerFactoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Access\Factory\Type;
use Bitrix\Socialnetwork\V2\Internal\Access\Project\ActionDictionary;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\MemberEntityMapper;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\ProjectMemberDiffBuilder;

class ProjectAccessService implements GridAccessServiceInterface
{
	use AccessUserErrorTrait;

	public function __construct(
		private readonly ControllerFactoryInterface $controllerFactory,
		private readonly CollabRegistry $collabRegistry,
		private readonly ProjectMemberDiffBuilder $projectMemberDiffBuilder,
		private readonly MemberEntityMapper $memberEntityMapper,
	)
	{
	}

	public function canRead(int $userId, int $entityId): bool
	{
		return $this->can($userId, ActionDictionary::Read, $entityId);
	}

	public function canCreate(int $userId, ?string $siteId = null): bool
	{
		$this->clearUserError();

		if ($userId <= 0)
		{
			return false;
		}

		$siteId = is_string($siteId) && $siteId !== '' ? $siteId : SITE_ID;

		return Access::canCreate([
			'userId' => $userId,
			'siteId' => $siteId,
		]);
	}

	public function canUpdate(int $userId, int $entityId, array $projectData = []): bool
	{
		if ($projectData === [])
		{
			return $this->can($userId, ActionDictionary::Update, $entityId);
		}

		[$before, $controller] = $this->resolveAccessContext($userId, $entityId);
		if ($before === null || $controller === null)
		{
			return false;
		}

		$payload = $this->projectMemberDiffBuilder->build($entityId, $projectData);

		$ownerId = (int)($projectData['ownerId'] ?? 0);
		if ($ownerId > 0)
		{
			$payload['ownerId'] = $ownerId;
		}

		$modelData = array_merge(['id' => $entityId], $payload);

		return $this->canWithModelData(
			controller: $controller,
			before: $before,
			action: ActionDictionary::Update,
			modelData: $modelData,
		);
	}

	public function canDelete(int $userId, int $entityId): bool
	{
		return $this->can($userId, ActionDictionary::Delete, $entityId);
	}

	public function canJoin(int $userId, int $entityId): bool
	{
		if ($userId <= 0 || $entityId <= 0)
		{
			return false;
		}

		if ($this->collabRegistry->get($entityId) === null)
		{
			return false;
		}

		return Access::canJoin([
			'groupId' => $entityId,
			'userId' => $userId,
		]);
	}

	public function canInvite(int $userId, int $entityId, array $projectData = []): bool
	{
		if ($projectData === [])
		{
			return $this->can($userId, ActionDictionary::Invite, $entityId);
		}

		[$before, $controller] = $this->resolveAccessContext($userId, $entityId);
		if ($before === null || $controller === null)
		{
			return false;
		}

		$addMembers = $this->extractAccessCodes($projectData, 'members');
		if ($addMembers === null)
		{
			return $this->can($userId, ActionDictionary::Invite, $entityId);
		}

		$modelData = ['id' => $entityId, 'addMembers' => $addMembers];

		return $this->canWithModelData(
			controller: $controller,
			before: $before,
			action: ActionDictionary::Invite,
			modelData: $modelData,
		);
	}

	public function canLeave(int $userId, int $entityId): bool
	{
		return $this->can($userId, ActionDictionary::Leave, $entityId);
	}

	public function canExclude(int $userId, int $entityId, array $projectData = []): bool
	{
		if ($projectData === [])
		{
			return $this->can($userId, ActionDictionary::Exclude, $entityId);
		}

		[$before, $controller] = $this->resolveAccessContext($userId, $entityId);
		if ($before === null || $controller === null)
		{
			return false;
		}

		$deleteMembers = $this->extractAccessCodes($projectData, 'members');
		if ($deleteMembers === null)
		{
			return $this->can($userId, ActionDictionary::Exclude, $entityId);
		}

		$modelData = ['id' => $entityId, 'deleteMembers' => $deleteMembers];

		return $this->canWithModelData(
			controller: $controller,
			before: $before,
			action: ActionDictionary::Exclude,
			modelData: $modelData,
		);
	}

	public function canSetModerator(int $userId, int $entityId, array $projectData = []): bool
	{
		if ($projectData === [])
		{
			return $this->can($userId, ActionDictionary::SetModerator, $entityId);
		}

		[$before, $controller] = $this->resolveAccessContext($userId, $entityId);
		if ($before === null || $controller === null)
		{
			return false;
		}

		$addModeratorMembers = $this->extractAccessCodes($projectData, 'moderatorMembers');
		if ($addModeratorMembers === null)
		{
			return $this->can($userId, ActionDictionary::SetModerator, $entityId);
		}

		$modelData = ['id' => $entityId, 'addModeratorMembers' => $addModeratorMembers];

		return $this->canWithModelData(
			controller: $controller,
			before: $before,
			action: ActionDictionary::SetModerator,
			modelData: $modelData,
		);
	}

	public function canExcludeModerator(int $userId, int $entityId, array $projectData = []): bool
	{
		if ($projectData === [])
		{
			return $this->can($userId, ActionDictionary::ExcludeModerator, $entityId);
		}

		[$before, $controller] = $this->resolveAccessContext($userId, $entityId);
		if ($before === null || $controller === null)
		{
			return false;
		}

		$deleteModeratorMembers = $this->extractAccessCodes($projectData, 'moderatorMembers');
		if ($deleteModeratorMembers === null)
		{
			return $this->can($userId, ActionDictionary::ExcludeModerator, $entityId);
		}

		$modelData = ['id' => $entityId, 'deleteModeratorMembers' => $deleteModeratorMembers];

		return $this->canWithModelData(
			controller: $controller,
			before: $before,
			action: ActionDictionary::ExcludeModerator,
			modelData: $modelData,
		);
	}

	public function canSetOwner(int $userId, int $entityId, array $projectData = []): bool
	{
		if ($projectData === [])
		{
			return $this->can($userId, ActionDictionary::SetOwner, $entityId);
		}

		[$before, $controller] = $this->resolveAccessContext($userId, $entityId);
		if ($before === null || $controller === null)
		{
			return false;
		}

		$ownerId = (int)($projectData['ownerId'] ?? 0);
		if ($ownerId <= 0)
		{
			return $this->can($userId, ActionDictionary::SetOwner, $entityId);
		}

		$modelData = ['id' => $entityId, 'ownerId' => $ownerId];

		return $this->canWithModelData(
			controller: $controller,
			before: $before,
			action: ActionDictionary::SetOwner,
			modelData: $modelData,
		);
	}

	public function canCopyLink(int $userId, int $entityId): bool
	{
		return $this->can($userId, ActionDictionary::CopyLink, $entityId);
	}

	public function can(int $userId, ActionDictionary $action, ?int $entityId = null, array $params = []): bool
	{
		$this->clearUserError();

		if ($userId <= 0)
		{
			return false;
		}

		if ($entityId !== null && $entityId <= 0)
		{
			return false;
		}

		if ($entityId !== null)
		{
			$collab = $this->collabRegistry->get($entityId);
			if ($collab === null)
			{
				return false;
			}
		}

		$controller = $this->createProjectController($userId);
		if ($controller === null)
		{
			return false;
		}

		$result = $controller->checkByItemId($action->value, $entityId, $params);

		if (!$result)
		{
			$this->resolveUserError($controller);
		}

		return $result;
	}

	private function createProjectController(int $userId): ?AbstractAccessController
	{
		$controller = $this->controllerFactory->create(Type::Project, $userId);

		return $controller instanceof AbstractAccessController ? $controller : null;
	}

	/**
	 * @return array{?Collab, ?AbstractAccessController}
	 */
	private function resolveAccessContext(int $userId, int $entityId): array
	{
		$this->clearUserError();

		if ($userId <= 0 || $entityId <= 0)
		{
			return [null, null];
		}

		$before = $this->collabRegistry->get($entityId);
		if ($before === null)
		{
			return [null, null];
		}

		$controller = $this->createProjectController($userId);
		if ($controller === null)
		{
			return [null, null];
		}

		return [$before, $controller];
	}

	private function canWithModelData(
		AbstractAccessController $controller,
		Collab $before,
		ActionDictionary $action,
		array $modelData,
	): bool
	{
		$model = $controller->getModel($modelData);
		if (!$model instanceof CollabModel)
		{
			return false;
		}

		if (!$controller->check($action->value, $model, $before))
		{
			$this->resolveUserError($controller);

			return false;
		}

		return true;
	}

	/**
	 * @return string[]|null
	 */
	private function extractAccessCodes(array $projectData, string $key): ?array
	{
		$members = $projectData[$key] ?? null;

		if (!is_array($members))
		{
			return null;
		}

		return $this->memberEntityMapper->toAccessCodes(
			$this->memberEntityMapper->fromArrays($members),
		);
	}
}
