<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Portal;

use Bitrix\Main;
use Bitrix\Rest\Internal\Integration;

class License
{
	private array $data;

	public function __construct()
	{
		$this->data =
			Main\Loader::includeModule('bitrix24')
				? (new Integration\Bitrix24\PortalLicenseProvider())->getLicenseInfo()
				: (new Integration\Main\PortalLicenseProvider())->getLicenseInfo()
		;
	}

	public function toArray(): array
	{
		return $this->data;
	}
}
