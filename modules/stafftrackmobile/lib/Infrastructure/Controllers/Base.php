<?php

namespace Bitrix\StaffTrackMobile\Infrastructure\Controllers;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;

class Base extends Controller
{
	protected function init(): void
	{
		parent::init();

		// init() runs per controller instance, and a process may build more than one
		if (!defined('BX_MOBILE'))
		{
			define('BX_MOBILE', true);
		}
	}

	protected function getDefaultPreFilters(): array
	{
		return [
			new ActionFilter\Authentication(),
			new ActionFilter\Csrf(),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			new ActionFilter\Scope(ActionFilter\Scope::NOT_REST),
		];
	}
}
