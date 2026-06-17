<?php

namespace Bitrix\Sign\Service\Integration\Disk;

use Bitrix\Disk\ProxyType\Base;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Main\Localization\Loc;

class ProxyType extends Base
{
	public function getSecurityContextByUser($user): SecurityContext
	{
		return new \Bitrix\Sign\Service\Integration\Disk\SecurityContext($user);
	}

	public function getStorageBaseUrl(): string
	{
		return '';
	}

	public function getEntityUrl(): string
	{
		return '';
	}

	public function getEntityTitle(): string
	{
		return Loc::getMessage('SIGN_INTEGRATION_DISK_ENTITY_TITLE');
	}

	public function getEntityImageSrc($width, $height): string
	{
		return '';
	}

	public function getTitle(): string
	{
		return $this->getEntityTitle();
	}
}
