<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Integration\BizProc\NodeFilter;

use Bitrix\Tasks\Access\TaskAccessController;

final class TargetDocumentAccessGuard
{
	public static function checkResolvedTarget(
		array $rootDocumentId,
		array $resolvedDocumentId,
		string $action,
		int $actorId,
	): ?bool
	{
		if ($rootDocumentId === $resolvedDocumentId)
		{
			return null;
		}

		$taskId = (int)($resolvedDocumentId[2] ?? 0);
		if ($taskId <= 0)
		{
			return false;
		}

		return TaskAccessController::can($actorId, $action, $taskId);
	}
}
