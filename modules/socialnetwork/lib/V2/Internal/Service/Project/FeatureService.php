<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use Bitrix\Socialnetwork\Collab\Integration\Note\CollabKnowledgeService;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityType;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Feature;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\FeatureCollection;
use Bitrix\Socialnetwork\V2\Internal\Integration;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectFeatureRepository;

class FeatureService
{
	/** @var array<string, array{available: bool, collectionId: int|null, canView: bool, restriction: string|null}> */
	private array $knowledgeSectionCache = [];

	public function __construct(
		private readonly FeatureLinkBuilder $linkBuilder,
		private readonly ProjectFeatureRepository $featureRepository,
		private readonly Integration\Tasks\Flow\Service\Project $flowProjectService,
		private readonly Integration\Rest\Service\Placement $restPlacementService,
		private readonly Integration\Landing\Service\Project $landingProjectService,
		private readonly CollabKnowledgeService $collabKnowledgeService,
	)
	{
	}

	public function getAvailableFeatures(int $projectId, int $userId): FeatureCollection
	{
		$storedFeatureNames = $this->getStoredFeatureNames($projectId);
		$storedFeatureNames = $this->appendPersistedActiveFeatureNames($projectId, $storedFeatureNames);
		$specialFeatureIds = $this->getSpecialFeatureIds($projectId, $userId, $storedFeatureNames);
		if ($storedFeatureNames === [] && $specialFeatureIds === [])
		{
			return new FeatureCollection();
		}

		$placementHandlers = $this->getPlacementHandlers();
		$features = [];

		foreach ($this->getOrderedFeatureIds($storedFeatureNames, $specialFeatureIds, $placementHandlers) as $featureId)
		{
			$url = $this->linkBuilder->build($featureId, $projectId, $userId);

			$restrictionCode = $this->resolveRestrictionCode($featureId, $projectId, $userId);
			$isLocked = ($restrictionCode !== null);
			if ($isLocked)
			{
				$url = null;
			}

			if (
				($url === null || $url === '')
				&& !$isLocked
				&& !$this->shouldIncludeFeatureWithoutUrl($featureId)
			)
			{
				continue;
			}

			$name = $this->resolveDefaultName($featureId, $projectId, $placementHandlers);
			$customName = trim((string)($storedFeatureNames[$featureId] ?? ''));

			$features[] = new Feature(
				id: $featureId,
				name: $name,
				customName: $customName,
				title: ($customName !== '' ? $customName : $name),
				url: $url,
				urlTemplate: $this->linkBuilder->getUrlTemplate($featureId, $projectId, $userId),
				isLocked: $isLocked,
				restrictionCode: $restrictionCode,
			);
		}

		return new FeatureCollection(...$features);
	}

	private function shouldIncludeFeatureWithoutUrl(string $featureId): bool
	{
		// Note: the knowledge-base feature is always shown in the menu even before its
		// collection exists — provisioning is deferred to the first click (the front-end
		// resolves the section URL lazily via socialnetwork.collab.note.resolveSection).
		return in_array(
			$featureId,
			[
				FeatureDictionary::Chat->value,
				FeatureDictionary::Note->value,
			],
			true,
		);
	}

	private function resolveRestrictionCode(string $featureId, int $projectId, int $userId): ?string
	{
		if ($featureId === FeatureDictionary::LandingKnowledge->value)
		{
			return $this->landingProjectService->getKnowledgeRestrictionCode();
		}

		if ($featureId === FeatureDictionary::Note->value)
		{
			return $this->resolveKnowledgeSection($projectId, $userId)['restriction'];
		}

		return null;
	}

	public function getAllowedFeatures(): array
	{
		$allowedFeatureIds = $this->featureRepository->getAllowedFeatureIds();

		return array_fill_keys($allowedFeatureIds, true);
	}

	private function getOrderedFeatureIds(
		array $storedFeatureNames,
		array $specialFeatureIds,
		array $placementHandlers,
	): array
	{
		$orderedFeatureIds = [];

		foreach (FeatureDictionary::getRegularFeatureIds() as $featureId)
		{
			if (array_key_exists($featureId, $storedFeatureNames))
			{
				$orderedFeatureIds[] = $featureId;
			}
		}

		foreach (FeatureDictionary::getSpecialFeatureIds() as $featureId)
		{
			if (in_array($featureId, $specialFeatureIds, true))
			{
				$orderedFeatureIds[] = $featureId;
			}
		}

		foreach ($placementHandlers as $placementFeatureId => $handler)
		{
			if (array_key_exists($placementFeatureId, $storedFeatureNames))
			{
				$orderedFeatureIds[] = $placementFeatureId;
			}
		}

		return $orderedFeatureIds;
	}

	private function resolveDefaultName(string $featureId, int $projectId, array $placementHandlers): string
	{
		if ($featureId === FeatureDictionary::LandingKnowledge->value)
		{
			$name = FeatureDictionary::LandingKnowledge->getDefaultName();
			$title = $this->landingProjectService->getKnowledgeTitle($projectId);

			return ($title !== '') ? $name . ' - ' . $title : $name;
		}

		if (FeatureDictionary::isPlacementFeature($featureId))
		{
			$handler = $placementHandlers[$featureId] ?? null;
			$title = trim((string)($handler['TITLE'] ?? ''));
			if ($title !== '')
			{
				return $title;
			}

			$appName = trim((string)($handler['APP_NAME'] ?? ''));
			if ($appName !== '')
			{
				return $appName;
			}
		}

		return FeatureDictionary::getDefaultNameById($featureId);
	}

	private function getPlacementHandlers(): array
	{
		$result = [];

		$handlers = [];
		foreach (['SONET_GROUP_DETAIL_TAB', 'SONET_GROUP_TOOLBAR'] as $placementCode)
		{
			$handlers = array_merge($handlers, $this->restPlacementService->getHandlersList($placementCode));
		}

		foreach ($handlers as $handler)
		{
			$handlerId = (string)($handler['ID'] ?? '');
			if ($handlerId === '')
			{
				continue;
			}

			$result[FeatureDictionary::getPlacementPrefix() . $handlerId] = $handler;
		}

		return $result;
	}

	private function getStoredFeatureNames(int $projectId): array
	{
		return $this->featureRepository->getStoredFeatureNames($projectId);
	}

	private function appendPersistedActiveFeatureNames(int $projectId, array $storedFeatureNames): array
	{
		$regularFeatureIds = array_fill_keys(FeatureDictionary::getRegularFeatureIds(), true);
		foreach ($this->featureRepository->getFeatureStates($projectId) as $featureId => $isActive)
		{
			if (
				$isActive !== true
				|| !isset($regularFeatureIds[$featureId])
				|| array_key_exists($featureId, $storedFeatureNames)
			)
			{
				continue;
			}

			$storedFeatureNames[$featureId] = null;
		}

		return $storedFeatureNames;
	}

	private function getSpecialFeatureIds(int $projectId, int $userId, array $storedFeatureNames): array
	{
		$specialFeatureIds = [];

		if ($this->hasProjectFlows($projectId))
		{
			$specialFeatureIds[] = FeatureDictionary::Flows->value;
		}

		// Do not add the new collab knowledge base (note) when the legacy landing-based
		// knowledge base (landing_knowledge) is already active for this project — showing
		// both would duplicate the Knowledge Base entry in the project tools menu.
		$hasLegacyKnowledgeBase = array_key_exists(FeatureDictionary::LandingKnowledge->value, $storedFeatureNames);
		if (!$hasLegacyKnowledgeBase && $this->hasKnowledgeSection($projectId, $userId))
		{
			$specialFeatureIds[] = FeatureDictionary::Note->value;
		}

		return $specialFeatureIds;
	}

	private function hasProjectFlows(int $projectId): bool
	{
		return $this->flowProjectService->hasFlows($projectId);
	}

	private function hasKnowledgeSection(int $projectId, int $userId): bool
	{
		$section = $this->resolveKnowledgeSection($projectId, $userId);

		// Include the feature when the section is reachable (available)
		// or when it is locked behind a tariff (restriction !== null) so the
		// generic feature-menu upsell can be rendered.
		return $section['available'] === true || $section['restriction'] !== null;
	}

	/**
	 * @return array{available: bool, collectionId: int|null, canView: bool, restriction: string|null}
	 */
	private function resolveKnowledgeSection(int $projectId, int $userId): array
	{
		$cacheKey = $projectId . ':' . $userId;
		if (!isset($this->knowledgeSectionCache[$cacheKey]))
		{
			$this->knowledgeSectionCache[$cacheKey] = $this->collabKnowledgeService->resolveSection($projectId, $userId);
		}

		return $this->knowledgeSectionCache[$cacheKey];
	}
}
