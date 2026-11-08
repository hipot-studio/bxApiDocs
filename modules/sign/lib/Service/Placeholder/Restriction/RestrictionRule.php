<?php

namespace Bitrix\Sign\Service\Placeholder\Restriction;

interface RestrictionRule
{
	/**
	 * Returns why the field is not supported in the given scenario, or null when the rule
	 * does not restrict it. Rules must stay deterministic: no storage, no external services.
	 */
	public function getReason(
		ParsedField $field,
		ScenarioContext $context,
		RestrictionPolicy $policy,
	): ?RestrictionReason;
}
