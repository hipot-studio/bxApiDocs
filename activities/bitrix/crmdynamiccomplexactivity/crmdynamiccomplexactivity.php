<?php
declare(strict_types=1);

use Bitrix\Bizproc\Public\Activity\Interface\FixedDocumentComplexActivity;
use Bitrix\Bizproc\Public\Activity\BaseComplexActivity;
use Bitrix\Crm\Integration\BizProc\Document\Dynamic;
use Bitrix\Crm\Service\Container;
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

		$entityTypeId = self::resolveAnyDynamicEntityTypeId();
		if ($entityTypeId > 0)
		{
			$documentType = \CCrmBizProcHelper::ResolveDocumentType($entityTypeId);
			if (is_array($documentType))
			{
				return $documentType;
			}
		}

		return ['crm', Dynamic::class, \CCrmOwnerType::DynamicTypePrefixName . '0'];
	}

	private static function resolveAnyDynamicEntityTypeId(): int
	{
		foreach (Container::getInstance()->getDynamicTypesMap()->getTypesCollection() as $type)
		{
			$entityTypeId = (int)$type->getEntityTypeId();
			if ($entityTypeId > 0 && \CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
			{
				return $entityTypeId;
			}
		}

		return 0;
	}
}
