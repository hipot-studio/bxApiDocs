<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\AnnualSummary;

class CollabFeature extends Feature
{
	public function __construct(int $count)
	{
		parent::__construct(
			FeatureType::Collab,
			$count,
			2,
			12,
			parent::generateRandomVariation(3),
		);
	}
}

