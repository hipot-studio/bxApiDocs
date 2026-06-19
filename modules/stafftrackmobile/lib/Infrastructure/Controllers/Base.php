<?php

namespace Bitrix\StaffTrackMobile\Infrastructure\Controllers;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;

class Base extends Controller
{
	protected function init(): void
	{
		parent::init();

		define('BX_MOBILE', true);
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
