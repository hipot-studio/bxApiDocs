<?php
declare(strict_types=1);

use Bitrix\Bizproc\Internal\Entity\Activity\Interface\FixedDocumentComplexActivity;
use Bitrix\Bizproc\Public\Activity\BaseComplexActivity;
use Bitrix\Crm\Integration\BizProc\Document\Dynamic;
use Bitrix\Main\Loader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPCrmDynamicComplexActivity extends BaseComplexActivity implements FixedDocumentComplexActivity
{
	public static function getDocumentTypeForNodeAction(): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		return ['crm', Dynamic::class];
	}
}