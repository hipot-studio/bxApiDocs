<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Interface;

interface TargetDocumentAccessGuard
{
	public function canUpdate(array $rootDocumentId, array $resolvedDocumentId, int $actorId): ?bool;

	public function canRead(array $rootDocumentId, array $resolvedDocumentId, int $actorId): ?bool;

	public function canDelete(array $rootDocumentId, array $resolvedDocumentId, int $actorId): ?bool;
}
