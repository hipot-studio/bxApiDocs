<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

final class ProjectDefaultFeatures
{
	public static function getFeatures(): array
	{
		return [
			FeatureDictionary::Blog->value => true,
			FeatureDictionary::Tasks->value => true,
			FeatureDictionary::Chat->value => true,
			FeatureDictionary::Calendar->value => true,
			FeatureDictionary::Files->value => true,
			FeatureDictionary::LandingKnowledge->value => false,
			FeatureDictionary::Note->value => false,
			FeatureDictionary::Marketplace->value => true,
		];
	}
}
