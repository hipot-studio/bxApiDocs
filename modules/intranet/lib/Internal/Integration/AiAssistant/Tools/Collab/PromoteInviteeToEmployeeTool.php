<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Extranet\Entity\ExtranetUser;
use Bitrix\Extranet\Enum\User\ExtranetRole;
use Bitrix\Extranet\Service\ServiceContainer;
use Bitrix\HumanResources\Enum\NodeActiveFilter;
use Bitrix\HumanResources\Item\Collection\NodeMemberCollection;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Enum\InvitationStatus;
use Bitrix\Intranet\Integration\HumanResources\DepartmentAssigner;
use Bitrix\Intranet\Integration\HumanResources\HrUserService;
use Bitrix\Intranet\Integration\HumanResources\PermissionInvitation;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\Collab\PromoteInviteeToEmployeeDto;
use Bitrix\Intranet\Internal\Integration\Bitrix24\License\InvitationLimiter;
use Bitrix\Intranet\Internal\Integration\Bitrix24\PortalCreatorService;
use Bitrix\Intranet\Internal\Integration\Socialnetwork\CollabRepository;
use Bitrix\Intranet\Internal\Integration\Socialnetwork\UserToGroupRoleService;
use Bitrix\Main\Loader;
use CIntranetInviteDialog;
use CUser;

class PromoteInviteeToEmployeeTool extends BaseCollabTool
{
	public function __construct(
		TracedLogger $tracedLogger,
		?CollabRepository $collabRepository = null,
		private ?HrUserService $hrUserService = null,
	)
	{
		parent::__construct($tracedLogger, $collabRepository);
		$this->hrUserService = $hrUserService ?? new HrUserService();
	}

	public function canRun(int $userId): bool
	{
		try
		{
			return
				parent::canRun($userId)
				&& (new PermissionInvitation($userId))->canInvite()
			;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public function getName(): string
	{
		return 'promote_invitee_to_employee';
	}

	public function getDescription(): string
	{
		return
			'Promotes an accepted project guest into a company employee and assigns them to a department. '
			. 'Use this tool only after the guest has already accepted the project invitation. '
			. 'The tool adds the user to the selected department and removes the current collaber or extranet role.'
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'projectId' => [
					'type' => 'integer',
					'description' => 'Target project ID.',
					'minimum' => 1,
				],
				'inviteeId' => [
					'type' => 'integer',
					'description' => 'Invitee user ID resolved from the project invitation list.',
					'minimum' => 1,
				],
				'departmentId' => [
					'type' => 'integer',
					'description' => 'Target department ID.',
					'minimum' => 1,
				],
			],
			'required' => ['projectId', 'inviteeId', 'departmentId'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructuredInternal(int $userId, ...$args): array
	{
		$dto = PromoteInviteeToEmployeeDto::fromArray($args);

		$this->resolveProject($userId, $dto->projectId);
		$this->checkInvitee($dto->inviteeId, $dto->projectId);

		$rollbackState = $this->capturePromotionRollbackState($dto->inviteeId);

		try
		{
			$this->assignUserToDepartment($dto->inviteeId, $dto->departmentId, $userId);

			if ($this->isExtranetModuleLoaded())
			{
				$this->removeExtranetRole($dto->inviteeId);
			}

			$updatedUser = $this->getUserById($dto->inviteeId);
			$isEmployee = $this->hrUserService->isEmployee($updatedUser);
			if (!$isEmployee)
			{
				throw new McpException(
					"User ID {$dto->inviteeId} could not be promoted to employee."
				);
			}
		}
		catch (\Throwable $exception)
		{
			try
			{
				$this->rollbackPromotion($dto->inviteeId, $rollbackState);
			}
			catch (\Throwable $rollbackException)
			{
				throw new McpException(
					$exception->getMessage() . ' Rollback failed: ' . $rollbackException->getMessage()
				);
			}

			throw $exception;
		}

		return [
			'projectId' => $dto->projectId,
			'invitee' => [
				'id' => $updatedUser->getId(),
				'email' => $updatedUser->getEmail(),
				'phoneNumber' => $updatedUser->getPhoneNumber(),
				'name' => $updatedUser->getName(),
				'lastName' => $updatedUser->getLastName(),
				'fullName' => $updatedUser->getFormattedName(),
			],
			'departmentId' => $dto->departmentId,
			'promoted' => true,
			'isEmployee' => true,
			'consumesLicense' => true,
		];
	}

	protected function capturePromotionRollbackState(int $inviteeId): array
	{
		return [
			'userGroups' => CUser::GetUserGroup($inviteeId),
			'departmentMembers' => $this->getCurrentDepartmentMembers($inviteeId),
			'extranetUser' => $this->getCurrentExtranetUser($inviteeId),
		];
	}

	protected function rollbackPromotion(int $inviteeId, array $rollbackState): void
	{
		$this->restoreDepartmentMembers($inviteeId, $rollbackState['departmentMembers'] ?? null);
		$this->restoreUserGroups($inviteeId, $rollbackState['userGroups'] ?? null);
		$this->restoreExtranetUser($inviteeId, $rollbackState['extranetUser'] ?? null);
	}

	protected function restoreUserGroups(int $inviteeId, mixed $userGroups): void
	{
		if (is_array($userGroups))
		{
			CUser::SetUserGroup($inviteeId, $userGroups);
		}
	}

	protected function isExtranetModuleLoaded(): bool
	{
		return Loader::includeModule('extranet');
	}

	protected function checkInvitee(int $inviteeId, int $projectId): void
	{
		$relation = $this->collabRepository->getInvitations(
			$projectId,
			inviteeIds: [$inviteeId],
			limit: 1,
		)[0] ?? null;

		if ($relation === null)
		{
			throw new McpException(
				"Invitee ID {$inviteeId} does not have a current invitation in project ID {$projectId}."
			);
		}

		$role = is_string($relation['ROLE'] ?? null) ? $relation['ROLE'] : null;
		$initiatedByType = is_string($relation['INITIATED_BY_TYPE'] ?? null) ? $relation['INITIATED_BY_TYPE'] : null;
		$roleService = new UserToGroupRoleService();
		if (!$roleService->isMember($role))
		{
			$status = $roleService->isGroupInitiatedRequest($role, $initiatedByType)
				? InvitationStatus::INVITED->value
				: InvitationStatus::ACTIVE->value
			;

			throw new McpException(
				"Invitee ID {$inviteeId} is not in accepted state. Current status: {$status}."
			);
		}
	}

	protected function assignUserToDepartment(int $inviteeId, int $departmentId, int $userId): void
	{
		$user = $this->getUserById($inviteeId);
		if ($this->hrUserService->isEmployee($user))
		{
			throw new McpException("User ID {$inviteeId} is already a company employee.");
		}

		$departmentCollection = $this->resolveDepartmentCollectionByIds($userId, [$departmentId]);
		if ($departmentCollection->empty())
		{
			throw new McpException("Department with ID {$departmentId} was not found.");
		}

		$currentGroups = CUser::GetUserGroup($inviteeId);
		$employeeGroups = CIntranetInviteDialog::getUserGroups(SITE_ID);

		CUser::SetUserGroup($inviteeId, array_values(array_unique(array_merge($currentGroups, $employeeGroups))));

		(new DepartmentAssigner($departmentCollection))->assignUser($user);
	}

	protected function getCurrentDepartmentMembers(int $inviteeId): ?NodeMemberCollection
	{
		if (!Loader::includeModule('humanresources'))
		{
			return null;
		}

		return Container::getNodeMemberRepository()->findAllByEntityIdAndEntityTypeAndNodeType(
			entityId: $inviteeId,
			entityType: MemberEntityType::USER,
			nodeType: NodeEntityType::DEPARTMENT,
			activeFilter: NodeActiveFilter::ALL,
		);
	}

	protected function restoreDepartmentMembers(int $inviteeId, ?NodeMemberCollection $departmentMembers): void
	{
		if ($departmentMembers === null || !Loader::includeModule('humanresources'))
		{
			return;
		}

		$repository = Container::getNodeMemberRepository();
		$currentDepartmentMembers = $this->getCurrentDepartmentMembers($inviteeId);

		if ($currentDepartmentMembers !== null && !$currentDepartmentMembers->empty())
		{
			$repository->removeByCollection($currentDepartmentMembers);
		}

		if (!$departmentMembers->empty())
		{
			$repository->createByCollection($departmentMembers);
		}
	}

	protected function getCurrentExtranetUser(int $inviteeId): ?ExtranetUser
	{
		if (!$this->isExtranetModuleLoaded())
		{
			return null;
		}

		return ServiceContainer::getInstance()
			->getExtranetUserRepository()
			->getByUserId($inviteeId)
		;
	}

	protected function restoreExtranetUser(int $inviteeId, ?ExtranetUser $extranetUser): void
	{
		if (!$this->isExtranetModuleLoaded())
		{
			return;
		}

		$repository = ServiceContainer::getInstance()->getExtranetUserRepository();
		if ($extranetUser === null && $repository->getByUserId($inviteeId) === null)
		{
			return;
		}

		$result = $extranetUser === null
			? $repository->deleteByUserId($inviteeId)
			: $repository->upsert($extranetUser)
		;

		if (!$result->isSuccess())
		{
			throw new McpException($this->formatResultErrors($result));
		}

		$extranetContainer = ServiceContainer::getInstance();
		$extranetContainer->getCollaberService()->clearCache();
		$extranetContainer->getUserService()->clearCache();
	}

	protected function removeExtranetRole(int $inviteeId): void
	{
		$extranetContainer = ServiceContainer::getInstance();
		$collaberService = $extranetContainer->getCollaberService();
		$userService = $extranetContainer->getUserService();

		$result =
			$collaberService->isCollaberById($inviteeId)
			? $collaberService->removeCollaberRoleByUserId($inviteeId)
			: $userService->setRoleById($inviteeId, ExtranetRole::FormerExtranet)
		;

		if (!$result->isSuccess())
		{
			throw new McpException($this->formatResultErrors($result));
		}

		$collaberService->clearCache();
		$userService->clearCache();
	}
}
