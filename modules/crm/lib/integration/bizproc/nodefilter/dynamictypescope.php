<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\NodeFilter;

enum DynamicTypeScope: string
{
	case Crm = 'crm';
	case AutomatedSolution = 'automatedSolution';

	public static function fromActivityType(?string $activityType): ?self
	{
		$normalizedType = strtolower(trim((string)$activityType));
		if (str_starts_with($normalizedType, 'cbp'))
		{
			$normalizedType = substr($normalizedType, 3);
		}

		return match ($normalizedType)
		{
			'crmsmartprocesscomplexactivity' => self::Crm,
			'crmautomatedsolutioncomplexactivity' => self::AutomatedSolution,
			default => null,
		};
	}
}
