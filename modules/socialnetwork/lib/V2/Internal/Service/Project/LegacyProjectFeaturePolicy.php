<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use Bitrix\Socialnetwork\V2\Internal\Entity\RoleType;

class LegacyProjectFeaturePolicy
{
	public function getDisabledFeatures(): array
	{
		return array_fill_keys($this->getDisabledFeatureIds(), false);
	}

	public function getDisabledFeatureIds(): array
	{
		return [
			FeatureDictionary::Forum->value,
			FeatureDictionary::Wiki->value,
			FeatureDictionary::Photo->value,
			FeatureDictionary::GroupLists->value,
			FeatureDictionary::LandingKnowledge->value,
		];
	}

	public function getReadonlyPermissions(): array
	{
		return [
			FeatureDictionary::Forum->value => [
				'full' => RoleType::None->value,
				'newtopic' => RoleType::None->value,
				'answer' => RoleType::None->value,
			],
			FeatureDictionary::Wiki->value => [
				'write' => RoleType::None->value,
				'delete' => RoleType::None->value,
			],
			FeatureDictionary::Photo->value => [
				'write' => RoleType::None->value,
			],
			FeatureDictionary::GroupLists->value => [
				'write' => RoleType::None->value,
			],
		];
	}
}
