<?php

namespace Bitrix\Sign\Service\Placeholder\Restriction\Rule;

use Bitrix\Sign\Service\Placeholder\Restriction\ParsedField;
use Bitrix\Sign\Service\Placeholder\Restriction\RestrictionPolicy;
use Bitrix\Sign\Service\Placeholder\Restriction\RestrictionReason;
use Bitrix\Sign\Service\Placeholder\Restriction\RestrictionRule;
use Bitrix\Sign\Service\Placeholder\Restriction\ScenarioContext;
use Bitrix\Sign\Type\BlockParty;
use Bitrix\Sign\Type\FieldType;
use Bitrix\Sign\Type\Member\Role;

/**
 * Employee-initiated documents cannot fill address and url user fields of the signer.
 *
 * Owns the type set: Factory\Field asks this rule through the facade instead of keeping its own copy.
 * Judges by the raw field type, never by the one the placeholder name carries: the name is built from
 * the converted type, and the conversion turns a url into a plain string.
 */
class EmployeeUnsupportedTypeRule implements RestrictionRule
{
	public const UNSUPPORTED_TYPES = [
		FieldType::ADDRESS,
		FieldType::URL,
	];

	public function getReason(
		ParsedField $field,
		ScenarioContext $context,
		RestrictionPolicy $policy,
	): ?RestrictionReason
	{
		if (!$context->isInitiatedByEmployee() || $field->party !== BlockParty::LAST_PARTY)
		{
			return null;
		}

		if (!$this->isUnsupportedType($field->rawFieldType))
		{
			return null;
		}

		return RestrictionReason::TYPE_UNSUPPORTED;
	}

	/**
	 * Same verdict as getReason(), for the configure path, which knows the member role instead of the
	 * party of a parsed field name and holds the field type as the user field still declares it.
	 */
	public function isUnsupportedForRole(
		string $fieldType,
		?string $memberRole,
		ScenarioContext $context,
	): bool
	{
		return $memberRole === Role::SIGNER
			&& $context->isInitiatedByEmployee()
			&& $this->isUnsupportedType($fieldType);
	}

	public function isUnsupportedType(string $fieldType): bool
	{
		return in_array(mb_strtolower($fieldType), self::UNSUPPORTED_TYPES, true);
	}
}
