<?php

namespace Bitrix\Intranet\Infrastructure\Controller\ActionFilter;

use Bitrix\Main;
use Bitrix\Main\Engine;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;

class RequiredModule extends Engine\ActionFilter\Base
{
	public function __construct(
		private readonly array $modules,
	)
	{
		parent::__construct();
	}

	public function onBeforeAction(Event $event)
	{
		foreach ($this->modules as $module)
		{
			if (!Loader::includeModule($module))
			{
				$this->addError(new Error("module $module is not installed"));

				return new EventResult(EventResult::ERROR, null, null, $this);
			}
		}

		return null;
	}
}
