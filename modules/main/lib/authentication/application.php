<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2014 Bitrix
 */

namespace Bitrix\Main\Authentication;

use Bitrix\Main;

class Application
{
	protected $validUrls = array();

	public function __construct()
	{
	}

	protected function isCheckRequestUri(): bool
	{
		return false;
	}

	/**
	 * Checks the valid scope for the applicaton.
	 *
	 * @return bool
	 */
	public function checkScope()
	{
		/** @var Main\HttpRequest $request */
		$request = Main\Context::getCurrent()->getRequest();

		$scriptFile = $request->getScriptFile();
		$requestUri = $request->getRequestUri();

		foreach ($this->validUrls as $url)
		{
			if (mb_strpos($scriptFile, $url) === 0)
			{
				return true;
			}

			if ($this->isCheckRequestUri() && mb_strpos($requestUri, $url) === 0)
			{
				return true;
			}
		}

		return false;
	}
}
