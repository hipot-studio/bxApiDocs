<?php

namespace Bitrix\Crm\Filter\FieldsTransform;

final class LeadClosedField
{
	public static function transform(mixed $value): array
	{
		return match ($value)
		{
			'N', false => ['=STATUS_SEMANTIC_ID' => 'P'],
			'Y', true => ['!STATUS_SEMANTIC_ID' => 'P'],
			default => [],
		};
	}
}
