<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access\Service;

/**
 * Per-feature read access of a project (b_sonet_features, b_sonet_features_perms).
 *
 * A subsystem of its own: passing the group access check (canRead) says nothing about
 * whether the user may open a particular feature of that project.
 *
 * @see \Bitrix\Socialnetwork\V2\Internal\Service\Project\FeatureAvailabilityService for the
 *      permission half of the same legacy check behind the project feature list
 */
class ProjectFeatureAccessService
{
	public function canViewFeature(int $userId, int $projectId, string $featureId): bool
	{
		if ($userId <= 0 || $projectId <= 0 || $featureId === '')
		{
			return false;
		}

		// A feature switched off for the project keeps no permission rows of its own, and the
		// operation check then falls back to the default role of the operation. Activity has to be
		// asked about separately, the way the project pages of the feature do.
		if (!\CSocNetFeatures::IsActiveFeature(\SONET_ENTITY_GROUP, $projectId, $featureId))
		{
			return false;
		}

		return \CSocNetFeaturesPerms::canPerformOperation(
			$userId,
			\SONET_ENTITY_GROUP,
			$projectId,
			$featureId,
			'view',
			\CSocNetUser::isUserModuleAdmin($userId),
		);
	}
}
