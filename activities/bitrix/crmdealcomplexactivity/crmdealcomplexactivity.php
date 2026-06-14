<?php

declare(strict_types=1);

use Bitrix\Bizproc\Internal\Entity\Activity\Interface\FixedDocumentComplexActivity;
use Bitrix\Bizproc\Public\Activity\BaseComplexActivity;
use Bitrix\Main\Loader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPCrmDealComplexActivity extends BaseComplexActivity implements FixedDocumentComplexActivity
{
	public static function getDocumentTypeForNodeAction(): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		return CCrmBizProcHelper::ResolveDocumentType(CCrmOwnerType::Deal);
	}
}
