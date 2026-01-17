<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete;

use Bitrix\Tasks\Internals\Task\Template\ScenarioTable;

class DeleteScenario
{
	public function __invoke(array $template): void
	{
		ScenarioTable::delete($template['ID']);
	}
}
