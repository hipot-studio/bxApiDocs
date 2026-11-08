<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc;

use Bitrix\Bizproc\Public\Service\TestRun\TestRunService;
use Bitrix\Main\Loader;

final class TestRunEntity
{
	public static function getActiveIds(int $entityTypeId): array
	{
		$documentType = \CCrmBizProcHelper::ResolveDocumentType($entityTypeId);
		if (!$documentType || !self::isSupported('getActiveDocumentIds'))
		{
			return [];
		}

		$ids = [];
		foreach ((new TestRunService())->getActiveDocumentIds($documentType) as $documentId)
		{
			[, $entityId] = \CCrmBizProcHelper::resolveEntityIdByDocumentId($documentId);
			if ($entityId)
			{
				$ids[] = $entityId;
			}
		}

		return $ids;
	}

	public static function isTestRun(int $entityTypeId, int $entityId): bool
	{
		$documentId = \CCrmBizProcHelper::ResolveDocumentId($entityTypeId, $entityId);
		if (!$documentId || !self::isSupported('isTestRunDocument'))
		{
			return false;
		}

		return (new TestRunService())->isTestRunDocument($documentId);
	}

	private static function isSupported(string $method): bool
	{
		return Loader::includeModule('bizproc') && method_exists(TestRunService::class, $method);
	}
}
