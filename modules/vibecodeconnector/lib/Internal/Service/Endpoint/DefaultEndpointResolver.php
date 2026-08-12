<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Endpoint;

use Bitrix\Main\Application;

final class DefaultEndpointResolver
{
	private const TECH_URL = 'https://vibecode.bitrix24.tech';
	private const COM_URL = 'https://vibecode.bitrix24.com';

	public function resolve(): string
	{
		$license = Application::getInstance()->getLicense();

		if ($license->getRegion() === null || $license->isCis())
		{
			return self::TECH_URL;
		}

		return self::COM_URL;
	}

	/**
	 * @return string[]
	 */
	public function getAvailableUrls(): array
	{
		return [
			self::TECH_URL,
			self::COM_URL,
		];
	}
}
