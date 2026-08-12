<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Intranet\Entity\Collection\DepartmentCollection;
use Bitrix\Intranet\Entity\InvitationLink;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Enum\InvitationStatus;
use Bitrix\Intranet\Enum\InvitationType;
use Bitrix\Intranet\Enum\LinkEntityType;
use Bitrix\Intranet\Integration\Socialnetwork\Collab\CollabProviderData;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\DepartmentInvitationAccessService;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Service\InvitationAvailabilityService;
use Bitrix\Intranet\Internal\Integration\Bitrix24\License\InvitationLimiter;
use Bitrix\Intranet\Internal\Integration\Bitrix24\PortalCreatorService;
use Bitrix\Intranet\Internal\Integration\Extranet\ExtranetService;
use Bitrix\Intranet\Internal\Integration\Socialnetwork\CollabRepository;
use Bitrix\Intranet\Internal\Integration\Socialnetwork\UserToGroupRoleService;
use Bitrix\Intranet\Internal\Repository\InvitationRepository;
use Bitrix\Intranet\Repository\UserRepository;
use Bitrix\Intranet\Service\InviteLinkGenerator;
use Bitrix\Intranet\Service\ServiceContainer;
use Bitrix\Intranet\User as IntranetUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Collab\Access\CollabAccessController;
use Bitrix\Socialnetwork\Collab\Access\CollabDictionary;
use Bitrix\Socialnetwork\Collab\Collab;
use Bitrix\Socialnetwork\Collab\Provider\CollabProvider;

abstract class BaseCollabTool extends ToolContract
{
	protected InvitationAvailabilityService $invitationAvailabilityService;
	protected DepartmentInvitationAccessService $departmentInvitationAccessService;
	protected ExtranetService $extranetService;

	public function __construct(
		TracedLogger $tracedLogger,
		protected ?CollabRepository $collabRepository = null,
		protected ?UserRepository $userRepository = null,
		protected ?CollabProviderData $collabProviderData = null,
		protected ?InvitationRepository $invitationRepository = null,
		?InvitationAvailabilityService $invitationAvailabilityService = null,
		?DepartmentInvitationAccessService $departmentInvitationAccessService = null,
		?ExtranetService $extranetService = null,
	)
	{
		parent::__construct($tracedLogger);
		$this->collabRepository = $collabRepository ?? new CollabRepository();
		$this->userRepository = $userRepository ?? new UserRepository();
		$this->collabProviderData = $collabProviderData ?? new CollabProviderData();
		$this->invitationRepository = $invitationRepository ?? new InvitationRepository();
		$this->invitationAvailabilityService = $invitationAvailabilityService ?? new InvitationAvailabilityService();
		$this->departmentInvitationAccessService =
			$departmentInvitationAccessService ?? new DepartmentInvitationAccessService()
		;
		$this->extranetService = $extranetService ?? new ExtranetService();
	}

	public function canList(int $userId): bool
	{
		$user = new IntranetUser($userId);
		return
			$userId > 0
			&& ($user->isIntranet() || $user->isExtranet())
		;
	}

	public function canRun(int $userId): bool
	{
		try
		{
			return $this->canList($userId);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		try
		{
			return $this->executeStructuredInternal($userId, ...$args);
		}
		catch (McpException $e)
		{
			throw $e;
		}
		catch (\Throwable $e)
		{
			throw new McpException($e->getMessage());
		}
	}

	abstract protected function executeStructuredInternal(int $userId, ...$args): array;

	protected function resolveProject(int $userId, int $projectId): Collab
	{
		if (!Loader::includeModule('socialnetwork'))
		{
			throw new McpException('Socialnetwork module is not available.');
		}

		$project = CollabProvider::getInstance()->getCollab($projectId);

		if (!$project instanceof Collab)
		{
			throw new McpException("Project with ID {$projectId} was not found.");
		}

		if (!$this->collabProviderData->isAllowedGuestsInvitation($projectId))
		{
			throw new McpException("Guest invitations are disabled for project ID {$projectId}.");
		}

		if (!CollabAccessController::can($userId, CollabDictionary::INVITE, $projectId))
		{
			throw new McpException("Current user cannot manage invitations for project ID {$projectId}.");
		}

		return $project;
	}

	protected function isRegisterByLinkAllowed(): bool
	{
		try
		{
			return
				$this->invitationAvailabilityService->isRegisterByLinkAllowed()
				&& $this->extranetService->isCollaberInvitationEnabled()
			;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	protected function isInvitationAdmissionAllowed(): bool
	{
		try
		{
			return
				!(new InvitationLimiter())->isExceeded()
				&& (new PortalCreatorService())->isPortalCreatorEmailConfirmed()
			;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	protected function isPhoneInviteAllowed(): bool
	{
		return $this->invitationAvailabilityService->isPhoneInviteAllowed();
	}

	protected function resolveDepartmentCollectionByIds(int $userId, array $departmentIds): DepartmentCollection
	{
		return $this->departmentInvitationAccessService->resolveDepartmentCollectionByIds($userId, $departmentIds);
	}

	protected function getCurrentProjectInvitations(
		int $projectId,
		?array $invitationIds = null,
		?array $inviteeIds = null,
		int $limit = 100,
		?int $afterId = null,
	): array
	{
		return $this->getCurrentProjectInvitationsBatch(
			$projectId,
			$invitationIds,
			$inviteeIds,
			$limit,
			$afterId,
		)['items'];
	}

	protected function getCurrentProjectInvitationsBatch(
		int $projectId,
		?array $invitationIds = null,
		?array $inviteeIds = null,
		int $limit = 100,
		?int $afterId = null,
		?array $statuses = null,
	): array
	{
		$invitations = $this->collabRepository->getInvitations(
			$projectId,
			$invitationIds,
			$inviteeIds,
			$limit,
			0,
			$afterId,
			$statuses,
		);

		if (empty($invitations))
		{
			return [
				'items' => [],
				'lastId' => $afterId ?? 0,
				'rawCount' => 0,
				'usersById' => [],
				'invitationTypesByUserId' => [],
			];
		}

		$userIds = array_values(
			array_unique(
				array_map(
					static fn (array $row): int => (int)$row['USER_ID'],
					$invitations,
				)
			)
		);
		$users = [];

		foreach ($this->userRepository->findUsersByIds($userIds) as $user)
		{
			$users[$user->getId()] = $user;
		}

		$invitationTypes = $this->invitationRepository->getInvitationType($userIds);
		$result = [];
		$roleService = new UserToGroupRoleService();

		foreach ($invitations as $row)
		{
			$user = $users[(int)$row['USER_ID']] ?? null;
			if (!$user instanceof User)
			{
				continue;
			}

			$isPendingCollabRequest = $roleService->isGroupInitiatedRequest(
				$row['ROLE'] ?? null,
				$row['INITIATED_BY_TYPE'] ?? null,
			);

			$result[] = [
				'invitationId' => (int)$row['ID'],
				'inviteeId' => $user->getId(),
				'projectId' => (int)$row['GROUP_ID'],
				'channel' => $this->resolveChannel($user, $invitationTypes[$user->getId()] ?? null)?->value,
				'projectInvitation' => [
					'isPendingRequest' => $isPendingCollabRequest,
				],
				'status' => $isPendingCollabRequest
					? InvitationStatus::INVITED->value
					: InvitationStatus::ACTIVE->value
				,
				'createdAt' => $this->formatDate($row['DATE_CREATE'] ?? null),
				'updatedAt' => $this->formatDate($row['DATE_UPDATE'] ?? null),
				'recipient' => [
					'id' => $user->getId(),
					'email' => $user->getEmail(),
					'phoneNumber' => $user->getPhoneNumber(),
					'name' => $user->getName(),
					'lastName' => $user->getLastName(),
					'fullName' => $user->getFormattedName(),
				],
			];
		}

		return [
			'items' => $result,
			'lastId' => (int)($invitations[array_key_last($invitations)]['ID'] ?? ($afterId ?? 0)),
			'rawCount' => count($invitations),
			'usersById' => $users,
			'invitationTypesByUserId' => $invitationTypes,
		];
	}

	protected function ensureAllInvitationIdsFound(array $requestedIds, array $items): void
	{
		$resolvedIds = array_flip(array_map(static fn(array $item): int => (int)$item['invitationId'], $items));
		$missingIds = array_values(
			array_filter(
				$requestedIds,
				static fn(int $id): bool => !isset($resolvedIds[$id]),
			),
		);

		if (!empty($missingIds))
		{
			throw new McpException(
				'Invitation IDs were not found in the current project: ' . implode(', ', $missingIds) . '.'
			);
		}
	}

	protected function getUserById(int $userId): User
	{
		$user = $this->userRepository->getUserById($userId);
		if (!$user instanceof User)
		{
			throw new McpException("User with ID {$userId} was not found.");
		}

		return $user;
	}

	protected function resolveChannel(User $user, ?string $invitationType): ?InvitationType
	{
		if (in_array($invitationType, [InvitationType::EMAIL->value, InvitationType::PHONE->value], true))
		{
			return InvitationType::from($invitationType);
		}

		if (!empty($user->getEmail()))
		{
			return InvitationType::EMAIL;
		}

		if (!empty($user->getPhoneNumber()))
		{
			return InvitationType::PHONE;
		}

		return null;
	}

	protected function getInvitationLinkEntity(int $projectId): ?InvitationLink
	{
		return
			ServiceContainer::getInstance()
			->invitationLinkRepository()
			->getActualByEntity(LinkEntityType::COLLAB, $projectId)
		;
	}

	protected function getAnyInvitationLinkEntity(int $projectId): ?InvitationLink
	{
		return
			ServiceContainer::getInstance()
			->invitationLinkRepository()
			->getByEntity(LinkEntityType::COLLAB, $projectId)
		;
	}

	protected function buildInvitationLinkUrls(int $userId, Collab $project, string $linkCode): array
	{
		$generator = InviteLinkGenerator::createByPayloadWithParams(
			[
				'collab_id' => $project->getId(),
				'collab_name' => $project->getName(),
				'inviting_user_id' => $userId,
				'link_code' => $linkCode,
			],
			[
				'user_lang' => defined('LANGUAGE_ID') ? LANGUAGE_ID : 'en',
			],
		);

		if ($generator === null)
		{
			throw new McpException('Failed to build invitation link URL.');
		}

		return [
			'shortLink' => $generator->getShortCollabLink(),
			'link' => $generator->getCollabLink(),
		];
	}

	protected function formatResultErrors(Result $result): string
	{
		$messages = array_filter(
			array_map(
				static fn($error): ?string => method_exists($error, 'getMessage') ? $error->getMessage() : null,
				$result->getErrors(),
			),
		);

		return empty($messages) ? 'Operation failed.' : implode('; ', $messages);
	}

	private function formatDate(mixed $value): ?string
	{
		if ($value === null)
		{
			return null;
		}

		if (is_object($value) && method_exists($value, 'format'))
		{
			return $value->format(DATE_ATOM);
		}

		return is_scalar($value) ? (string)$value : null;
	}
}
