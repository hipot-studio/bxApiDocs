<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add;

use Bitrix\Tasks\Internals\Task\Template\ScenarioTable;

class AddScenario
{
	public function __invoke(array $fields): void
	{
		if (empty($fields['SCENARIO_NAME']))
		{
			// set default scenario if none specified
			$fields['SCENARIO_NAME'] = ScenarioTable::SCENARIO_DEFAULT;
		}

		ScenarioTable::insertIgnore($fields['ID'], $fields['SCENARIO_NAME']);
	}
}
