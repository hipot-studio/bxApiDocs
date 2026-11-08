<?php

namespace Bitrix\Sign\Service\Placeholder\Restriction\Rule;

use Bitrix\Sign\Service\Placeholder\Restriction\ParsedField;
use Bitrix\Sign\Service\Placeholder\Restriction\RestrictionPolicy;
use Bitrix\Sign\Service\Placeholder\Restriction\RestrictionReason;
use Bitrix\Sign\Service\Placeholder\Restriction\RestrictionRule;
use Bitrix\Sign\Service\Placeholder\Restriction\ScenarioContext;
use Bitrix\Sign\Type\BlockCode;

/**
 * Employee-initiated documents do not offer regional external blocks while authoring. Strict
 * validation deals with codes already present in a blank, which the configure and send paths support.
 */
class EmployeeExternalBlockRule implements RestrictionRule
{
	public function getReason(
		ParsedField $field,
		ScenarioContext $context,
		RestrictionPolicy $policy,
	): ?RestrictionReason
	{
		if (
			$policy === RestrictionPolicy::STRICT
			|| !$this->isExternalBlockHidden($field->blockCode, $context)
		)
		{
			return null;
		}

		return RestrictionReason::SCENARIO_FORBIDDEN;
	}

	public function isExternalBlockHidden(string $blockCode, ScenarioContext $context): bool
	{
		return $context->isInitiatedByEmployee() && BlockCode::isB2eRegional($blockCode);
	}
}
