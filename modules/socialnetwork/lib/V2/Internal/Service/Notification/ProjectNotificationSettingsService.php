<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Notification;

use Bitrix\Socialnetwork\V2\Internal\Entity\Notification\NotificationGroup;
use Bitrix\Socialnetwork\V2\Internal\Entity\Notification\NotificationType;
use Bitrix\Socialnetwork\V2\Internal\Repository\CollabOptionRepository;
use Bitrix\Socialnetwork\V2\Internal\Repository\Option\NotificationCounterOption;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\NotificationCatalogResponse;

/**
 * Facade for notification settings: counter read, catalog build, and save.
 *
 * Consolidates counter-read and catalog-build into a single cohesive service.
 * All writes go through save(); all reads are pure (no side-effects).
 */
class ProjectNotificationSettingsService
{
	public function __construct(
		private readonly NotificationAvailabilityService $availabilityGate,
		private readonly NotificationCounterMapCodec $codec,
		private readonly CollabOptionRepository $collabOptionRepository,
	)
	{
	}

	/**
	 * Returns the effective counter flag for a notification type in the given project.
	 * The stored override map (if any) takes precedence over the registry default.
	 */
	public function getEffectiveCounter(int $projectId, NotificationType $type): bool
	{
		$overrides = $this->getOverrideMap($projectId);
		$wireKey = $type->getWireKey();

		return $overrides[$wireKey] ?? $type->getDefaultCounter();
	}

	/**
	 * Returns effective counter flags for all notification types in the given project.
	 *
	 * @return array<string, bool> wire-key => effective counter flag
	 */
	public function getEffectiveCounterMap(int $projectId): array
	{
		$overrides = $this->getOverrideMap($projectId);

		$result = [];
		foreach (NotificationType::cases() as $type)
		{
			$wireKey = $type->getWireKey();
			$result[$wireKey] = $overrides[$wireKey] ?? $type->getDefaultCounter();
		}

		return $result;
	}

	/**
	 * Builds the catalog for an existing project.
	 * Returns null when the project is not available (i.e. not a converted project).
	 */
	public function buildCatalog(int $projectId): ?NotificationCatalogResponse
	{
		if (!$this->availabilityGate->isAvailable($projectId))
		{
			return null;
		}

		$groups = $this->buildGroups($projectId);

		return new NotificationCatalogResponse($groups);
	}

	/**
	 * Builds the default catalog (no project overrides) for the project create-form.
	 * New projects are always NotRequired, so the catalog is always present.
	 */
	public function buildDefaultCatalog(): NotificationCatalogResponse
	{
		$groups = $this->buildGroups(null);

		return new NotificationCatalogResponse($groups);
	}

	/**
	 * Saves notification counter settings for a project.
	 *
	 * Gate-checked: if the project is not available (not a converted project) → no-op.
	 * Reduces $notifications to diffs from registry defaults; unknown ids are discarded.
	 * Always writes the reduced map (even empty) so resetting to defaults clears prior overrides.
	 *
	 * @param list<array{id: string, counterEnabled: bool}> $notifications
	 */
	public function save(int $projectId, array $notifications): void
	{
		if (!$this->availabilityGate->isAvailable($projectId))
		{
			return;
		}

		$overrides = [];
		foreach ($notifications as $typeData)
		{
			$id = (string)($typeData['id'] ?? '');
			$counterEnabled = $typeData['counterEnabled'] ?? null;

			$type = NotificationType::tryFrom($id);
			if ($type === null || !is_bool($counterEnabled))
			{
				continue;
			}

			// Only store entries that differ from the registry default
			if ($counterEnabled !== $type->getDefaultCounter())
			{
				$overrides[$type->getWireKey()] = $counterEnabled;
			}
		}

		$this->collabOptionRepository->setOption(
			collabId: $projectId,
			optionName: NotificationCounterOption::DB_NAME,
			value: $this->codec->encode($overrides),
		);
	}

	/**
	 * Returns the decoded override map for the project, or an empty array if not set.
	 *
	 * @return array<string, bool> wire-key => counter override flag
	 */
	private function getOverrideMap(int $projectId): array
	{
		if ($projectId <= 0)
		{
			return [];
		}

		$options = $this->collabOptionRepository->getRawOptions(
			collabId: $projectId,
			optionNames: [NotificationCounterOption::DB_NAME],
		);

		$rawValue = $options[NotificationCounterOption::DB_NAME] ?? '';

		return $this->codec->decode($rawValue);
	}

	/**
	 * @return list<array{id: string, label: string, types: list<array{id: string, label: string, counterEnabled: bool}>}>
	 */
	private function buildGroups(?int $projectId): array
	{
		$groups = [];

		$effectiveCounterMap = $projectId !== null
			? $this->getEffectiveCounterMap($projectId)
			: [];

		foreach (NotificationGroup::cases() as $group)
		{
			$types = [];
			foreach (NotificationTypeRegistry::getByGroup($group) as $type)
			{
				$counterEnabled = $effectiveCounterMap[$type->getWireKey()] ?? $type->getDefaultCounter();

				$types[] = [
					'id' => $type->value,
					'label' => NotificationTypeRegistry::getTypeLabel($type),
					'counterEnabled' => $counterEnabled,
				];
			}

			$groups[] = [
				'id' => $group->value,
				'label' => NotificationTypeRegistry::getGroupLabel($group),
				'types' => $types,
			];
		}

		return $groups;
	}
}
