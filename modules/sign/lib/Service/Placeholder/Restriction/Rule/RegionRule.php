<?php

namespace Bitrix\Sign\Service\Placeholder\Restriction\Rule;

use Bitrix\Sign\Service\Placeholder\Restriction\ParsedField;
use Bitrix\Sign\Service\Placeholder\Restriction\RestrictionPolicy;
use Bitrix\Sign\Service\Placeholder\Restriction\RestrictionReason;
use Bitrix\Sign\Service\Placeholder\Restriction\RestrictionRule;
use Bitrix\Sign\Service\Placeholder\Restriction\ScenarioContext;
use Bitrix\Sign\Type\FieldType;

/**
 * Regional fields (registration number, creation date) exist for the ru region only.
 */
class RegionRule implements RestrictionRule
{
	public const REGION_RU = 'ru';

	public function getReason(
		ParsedField $field,
		ScenarioContext $context,
		RestrictionPolicy $policy,
	): ?RestrictionReason
	{
		if (!FieldType::isRegional($field->fieldType))
		{
			return null;
		}

		if ($this->isRegionalFieldAvailable($context->region))
		{
			return null;
		}

		return RestrictionReason::REGION_UNAVAILABLE;
	}

	public function isRegionalFieldAvailable(string $region): bool
	{
		return $region === self::REGION_RU;
	}
}
