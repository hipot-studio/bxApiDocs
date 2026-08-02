<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Integration\BizProc\NodeFilter;

use Bitrix\Bizproc\Public\Activity\Interface\FilterResultPropertyResolver as FilterResultPropertyResolverInterface;
use Bitrix\Bizproc\Public\Activity\Interface\FixedDocumentComplexActivity;

final class FilterResultPropertyResolver implements FilterResultPropertyResolverInterface
{
	public function supports(\CBPActivity $activity): bool
	{
		$documentType = $activity instanceof FixedDocumentComplexActivity
			? $activity::getDocumentTypeForNodeAction()
			: $activity->getDocumentType()
		;

		return ($documentType[0] ?? null) === 'tasks';
	}

	public function resolveProperties(\CBPActivity $activity): array
	{
		return Resolver::resolveDocuments($activity);
	}
}
