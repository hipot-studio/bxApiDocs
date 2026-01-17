<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\AnnualSummary;

class ChannelFeature extends Feature
{
	public function __construct(int $count)
	{
		parent::__construct(
			FeatureType::Channel,
			$count,
			2,
			8,
			parent::generateRandomVariation(2),
			parent::generateCountVariation([2, 4], $count)
		);
	}
}

