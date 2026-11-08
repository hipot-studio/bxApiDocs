<?php

namespace Bitrix\Sign\Service\Placeholder\Restriction\Rule;

use Bitrix\Sign\Service\Placeholder\Restriction\ParsedField;
use Bitrix\Sign\Service\Placeholder\Restriction\RestrictionPolicy;
use Bitrix\Sign\Service\Placeholder\Restriction\RestrictionReason;
use Bitrix\Sign\Service\Placeholder\Restriction\RestrictionRule;
use Bitrix\Sign\Service\Placeholder\Restriction\ScenarioContext;
use Bitrix\Sign\Type\BlockCode;

/**
 * 1C (HCM link) fields require a chosen company: without it the value has no source.
 */
class HcmLinkReferenceRule implements RestrictionRule
{
	public function getReason(
		ParsedField $field,
		ScenarioContext $context,
		RestrictionPolicy $policy,
	): ?RestrictionReason
	{
		if (!BlockCode::isHcmLinkReference($field->blockCode))
		{
			return null;
		}

		if ($context->hcmLinkCompanyId !== null)
		{
			return null;
		}

		return RestrictionReason::SCENARIO_FORBIDDEN;
	}
}
