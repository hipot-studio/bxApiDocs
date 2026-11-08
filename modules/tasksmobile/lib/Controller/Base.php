<?php

namespace Bitrix\TasksMobile\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\TasksMobile\Cache\DeadlineCountersResetTrait;

class Base extends Controller
{
	use DeadlineCountersResetTrait;

	protected function init()
	{
		parent::init();

		define('BX_MOBILE', true);
	}

	public function configureActions(): array
	{
		$actions = [];

		foreach ($this->getQueryActionNames() as $queryActionName)
		{
			$actions[$queryActionName] = [
				'+prefilters' => [
					new CloseSession(),
				],
			];
		}

		return $actions;
	}

	/**
	 * Returns action names of query methods which should close session.
	 *
	 * @return array
	 */
	protected function getQueryActionNames(): array
	{
		return [];
	}

	/**
	 * Closing the session is the mark of an action that reads: it lets the session go exactly because it
	 * writes nothing. A controller that closes it for a mutation as well states its reads apart from those.
	 */
	protected function getReadActionNames(): array
	{
		return $this->getQueryActionNames();
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
