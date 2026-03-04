<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Flow\Migration\Exclusion\Service\Responsible;

use Bitrix\Tasks\Flow\Migration\Exclusion\Service\AbstractExclusionService;
use Bitrix\Tasks\Flow\Migration\Strategy\Type\ExcludeNonActive;
use Bitrix\Tasks\Flow\Migration\Strategy\Type\MigrateToManual\SwitchToManualDistribution;

class BaseResponsibleExclusionService extends AbstractExclusionService
{
	protected function getStrategySequence(): array
	{
		return [
			new ExcludeNonActive(),
			new SwitchToManualDistribution(),
		];
	}
}
