<?php

namespace Bitrix\Landing\Controller;

use Bitrix\Main\Engine;
use Bitrix\Main\Engine\Controller;
use Bitrix\Landing;
use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Data;
use Bitrix\Landing\Copilot\Connector;
use Bitrix\Main\Error;

class Vibe extends Controller
{
	public function getDefaultPreFilters(): array
	{
		return [
			new Engine\ActionFilter\Authentication(),
			new ActionFilter\Extranet(),
		];
	}

	/**
	 * Save relations between chat, site and user
	 * @param int $siteId
	 * @param int $chatId
	 * @return bool
	 */
	public function setDemoTestAction(): bool
	{
		return (bool)\CBitrix24::setLicenseType('demo');
	}
}