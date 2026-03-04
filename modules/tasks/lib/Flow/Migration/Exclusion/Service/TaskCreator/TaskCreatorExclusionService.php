<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Flow\Migration\Exclusion\Service\TaskCreator;

use Bitrix\Tasks\Flow\Migration\Exclusion\Service\AbstractExclusionService;
use Bitrix\Tasks\Flow\Migration\Strategy\Type\ChangeToOwnerOrAdmin;
use Bitrix\Tasks\Flow\Migration\Strategy\Type\ExcludeNonActive;

class TaskCreatorExclusionService extends AbstractExclusionService
{
	protected function getStrategySequence(): array
	{
		return [
			new ExcludeNonActive(),
			new ChangeToOwnerOrAdmin(),
		];
	}
}
