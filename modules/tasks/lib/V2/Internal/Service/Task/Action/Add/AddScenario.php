<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Trait\ConfigTrait;

class AddScenario
{
	use ConfigTrait;

	public function __invoke(array $fields): void
	{
		$taskId = $fields['ID'];

		$scenarioService = Container::getInstance()->getScenarioService();

		if (empty($fields['SCENARIO_NAME']))
		{
			$scenarioService->saveDefault($taskId);

			return;
		}

		$scenarios =
			is_array($fields['SCENARIO_NAME'])
				? $fields['SCENARIO_NAME']
				: [$fields['SCENARIO_NAME']]
		;

		$scenarioService->save($taskId, $scenarios);
	}
}
