<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use Bitrix\Main\Config\Option;
use Bitrix\Socialnetwork\Collab\Internals\CollabOptionTable;
use Bitrix\Socialnetwork\FeaturePermTable;
use Bitrix\Socialnetwork\FeatureTable;
use Bitrix\Socialnetwork\Provider\FeatureProvider;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityType;
use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertStatus;
use Bitrix\Socialnetwork\V2\Internal\Entity\RoleType;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\ConvertProgressMapper;
use Bitrix\Socialnetwork\V2\Infrastructure\Agent\RestoreConvertedProjectListsPermissionAgent;
use Bitrix\Socialnetwork\WorkgroupTable;
use CSocNetFeatures;
use CSocNetFeaturesPerms;
use CSocNetAllowed;
use RuntimeException;

class ProjectFeatureWhitelistService
{
	public function __construct(
		private readonly FeatureProvider $featureProvider,
		private readonly ProjectCreateFeatures $projectCreateFeatures,
	)
	{
	}

	/**
	 * Brings a project to the feature set a newly created one has. What the request asks for goes through the very
	 * filter the create path uses, so a copy cannot end up holding a tool creating it would not have offered.
	 *
	 * @param array<string, mixed>|null $requestedFeatures
	 */
	public function syncWithCreateDefaults(int $projectId, ?array $requestedFeatures = null, ?int $sourceProjectId = null): void
	{
		$currentFeatures = $this->featureProvider->getFeatures($projectId);
		$featuresToSync = $this->projectCreateFeatures->getFeatures($requestedFeatures);

		foreach ($featuresToSync as $featureId => $isActive)
		{
			$currentFeature = $currentFeatures[$featureId] ?? null;
			if ($currentFeature !== null && $currentFeature->isActive === $isActive)
			{
				continue;
			}

			CSocNetFeatures::setFeature(
				EntityType::Group->value,
				$projectId,
				$featureId,
				$isActive,
				false,
				['isCollab' => true],
			);
		}

		if (
			$sourceProjectId !== null
			&& ($featuresToSync[FeatureDictionary::GroupLists->value] ?? false) === true
		)
		{
			$this->restoreCopiedListsWritePermission($sourceProjectId, $projectId);
		}
	}

	private function restoreCopiedListsWritePermission(int $sourceProjectId, int $projectId): void
	{
		$cutoff = (int)Option::get('socialnetwork', RestoreConvertedProjectListsPermissionAgent::CUTOFF_OPTION_NAME, '0');
		if ($cutoff <= 0)
		{
			return;
		}
		if (
			Option::get('socialnetwork', RestoreConvertedProjectListsPermissionAgent::COMPLETED_OPTION_NAME, '')
			=== RestoreConvertedProjectListsPermissionAgent::COMPLETED_OPTION_VALUE
		)
		{
			return;
		}

		$source = WorkgroupTable::getRow(['select' => ['DATE_CREATE'], 'filter' => ['=ID' => $sourceProjectId]]);
		if (($source['DATE_CREATE'] ?? null) === null || $source['DATE_CREATE']->getTimestamp() >= $cutoff)
		{
			return;
		}

		$options = CollabOptionTable::query()
			->setSelect(['NAME', 'VALUE'])
			->where('COLLAB_ID', $sourceProjectId)
			->whereIn('NAME', [
				ConvertProgressMapper::CONVERT_STATUS,
				RestoreConvertedProjectListsPermissionAgent::MARKER_OPTION_NAME,
				RestoreConvertedProjectListsPermissionAgent::NEW_CONVERSION_OPTION_NAME,
			])
			->fetchAll();
		$sourceOptions = array_column($options, 'VALUE', 'NAME');
		if (
			!in_array($sourceOptions[ConvertProgressMapper::CONVERT_STATUS] ?? null, [
				ConvertStatus::CompletedFromGroup->value,
				ConvertStatus::CompletedFromCollab->value,
			], true)
			|| isset($sourceOptions[RestoreConvertedProjectListsPermissionAgent::MARKER_OPTION_NAME])
			|| isset($sourceOptions[RestoreConvertedProjectListsPermissionAgent::NEW_CONVERSION_OPTION_NAME])
		)
		{
			return;
		}

		$permission = FeaturePermTable::query()
			->setSelect(['FEATURE_ID', 'ROLE'])
			->where('FEATURE.ENTITY_TYPE', FeatureTable::FEATURE_ENTITY_TYPE_GROUP)
			->where('FEATURE.ENTITY_ID', $projectId)
			->where('FEATURE.FEATURE', FeatureDictionary::GroupLists->value)
			->where('OPERATION_ID', 'write')
			->fetch();
		if (($permission['ROLE'] ?? null) !== RoleType::None->value)
		{
			return;
		}

		$allowed = CSocNetAllowed::GetAllowedFeatures();
		$role = $allowed[FeatureDictionary::GroupLists->value]['operations']['write'][FeatureTable::FEATURE_ENTITY_TYPE_GROUP]
			?? RoleType::Moderator->value;
		if (!CSocNetFeaturesPerms::SetPerm((int)$permission['FEATURE_ID'], 'write', $role))
		{
			throw new RuntimeException('Unable to restore lists write permission on copied project');
		}
	}
}
