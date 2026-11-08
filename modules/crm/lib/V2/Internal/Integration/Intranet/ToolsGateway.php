<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Intranet;

use Bitrix\Crm\Service\Container;

/**
 * The single point CRM V2 asks whether the portal keeps the business processes tool switched on.
 *
 * The answer belongs to the legacy CRM facade over the intranet module: it holds both the tool of
 * the question and the CRM availability behind it, and it includes the module in its own
 * constructor. That is why no loader call lives here - the gateway never reaches the module itself.
 *
 * @internal
 */
class ToolsGateway
{
	public function isBizprocToolAvailable(): bool
	{
		return $this->checkBizprocAvailability();
	}

	public function isRobotsToolAvailable(): bool
	{
		return Container::getInstance()->getIntranetToolsManager()->checkRobotsAvailability();
	}

	public function isIntranetAvailable(): bool
	{
		return \Bitrix\Main\Loader::includeModule('intranet');
	}

	public function isExternalMailAvailable(): bool
	{
		return \CIntranetUtils::IsExternalMailAvailable();
	}

	protected function checkBizprocAvailability(): bool
	{
		return Container::getInstance()->getIntranetToolsManager()->checkBizprocAvailability();
	}
}
