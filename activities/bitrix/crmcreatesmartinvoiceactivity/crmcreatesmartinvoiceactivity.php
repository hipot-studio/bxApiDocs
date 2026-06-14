<?php
declare(strict_types=1);

use Bitrix\Main\Loader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!CBPRuntime::getRuntime()->includeActivityFile('CreateDocumentActivity'))
{
	return;
}

class CBPCrmCreateSmartInvoiceActivity extends CBPCreateDocumentActivity
{
	protected function getCreatedDocumentType(): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		return CCrmBizProcHelper::ResolveDocumentType(CCrmOwnerType::SmartInvoice);
	}

	public function getDocumentId(): array
	{
		return $this->getCreatedDocumentType();
	}
}
