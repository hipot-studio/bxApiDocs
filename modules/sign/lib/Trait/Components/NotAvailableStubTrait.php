<?php

namespace Bitrix\Sign\Trait\Components;

trait NotAvailableStubTrait
{
	protected const STUB_TYPE_NO_ACCESS = 'noAccess';
	protected const STUB_TYPE_NOT_AVAILABLE = 'notAvailable';

	protected function renderNotAvailableStub(
		?string $pageTitle = null,
		?string $stubTitle = null,
		?string $stubDesc = null,
		?string $stubType = self::STUB_TYPE_NOT_AVAILABLE,
	): void
	{
		global $APPLICATION;

		$params = [];
		if ($pageTitle !== null)
		{
			$params['PAGE_TITLE'] = $pageTitle;
		}
		if ($stubTitle !== null)
		{
			$params['STUB_TITLE'] = $stubTitle;
		}
		if ($stubDesc !== null)
		{
			$params['STUB_DESC'] = $stubDesc;
		}
		if ($stubType !== null)
		{
			$params['STUB_TYPE'] = $stubType;
		}

		$APPLICATION->IncludeComponent(
			'bitrix:sign.notavailable',
			'',
			$params,
			$this,
			['HIDE_ICONS' => 'Y'],
		);
	}
}
