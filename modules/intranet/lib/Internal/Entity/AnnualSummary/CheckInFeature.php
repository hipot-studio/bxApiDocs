<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\AnnualSummary;

class CheckInFeature extends Feature
{
	public function __construct(int $count)
	{
		parent::__construct(
			FeatureType::CheckIn,
			$count,
			10,
			170,
		);
	}
}

