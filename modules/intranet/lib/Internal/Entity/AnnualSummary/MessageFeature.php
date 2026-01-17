<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\AnnualSummary;

class MessageFeature extends Feature
{
	public function __construct(int $count)
	{
		// min/max from MessageProvider: 130, 7500
		parent::__construct(
			FeatureType::Message,
			$count,
			130,
			7500,
			parent::generateRandomVariation(3),
			parent::generateCountVariation([130, 1000, 3000, 5000], $count),
		);
	}
}
