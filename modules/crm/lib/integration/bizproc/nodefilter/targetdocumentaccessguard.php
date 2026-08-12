<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\NodeFilter;

use Bitrix\Crm\Service\Container;
use CCrmBizProcHelper;

final class TargetDocumentAccessGuard implements \Bitrix\Bizproc\Public\Activity\Interface\TargetDocumentAccessGuard
{
	public function canUpdate(array $rootDocumentId, array $resolvedDocumentId, int $actorId): ?bool
	{
		return $this->check($rootDocumentId, $resolvedDocumentId, $actorId, 'canUpdate');
	}

	public function canRead(array $rootDocumentId, array $resolvedDocumentId, int $actorId): ?bool
	{
		return $this->check($rootDocumentId, $resolvedDocumentId, $actorId, 'canRead');
	}

	public function canDelete(array $rootDocumentId, array $resolvedDocumentId, int $actorId): ?bool
	{
		return $this->check($rootDocumentId, $resolvedDocumentId, $actorId, 'canDelete');
	}

	private function check(
		array $rootDocumentId,
		array $resolvedDocumentId,
		int $actorId,
		string $itemMethod,
	): ?bool
	{
		if ($rootDocumentId === $resolvedDocumentId)
		{
			return null;
		}

		[$entityTypeId, $entityId] = CCrmBizProcHelper::resolveEntityId($resolvedDocumentId);
		$entityTypeId = (int)$entityTypeId;
		$entityId = (int)$entityId;
		if ($entityTypeId <= 0 || $entityId <= 0)
		{
			return false;
		}

		$itemPermissions = Container::getInstance()->getUserPermissions($actorId)->item();

		return (bool)$itemPermissions->{$itemMethod}($entityTypeId, $entityId);
	}
}
