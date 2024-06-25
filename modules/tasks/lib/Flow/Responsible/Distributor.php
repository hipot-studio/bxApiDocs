<?php

namespace Bitrix\Tasks\Flow\Responsible;

use Bitrix\Tasks\Flow\Flow;
use Bitrix\Tasks\Flow\Responsible\Distributor\DistributorStrategyInterface;
use Bitrix\Tasks\Flow\Responsible\Distributor\ManualDistributorStrategy;
use Bitrix\Tasks\Flow\Responsible\Distributor\NullDistributorStrategy;
use Bitrix\Tasks\Flow\Responsible\Distributor\QueueDistributorStrategy;

class Distributor
{
	public function generateResponsible(Flow $flow): Responsible
	{
		$strategy = $this->getDistributorStrategy($flow);
		$responsibleId = $strategy->distribute($flow);

		return new Responsible($responsibleId, $flow->getId());
	}

	private function getDistributorStrategy(Flow $flow): DistributorStrategyInterface
	{
		return match ($flow->getDistributionType())
		{
			Flow::DISTRIBUTION_TYPE_MANUALLY => new ManualDistributorStrategy(),
			Flow::DISTRIBUTION_TYPE_QUEUE => new QueueDistributorStrategy(),
			default => new NullDistributorStrategy(),
		};
	}
}
