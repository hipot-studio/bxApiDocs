<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Provider;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Type\Collection;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Socialnetwork\Collab\Registry\CollabRegistry;
use Bitrix\Socialnetwork\V2\Internal\Access\Service\ProjectAccessService;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Project;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\ProjectCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\UserCollection;
use Bitrix\Socialnetwork\V2\Internal\Integration;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Provider\ProjectChatDataProvider;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectFeatureRepository;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\FeatureAvailabilityService;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\FeatureDictionary;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\FeatureService;
use Bitrix\Socialnetwork\V2\Internal\Service\Notification\ProjectNotificationSettingsService;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\HasCollabersService;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\ProjectCreateFeatures;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\ProjectFeatureToggleService;
use Bitrix\Socialnetwork\V2\Internal\Service\UserServiceInterface;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\FeatureCollection;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\Feature;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\NotificationCatalogResponse;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\ProjectResponse;
use Bitrix\Socialnetwork\V2\Public\Mapper\ProjectMapper;
use Bitrix\Socialnetwork\V2\Public\Provider\Params\Project\ProjectParams;
use Bitrix\Socialnetwork\V2\Public\Provider\Params\Project\ProjectSelect;

class ProjectProvider
{
	private ProjectRepositoryInterface $projectRepository;
	private ProjectMapper $projectMapper;
	private FeatureService $projectFeatureService;
	private FeatureAvailabilityService $projectFeatureAvailabilityService;
	private ProjectCreateFeatures $projectCreateFeatures;
	private ProjectFeatureRepository $projectFeatureRepository;
	private ProjectFeatureToggleService $projectFeatureToggleService;
	private UserServiceInterface $userService;
	private ProjectChatDataProvider $projectChatDataProvider;
	private ?HasCollabersService $hasCollabersService;
	private ProjectAccessService $projectAccessService;
	private ProjectNotificationSettingsService $notificationSettingsService;
	private Integration\Landing\Service\Project $landingProjectService;

	public function __construct(
		?ProjectRepositoryInterface $projectRepository = null,
		?ProjectMapper $projectMapper = null,
		?FeatureService $projectFeatureService = null,
		?FeatureAvailabilityService $projectFeatureAvailabilityService = null,
		?ProjectCreateFeatures $projectCreateFeatures = null,
		?ProjectFeatureRepository $projectFeatureRepository = null,
		?ProjectFeatureToggleService $projectFeatureToggleService = null,
		?UserServiceInterface $userService = null,
		?ProjectChatDataProvider $projectChatDataProvider = null,
		?HasCollabersService $hasCollabersService = null,
		?ProjectAccessService $projectAccessService = null,
		?ProjectNotificationSettingsService $notificationSettingsService = null,
		?Integration\Landing\Service\Project $landingProjectService = null,
	)
	{
		$container = Container::getInstance();

		$this->projectRepository = $projectRepository ?? $container->getProjectRepository();
		$this->projectMapper = $projectMapper ?? $container->getProjectMapper();
		$this->projectFeatureService = $projectFeatureService ?? $container->getProjectFeatureService();
		$this->projectFeatureAvailabilityService =
			$projectFeatureAvailabilityService ?? $container->getProjectFeatureAvailabilityService();
		$this->projectCreateFeatures = $projectCreateFeatures ?? $container->getProjectCreateFeatures();
		$this->projectFeatureRepository = $projectFeatureRepository ?? $container->getProjectFeatureRepository();
		$this->projectFeatureToggleService = $projectFeatureToggleService ?? $container->getProjectFeatureToggleService();
		$this->userService = $userService ?? $container->getUserService();
		$this->projectChatDataProvider = $projectChatDataProvider ?? $container->getProjectChatDataProvider();
		$this->hasCollabersService = $hasCollabersService ?? $container->getHasCollabersService();
		$this->projectAccessService = $projectAccessService ?? $container->getProjectAccessService();
		$this->notificationSettingsService = $notificationSettingsService ?? $container->get(ProjectNotificationSettingsService::class);
		$this->landingProjectService = $landingProjectService ?? $container->get(Integration\Landing\Service\Project::class);
	}

	/**
	 * @param int $groupId
	 * @return bool
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function isProject(int $groupId): bool
	{
		return (CollabRegistry::getInstance()->get($groupId) !== null);
	}

	/**
	 * Opens the project knowledge base when the page is hit by a direct link to it.
	 * An ordinary hit costs nothing: landing is loaded only after the hit is recognized as a deep link.
	 */
	public function processKnowledgeDeepLink(int $projectId): void
	{
		$this->landingProjectService->processKnowledgeDeepLink($projectId);
	}

	public function getById(int $projectId): ?ProjectResponse
	{
		$entity = $this->projectRepository->getById($projectId);

		if ($entity === null)
		{
			return null;
		}

		$entity = $this->hydrateChat(
			$entity,
			$this->projectChatDataProvider->getByProjectIds([$projectId]),
		);
		$entity = $this->hydrateFeatureToggles($entity);
		$entity = $this->hydrateOwner($entity);
		$entity = $this->sanitizeMembersForResponse($entity);

		$response = $this->projectMapper->toResponse($entity);
		$response->setNotificationCatalog(
			$this->notificationSettingsService->buildCatalog($projectId),
		);

		return $response;
	}

	/**
	 * Returns the default notification catalog for the project create-form.
	 * New projects are always NotRequired, so defaults are always present.
	 */
	public function getNotificationCatalogDefaults(): NotificationCatalogResponse
	{
		return $this->notificationSettingsService->buildDefaultCatalog();
	}

	/**
	 * @return ProjectResponse[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getProjects(ProjectParams $gridParams): array
	{
		$select = $gridParams->getSelect() ?? ['*'];
		$sort = $gridParams->getSort() ?? [];

		$collection = $this->projectRepository->getList(
			select: $select,
			filter: $gridParams->getFilter(),
			sort: $sort,
			offset: $gridParams->getOffset(),
			limit: $gridParams->getLimit(),
			withImage: true,
		);

		$collection = $this->hydrateChats($collection);

		if ($this->shouldHydrateOwners($gridParams))
		{
			$collection = $this->hydrateOwners($collection);
		}

		$result = [];
		foreach ($collection as $entity)
		{
			$result[] = $this->projectMapper->toResponse($entity);
		}

		return $result;
	}

	public function getAvailableFeatures(
		int $projectId,
		int $userId,
		bool $isCurrentUserModuleAdmin,
	): FeatureCollection
	{
		$features = $this->projectFeatureAvailabilityService->filterAvailableFeatures(
			$this->projectFeatureService->getAvailableFeatures($projectId, $userId),
			$projectId,
			$userId,
			$isCurrentUserModuleAdmin,
		);

		return FeatureCollection::mapFromArray($features->toArray());
	}

	public function getFeatureMenuItems(
		int $projectId,
		int $userId,
		bool $isCurrentUserModuleAdmin,
	): FeatureCollection
	{
		$collection = $this->getAvailableFeatures($projectId, $userId, $isCurrentUserModuleAdmin);

		if (
			\Bitrix\Socialnetwork\V2\Feature::isOldPortalForNewProject()
			&& $this->projectAccessService->canUpdate($userId, $projectId)
		)
		{
			$collection->add($this->buildStartupToolActionItem());
		}

		return $collection;
	}

	private function buildStartupToolActionItem(): Feature
	{
		return new Feature(
			id: 'settings_startup_tool',
			title: FeatureDictionary::getStartupToolTitle(),
			type: 'action',
		);
	}

	public function hasCollabers(int $projectId): bool
	{
		$batch = $this->projectRepository->getHasCollabersBatch([$projectId]);

		return $batch[$projectId] ?? false;
	}

	/**
	 * Read + lazy backfill: for ids missing in b_sonet_collab_option the option
	 * is recomputed from current membership and persisted in-place. The returned
	 * map always contains every requested id.
	 *
	 * Covers legacy collabs created before HasCollabersHandler was registered:
	 * such projects can carry external users but no HAS_COLLABERS row. First
	 * call to this method populates them.
	 *
	 * @param int[] $projectIds
	 *
	 * @return array<int, bool>
	 */
	public function ensureHasCollabersMap(array $projectIds): array
	{
		if ($projectIds === [])
		{
			return [];
		}

		$map = $this->projectRepository->getHasCollabersBatch($projectIds);

		$missing = array_diff($projectIds, array_keys($map));

		if ($missing === [])
		{
			return $map;
		}

		foreach ($missing as $id)
		{
			$map[(int)$id] = $this->hasCollabersService->updateOption((int)$id);
		}

		return $map;
	}

	public function getAvailableFeaturesForCreate(): FeatureCollection
	{
		$defaultFeatureStates = $this->projectCreateFeatures->getFeatures();
		$allowedFeatureIds = array_fill_keys(
			[
				...$this->projectFeatureRepository->getAllowedFeatureIds(),
				FeatureDictionary::Chat->value,
			],
			true,
		);
		$features = [];

		foreach (FeatureDictionary::getRegularFeatureIds() as $featureId)
		{
			if (
				!array_key_exists($featureId, $defaultFeatureStates)
				|| $defaultFeatureStates[$featureId] !== true
				|| !array_key_exists($featureId, $allowedFeatureIds)
			)
			{
				continue;
			}

			$name = FeatureDictionary::getDefaultNameById($featureId);

			$features[] = new Feature(
				id: $featureId,
				name: $name,
				title: $name,
			);
		}

		return new FeatureCollection(...$features);
	}

	public function getBaseFeature(
		int $projectId,
		int $userId,
		bool $isCurrentUserModuleAdmin,
	): ?Feature
	{
		$baseFeatureId = $this->projectFeatureRepository->getBaseFeatureId($projectId);
		if ($baseFeatureId === null)
		{
			return null;
		}

		return $this->getAvailableFeatures(
			projectId: $projectId,
			userId: $userId,
			isCurrentUserModuleAdmin: $isCurrentUserModuleAdmin,
		)->findOneById($baseFeatureId);
	}

	/**
	 * @return ProjectResponse[]
	 */
	public function getProjectsWithSummaryAgent(?int $offset = null, ?int $limit = null): array
	{
		$collection = $this->projectRepository->getProjectsWithSummaryAgent($offset, $limit);

		$result = [];
		foreach ($collection as $entity)
		{
			$result[] = $this->projectMapper->toResponse($entity);
		}

		return $result;
	}

	private function shouldHydrateOwners(ProjectParams $gridParams): bool
	{
		return ($gridParams->select instanceof ProjectSelect) && $gridParams->select->owner;
	}

	private function hydrateOwners(ProjectCollection $collection): ProjectCollection
	{
		$ownerIds = [];
		foreach ($collection as $entity)
		{
			if ($entity->owner === null && ($entity->ownerId ?? 0) > 0)
			{
				$ownerIds[] = $entity->ownerId;
			}
		}

		if ($ownerIds === [])
		{
			return $collection;
		}

		$owners = $this->userService->getUsers($ownerIds);
		$result = [];
		foreach ($collection as $entity)
		{
			$result[] = $this->hydrateOwner($entity, $owners);
		}

		return new ProjectCollection(...$result);
	}

	private function hydrateChats(ProjectCollection $collection): ProjectCollection
	{
		$projectIds = [];
		foreach ($collection as $entity)
		{
			if (($entity->id ?? 0) > 0)
			{
				$projectIds[] = $entity->id;
			}
		}

		if ($projectIds === [])
		{
			return $collection;
		}

		Collection::normalizeArrayValuesByInt($projectIds, false);
		$projectIds = array_values(array_unique($projectIds));
		$projectChatData = $this->projectChatDataProvider->getByProjectIds($projectIds);

		$result = [];
		foreach ($collection as $entity)
		{
			$result[] = $this->hydrateChat($entity, $projectChatData);
		}

		return new ProjectCollection(...$result);
	}

	/**
	 * @param array<int, array{chatId: ?int, color: ?string}> $projectChatData
	 */
	private function hydrateChat(Project $entity, array $projectChatData = []): Project
	{
		if (($entity->id ?? 0) <= 0)
		{
			return $entity;
		}

		$chatData = $projectChatData[$entity->id] ?? [
			'chatId' => null,
			'color' => null,
		];

		$chatId = (int)($chatData['chatId'] ?? 0);
		$color = $chatData['color'] ?? null;

		return $this->copyProject($entity, [
			'chatId' => $chatId > 0 ? $chatId : null,
			'color' => is_string($color) && $color !== '' ? $color : null,
		]);
	}

	private function hydrateOwner(Project $entity, ?UserCollection $owners = null): Project
	{
		if ($entity->owner !== null || ($entity->ownerId ?? 0) <= 0)
		{
			return $entity;
		}

		$owners ??= $this->userService->getUsers([$entity->ownerId]);
		$owner = $owners->findOneById($entity->ownerId);

		if ($owner === null)
		{
			return $entity;
		}

		return $this->copyProject($entity, ['owner' => $owner]);
	}

	private function hydrateFeatureToggles(Project $entity): Project
	{
		if (($entity->id ?? 0) <= 0)
		{
			return $entity;
		}

		$toggleableFeatureIds = $this->projectFeatureToggleService->getToggleableFeatureIds($entity->id);

		return $this->copyProject($entity, [
			'features' => $this->getProjectFeatures($entity->id, $toggleableFeatureIds),
			'baseFeatureId' => $this->projectFeatureRepository->getBaseFeatureId($entity->id),
			'toggleableFeatures' => $toggleableFeatureIds,
		]);
	}

	private function sanitizeMembersForResponse(Project $entity): Project
	{
		$members = $entity->members;
		if ($members === null || $members->isEmpty())
		{
			return $entity;
		}

		$excludedUserIds = $this->getExcludedMemberIds($entity);
		if ($excludedUserIds === [])
		{
			return $entity;
		}

		$filteredMembers = $members->filter(
			static fn (MemberEntity $member): bool => (
				$member->type !== MemberEntityType::User
				|| !in_array($member->id, $excludedUserIds, true)
			),
		);

		if ($filteredMembers->count() === $members->count())
		{
			return $entity;
		}

		return $this->copyProject($entity, ['members' => $filteredMembers]);
	}

	/**
	 * @return int[]
	 */
	private function getExcludedMemberIds(Project $entity): array
	{
		$excludedUserIds = $entity->moderatorMembers?->getIds() ?? [];

		if (($entity->ownerId ?? 0) > 0)
		{
			$excludedUserIds[] = $entity->ownerId;
		}

		if ($excludedUserIds === [])
		{
			return [];
		}

		Collection::normalizeArrayValuesByInt($excludedUserIds, false);

		return array_values(array_unique($excludedUserIds));
	}

	private function copyProject(Project $entity, array $overrides = []): Project
	{
		$state = [
			'id' => $entity->id,
			'name' => $entity->name,
			'description' => $entity->description,
			'goal' => $entity->goal,
			'avatar' => $entity->avatar,
			'owner' => $entity->owner,
			'ownerId' => $entity->ownerId,
			'archived' => $entity->archived,
			'chatId' => $entity->chatId,
			'color' => $entity->color,
			'permissions' => $entity->permissions,
			'privacyType' => $entity->privacyType,
			'dates' => $entity->dates,
			'tags' => $entity->tags,
			'createdTs' => $entity->createdTs,
			'activityTs' => $entity->activityTs,
			'efficiency' => $entity->efficiency,
			'numberOfMembers' => $entity->numberOfMembers,
			'numberOfModerators' => $entity->numberOfModerators,
			'members' => $entity->members,
			'moderatorMembers' => $entity->moderatorMembers,
			'rawPermissions' => $entity->rawPermissions,
			'options' => $entity->options,
			'features' => $entity->features,
			'baseFeatureId' => $entity->baseFeatureId,
			'toggleableFeatures' => $entity->toggleableFeatures,
			'tagNames' => $entity->tagNames,
			'publication' => $entity->publication,
			'hasCollabers' => $entity->hasCollabers,
		];

		return new Project(...array_replace($state, $overrides));
	}

	/**
	 * @return array<string, bool>
	 */
	private function getProjectFeatures(int $projectId, array $toggleableFeatureIds = []): array
	{
		$features = [
			...$this->projectCreateFeatures->getFeatures(),
			...$this->projectFeatureRepository->getFeatureStates($projectId),
		];

		if ($toggleableFeatureIds === [])
		{
			return $features;
		}

		$groupFeatureStates = $this->projectFeatureRepository->getGroupFeatureStates($projectId);
		foreach ($toggleableFeatureIds as $featureId)
		{
			if (!is_string($featureId) || !array_key_exists($featureId, $groupFeatureStates))
			{
				continue;
			}

			$features[$featureId] = $groupFeatureStates[$featureId];
		}

		return $features;
	}
}
