<?php

namespace Bitrix\Sign\Service\Placeholder\Restriction\Rule;

use Bitrix\Sign\Service\Placeholder\Restriction\ParsedField;
use Bitrix\Sign\Service\Placeholder\Restriction\RestrictionPolicy;
use Bitrix\Sign\Service\Placeholder\Restriction\RestrictionReason;
use Bitrix\Sign\Service\Placeholder\Restriction\RestrictionRule;
use Bitrix\Sign\Service\Placeholder\Restriction\ScenarioContext;
use Bitrix\Sign\Type\BlockCode;
use Bitrix\Sign\Type\BlockParty;

/**
 * A mass document is signed by many employees, so personal data of one of them must not leak
 * into the shared file: profile, dynamic and 1C fields of the signer are forbidden there.
 *
 * The only rule depending on the mass axis: while the axis is unresolved it restricts nothing
 * under PERMISSIVE (placeholder list) and restricts under STRICT (send warning).
 */
class MassPersonalFieldRule implements RestrictionRule
{
	public function getReason(
		ParsedField $field,
		ScenarioContext $context,
		RestrictionPolicy $policy,
	): ?RestrictionReason
	{
		if ($field->party !== BlockParty::LAST_PARTY || !$this->isPersonalBlock($field->blockCode))
		{
			return null;
		}

		if (!$this->isTreatedAsMassDocument($context, $policy))
		{
			return null;
		}

		return RestrictionReason::SCENARIO_FORBIDDEN;
	}

	public function isPersonalBlock(string $blockCode): bool
	{
		return $blockCode === BlockCode::B2E_REFERENCE
			|| BlockCode::isMemberDynamic($blockCode)
			|| BlockCode::isHcmLinkReference($blockCode)
		;
	}

	/**
	 * The verdict of the mass axis this rule acts on, for a caller that has to normalise the axis the
	 * same way: two contexts differing only in an unresolved and a resolved-to-false axis give one and
	 * the same filtered list under PERMISSIVE, so they must give one and the same cache key as well.
	 */
	public function isTreatedAsMassDocument(ScenarioContext $context, RestrictionPolicy $policy): bool
	{
		if ($context->isMassAxisResolved())
		{
			return $context->isMassDocument === true;
		}

		return $policy === RestrictionPolicy::STRICT;
	}
}
