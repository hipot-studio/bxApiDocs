<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\AnnualSummary;

class WorkflowFeature extends Feature
{
	public function __construct(int $count)
	{
		parent::__construct(
			FeatureType::Workflow,
			$count,
			5,
			400,
			parent::generateRandomVariation(3),
			parent::generateCountVariation([5, 100, 400], $count),
		);
	}
}
