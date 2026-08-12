<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Main;

use Bitrix\Main\Application;
use Bitrix\Main\Service\MicroService\Client;

class MainPortalFieldsProvider
{
	public function getPortalType(): string
	{
		return Client::getPortalType();
	}

	public function getLicenseCode(): string
	{
		return Client::getLicenseCode();
	}

	public function getServerName(): string
	{
		return Client::getServerName();
	}

	public function getLicenseRegion(): string
	{
		return (string)Application::getInstance()->getLicense()->getRegion();
	}

	/**
	 * @param array<string, scalar|null> $fields
	 */
	public function sign(array $fields): string
	{
		return Client::signRequest($fields);
	}
}
