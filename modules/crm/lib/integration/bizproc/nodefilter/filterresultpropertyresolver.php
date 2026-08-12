<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\NodeFilter;

final class FilterResultPropertyResolver implements \Bitrix\Bizproc\Public\Activity\Interface\FilterResultPropertyResolver
{
	public function supports(\CBPActivity $activity): bool
	{
		$documentType = $activity instanceof \Bitrix\Bizproc\Public\Activity\Interface\FixedDocumentComplexActivity
			? $activity::getDocumentTypeForNodeAction()
			: $activity->getDocumentType()
		;

		return ($documentType[0] ?? null) === 'crm';
	}

	public function resolveProperties(\CBPActivity $activity): array
	{
		return Resolver::resolveDocuments($activity);
	}
}
