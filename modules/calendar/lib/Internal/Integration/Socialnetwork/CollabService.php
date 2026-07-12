<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Integration\Socialnetwork;

use Bitrix\Calendar\Integration\SocialNetwork\Collab\Entity\SectionEntityHelper;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Public\Provider\ProjectProvider;

class CollabService
{
	/**
	 * All three methods perform lazy backfill: if HAS_COLLABERS is not yet stored
	 * for a project (e.g. legacy collab created before HasCollabersHandler was
	 * registered), it is recomputed from current membership and persisted on the
	 * first read. Subsequent reads come from the option row.
	 */
	public function hasCollabers(int $id): bool
	{
		$map = $this->getHasCollabersMap([$id]);

		return $map[$id] ?? false;
	}

	/**
	 * @param int[] $projectIds
	 *
	 * @return array<int, bool>
	 */
	public function getHasCollabersMap(array $projectIds): array
	{
		if ($projectIds === [] || !$this->isAvailable())
		{
			return [];
		}

		return ServiceLocator::getInstance()
			->get(ProjectProvider::class)
			->ensureHasCollabersMap($projectIds)
		;
	}

	public function isSectionProjectHasCollabers(int $sectionId): bool
	{
		if (!$this->isAvailable())
		{
			return false;
		}

		$collabSectionEntity = SectionEntityHelper::getIfCollab($sectionId);
		$collabId = $collabSectionEntity?->getCollab()?->getId();

		if (!$collabId)
		{
			return false;
		}

		return $this->hasCollabers($collabId);
	}

	private function isAvailable(): bool
	{
		return Loader::includeModule('socialnetwork');
	}
}
