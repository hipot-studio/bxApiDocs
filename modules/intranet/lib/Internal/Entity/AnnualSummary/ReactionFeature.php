<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\AnnualSummary;

class ReactionFeature extends Feature
{
	public function __construct(int $count)
	{
		parent::__construct(
			FeatureType::Reaction,
			$count,
			20,
			2500,
			parent::generateRandomVariation(3),
			parent::generateCountVariation([20, 500, 1000, 2000], $count),
		);
	}
}
