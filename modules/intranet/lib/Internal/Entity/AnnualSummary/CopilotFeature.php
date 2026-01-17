<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\AnnualSummary;

class CopilotFeature extends Feature
{
	public function __construct(int $count)
	{
		parent::__construct(
			FeatureType::Copilot,
			$count,
			5,
			300,
			parent::generateRandomVariation(3),
			parent::generateCountVariation([5, 50], $count)
		);
	}
}

