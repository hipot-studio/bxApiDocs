<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Row\Action;

use Bitrix\Main\Grid\Row\Action\BaseAction;
use Bitrix\Main\Grid\Row\Action\DataProvider;

use Bitrix\Bizproc\Internal\Grid\AiAgents\Settings\AiAgentsSettings;

/**
 * @method AiAgentsSettings getSettings()
 */
class AiAgentsDataProvider extends DataProvider
{
	/**
	 * @return BaseAction[]
	 */
	public function prepareActions(): array
	{
		return [
			new RestartAction($this->getSettings()),
			new EditAction($this->getSettings()),
			new DeleteAction($this->getSettings()),
		];
	}
}