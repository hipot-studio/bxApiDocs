<?php

namespace Bitrix\ImOpenLines\V2\Controller;

use Bitrix\Im;
use Bitrix\ImOpenLines\V2\Session\Session;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;

Loader::requireModule('im');

abstract class BaseController extends Im\V2\Controller\BaseController
{
	public function getAutoWiredParameters()
	{
		return array_merge([
			new ExactParameter(
				Session::class,
				'session',
				function ($className, int $sessionId) {
					$session = Session::getInstance($sessionId);
					if ($session === null || $session->getSessionId() === null)
					{
						$this->addError(new Error('Session not found', 'SESSION_NOT_FOUND'));

						return null;
					}

					return $session;
				}
			),
		], parent::getAutoWiredParameters());
	}

	protected function getDefaultPreFilters()
	{
		return array_merge(
			[
				new \Bitrix\Intranet\ActionFilter\IntranetUser(),
			],
			parent::getDefaultPreFilters(),
		);
	}
}
