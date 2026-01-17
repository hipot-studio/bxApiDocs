<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\AnnualSummary;

class SiteFeature extends Feature
{
	public function __construct(int $count)
	{
		parent::__construct(
			FeatureType::Site,
			$count,
			2,
			25,
			parent::generateRandomVariation(3),
			parent::generateCountVariation([2, 10], $count),
		);
	}
}
