<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\AnnualSummary;

class BoardFeature extends Feature
{
	public function __construct(int $count)
	{
		parent::__construct(
			FeatureType::Board,
			$count,
			1,
			10,
			parent::generateRandomVariation(2),
			parent::generateCountVariation([1, 5], $count)
		);
	}
}

