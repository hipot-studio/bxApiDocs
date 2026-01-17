<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\AnnualSummary;

class TaskFeature extends Feature
{
	public function __construct(int $count)
	{
		parent::__construct(
			FeatureType::Task,
			$count,
			10,
			1800,
			parent::generateRandomVariation(3),
			parent::generateCountVariation([10, 100, 500, 1000], $count),
		);
	}
}