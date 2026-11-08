<?php

namespace Bitrix\Sign\Service\Placeholder\Restriction\Rule;

use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Document\FieldService;
use Bitrix\Sign\Service\Placeholder\Restriction\ParsedField;
use Bitrix\Sign\Service\Placeholder\Restriction\RestrictionPolicy;
use Bitrix\Sign\Service\Placeholder\Restriction\RestrictionReason;
use Bitrix\Sign\Service\Placeholder\Restriction\RestrictionRule;
use Bitrix\Sign\Service\Placeholder\Restriction\ScenarioContext;

/**
 * CRM fields excluded from the selector are not fillable placeholders either.
 *
 * The denylist itself stays in FieldService, this rule only reads it.
 */
class CrmDenylistRule implements RestrictionRule
{
	private readonly FieldService $fieldService;

	public function __construct(?FieldService $fieldService = null)
	{
		$this->fieldService = $fieldService ?? Container::instance()->getDocumentFieldService();
	}

	public function getReason(
		ParsedField $field,
		ScenarioContext $context,
		RestrictionPolicy $policy,
	): ?RestrictionReason
	{
		if (!$this->isDenied($field->fieldCode))
		{
			return null;
		}

		return RestrictionReason::SCENARIO_FORBIDDEN;
	}

	public function isDenied(string $fieldCode): bool
	{
		foreach ($this->fieldService->getExcludedFields() as $category => $fieldNames)
		{
			foreach ($fieldNames as $fieldName)
			{
				if ($fieldCode === $category . '_' . $fieldName)
				{
					return true;
				}
			}
		}

		return false;
	}
}
