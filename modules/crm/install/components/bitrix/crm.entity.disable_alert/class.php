<?php

use Bitrix\Crm\Component\DisableHelpers\BaseDisableHelper;
use Bitrix\Crm\Component\DisableHelpers\OldEntityViewDisableHelper;
use Bitrix\Crm\Component\DisableHelpers\OldInvoiceReadonlyHelper;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CrmEntityDisableAlert extends CBitrixComponent
{
	private BaseDisableHelper $disableHelper;

	public function executeComponent(): void
	{
		if (!$this->checkPermissions())
		{
			return;
		}

		$this->initDisableHelper();
		$this->prepareParams();

		$this->includeComponentTemplate();
	}

	private function checkPermissions(): bool
	{
		return Bitrix\Crm\Service\Container::getInstance()
			->getUserPermissions()
			->item()
			->canRead($this->arParams['ENTITY_TYPE_ID'], $this->arParams['ENTITY_ID'])
		;
	}

	private function initDisableHelper(): void
	{
		$disableHelperClass = $this->arParams['DISABLE_HELPER_CLASS'] ?? OldEntityViewDisableHelper::class;

		$this->disableHelper = match ($disableHelperClass)
		{
			OldEntityViewDisableHelper::class => new OldEntityViewDisableHelper(),
			OldInvoiceReadonlyHelper::class => new OldInvoiceReadonlyHelper(),
			default => throw new \InvalidArgumentException('Unsupported DISABLE_HELPER_CLASS value'),
		};
	}

	private function prepareParams(): void
	{
		$this->arResult['jsParams'] = $this->disableHelper->getJsParams([
			'ENTITY_TYPE_ID' => $this->arParams['ENTITY_TYPE_ID'],
			'ENTITY_ID' => $this->arParams['ENTITY_ID'],
		]);

		$this->arResult['canShowAlert'] = $this->disableHelper->canShowAlert();
	}
}
