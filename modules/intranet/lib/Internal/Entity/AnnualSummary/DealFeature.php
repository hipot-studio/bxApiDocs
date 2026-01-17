<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\AnnualSummary;

class DealFeature extends Feature
{
	public function __construct(int $count)
	{
		// min/max from DealProvider: 20, 3000
		parent::__construct(
			FeatureType::Deal,
			$count,
			20,
			3000,
			parent::generateRandomVariation(3),
			parent::generateCountVariation([20, 200, 700, 1000], $count),
		);
	}
}
