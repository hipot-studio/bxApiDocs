<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Panel\Action;

use Bitrix\Main\Grid\Panel\Action\GroupAction;
use Bitrix\Main\Grid\Panel\Action\DataProvider;

use Bitrix\Bizproc\Internal\Grid\AiAgents\Settings\AiAgentsSettings;

/**
 * @method AiAgentsSettings getSettings()
 */
class AiAgentsDataProvider extends DataProvider
{
	/**
	 * @return GroupAction[]
	 */
	public function prepareActions(): array
	{
		return [
			new AiAgentsGroupAction($this->getSettings()),
		];
	}
}
