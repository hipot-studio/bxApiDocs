<?php

namespace Bitrix\Baas\Service;


class LocalService
{
	protected function __construct()
	{
		BillingSynchronizationService::getInstance()->syncIfNeeded();
	}
}
