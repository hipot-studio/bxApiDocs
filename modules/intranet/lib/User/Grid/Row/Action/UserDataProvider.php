<?php

namespace Bitrix\Intranet\User\Grid\Row\Action;

use Bitrix\Intranet\User\Grid\Settings\UserSettings;
use Bitrix\Main\Grid\Row\Action\DataProvider;
use Bitrix\Intranet\User\Grid\Row\Action;
use Bitrix\Main\Loader;

/**
 * @method UserSettings getSettings()
 */
class UserDataProvider extends DataProvider
{
	public function prepareActions(): array
	{
		$result = [
			new Action\ConfirmAction($this->getSettings()),
			new Action\DeclineAction($this->getSettings()),
			new Action\OpenProfileAction(),
			new Action\FireAction($this->getSettings()),
			new Action\DeleteAction($this->getSettings()),
			new Action\RestoreAction($this->getSettings()),
		];

		if (Loader::includeModule('tasks'))
		{
			$result[] = new Action\AddTaskAction();
		}

		if (Loader::includeModule('im'))
		{
			$result[] = new Action\MessageAction();
		}

		return $result;
	}
}