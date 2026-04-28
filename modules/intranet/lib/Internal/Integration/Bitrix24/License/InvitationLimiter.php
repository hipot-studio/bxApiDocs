<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Bitrix24\License;

use Bitrix\Bitrix24\License;
use Bitrix\Intranet\Internals\InvitationTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;

class InvitationLimiter
{
	protected bool $isModuleIncluded;

	public function __construct()
	{
		$this->isModuleIncluded = Loader::includeModule('bitrix24');
	}

	public function isExceeded(): bool
	{
		if (!$this->isModuleIncluded)
		{
			return false;
		}

		return
			in_array(License::getCurrent()->getLicensePrefix(), ['cn', 'en', 'vn', 'jp'], true)
			&& in_array(License::getCurrent()->getCode(), \CBitrix24::BASE_EDITIONS, true)
			&& InvitationTable::getCount(['>=DATE_CREATE' => new Date]) >= 10
		;
	}
}
