<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Integration\BizProc\NodeFilter;

use Bitrix\Bizproc\Public\Activity\Interface\FixedDocumentComplexActivity;
use Bitrix\Bizproc\Public\Activity\Interface\TargetDocumentResolver as TargetDocumentResolverInterface;
use Bitrix\Tasks\Integration\Bizproc\Document\Task;

final class TargetDocumentResolver implements TargetDocumentResolverInterface
{
	public function supports(\CBPActivity $activity): bool
	{
		return (self::resolveExpectedDocumentType($activity)[0] ?? null) === 'tasks';
	}

	public function resolveDocumentId(\CBPActivity $activity): array
	{
		$documentId = Resolver::resolveDocumentId($activity);

		return is_array($documentId) ? $documentId : self::resolveFallbackDocumentId($activity);
	}

	public function resolveDocumentType(array $documentId, array $fallbackDocumentType): array
	{
		if (($documentId[0] ?? null) === 'tasks')
		{
			return ['tasks', Task::class, 'TASK'];
		}

		return $fallbackDocumentType;
	}

	private static function resolveExpectedDocumentType(\CBPActivity $activity): array
	{
		$parent = $activity->parent ?? null;
		if ($parent && is_subclass_of($parent, FixedDocumentComplexActivity::class))
		{
			return $parent::getDocumentTypeForNodeAction();
		}

		return $activity->getDocumentType();
	}

	private static function resolveFallbackDocumentId(\CBPActivity $activity): array
	{
		$documentId = $activity->getDocumentId();
		if (is_array($documentId))
		{
			return $documentId;
		}

		$rootDocumentId = $activity->getRootActivity()->getDocumentId();

		return is_array($rootDocumentId) ? $rootDocumentId : [];
	}
}
