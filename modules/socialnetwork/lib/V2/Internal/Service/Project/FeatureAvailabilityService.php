<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use Bitrix\Socialnetwork\Integration\Intranet\Settings;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Feature;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\FeatureCollection;
use Bitrix\Socialnetwork\V2\Internal\Integration;

class FeatureAvailabilityService
{
	public function __construct(
		private readonly Integration\Disk\Service\Project $diskProjectService,
		private readonly Integration\Photogallery\Service\Project $photogalleryProjectService,
		private readonly Integration\Lists\Service\Project $listsProjectService,
		private readonly Integration\Wiki\Service\Project $wikiProjectService,
		private readonly Integration\Rest\Service\Project $restProjectService,
		private readonly Integration\Landing\Service\Project $landingProjectService,
	)
	{
	}

	public function filterAvailableFeatures(
		FeatureCollection $features,
		int $projectId,
		int $userId,
		bool $isCurrentUserModuleAdmin,
	): FeatureCollection
	{
		$settings = new Settings();

		return $features->filter(
			fn (Feature $feature): bool => $this->isFeatureAvailable(
				featureId: (string)$feature->id,
				projectId: $projectId,
				userId: $userId,
				settings: $settings,
				isCurrentUserModuleAdmin: $isCurrentUserModuleAdmin,
			),
		);
	}

	private function isFeatureAvailable(
		string $featureId,
		int $projectId,
		int $userId,
		Settings $settings,
		bool $isCurrentUserModuleAdmin,
	): bool
	{
		return match ($featureId)
		{
			FeatureDictionary::Tasks->value => (
				$settings->isToolAvailable(Settings::TASKS_TOOLS['base_tasks'])
				&& $this->canViewFeature($projectId, $userId, $featureId, $isCurrentUserModuleAdmin)
			),
			FeatureDictionary::Calendar->value => (
				$settings->isToolAvailable(Settings::CALENDAR_TOOLS['calendar'])
				&& $this->canViewFeature($projectId, $userId, $featureId, $isCurrentUserModuleAdmin)
			),
			FeatureDictionary::Files->value => $this->diskProjectService->isAvailable(),
			FeatureDictionary::Photo->value => (
				$this->photogalleryProjectService->isAvailable()
				&& $this->canViewFeature($projectId, $userId, $featureId, $isCurrentUserModuleAdmin)
			),
			FeatureDictionary::Blog->value => $this->canViewFeature(
				projectId: $projectId,
				userId: $userId,
				featureId: $featureId,
				isCurrentUserModuleAdmin: $isCurrentUserModuleAdmin,
				operation: 'view_post',
			),
			FeatureDictionary::Forum->value => $this->canViewFeature(
				$projectId,
				$userId,
				$featureId,
				$isCurrentUserModuleAdmin,
			),
			FeatureDictionary::GroupLists->value => (
				$this->listsProjectService->isAvailable()
				&& $this->canViewFeature($projectId, $userId, $featureId, $isCurrentUserModuleAdmin)
			),
			FeatureDictionary::Wiki->value => (
				$this->wikiProjectService->isAvailable()
				&& $this->canViewFeature($projectId, $userId, $featureId, $isCurrentUserModuleAdmin)
			),
			FeatureDictionary::Marketplace->value => $this->restProjectService->isAvailable(),
			FeatureDictionary::LandingKnowledge->value => $this->landingProjectService->canReadKnowledge($projectId),
			default => true,
		};
	}

	private function canViewFeature(
		int $projectId,
		int $userId,
		string $featureId,
		bool $isCurrentUserModuleAdmin,
		string $operation = 'view',
	): bool
	{
		return \CSocNetFeaturesPerms::canPerformOperation(
			$userId,
			\SONET_ENTITY_GROUP,
			$projectId,
			$featureId,
			$operation,
			$isCurrentUserModuleAdmin,
		);
	}
}
