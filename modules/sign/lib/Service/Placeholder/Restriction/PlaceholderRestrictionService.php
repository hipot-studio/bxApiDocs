<?php

namespace Bitrix\Sign\Service\Placeholder\Restriction;

use Bitrix\Sign\Item\Document\Placeholder\PlaceholderCollection;
use Bitrix\Sign\Service\Placeholder\Restriction\Rule\CrmDenylistRule;
use Bitrix\Sign\Service\Placeholder\Restriction\Rule\EmployeeExternalBlockRule;
use Bitrix\Sign\Service\Placeholder\Restriction\Rule\EmployeeUnsupportedTypeRule;
use Bitrix\Sign\Service\Placeholder\Restriction\Rule\HcmLinkReferenceRule;
use Bitrix\Sign\Service\Placeholder\Restriction\Rule\MassPersonalFieldRule;
use Bitrix\Sign\Service\Placeholder\Restriction\Rule\RegionRule;

/**
 * Single source of truth for "is this placeholder supported in this signing scenario".
 *
 * Feeds both the placeholder list of the code slider and the warning shown before sending.
 * Does not replace the gates of the configure and signing paths.
 */
class PlaceholderRestrictionService
{
	/** @var RestrictionRule[] */
	private readonly array $rules;

	private readonly ?RegionRule $regionRule;
	private readonly ?EmployeeExternalBlockRule $employeeExternalBlockRule;
	private readonly ?EmployeeUnsupportedTypeRule $employeeUnsupportedTypeRule;
	private readonly ?MassPersonalFieldRule $massPersonalFieldRule;

	/**
	 * @param RestrictionRule[]|null $rules the configured set of rules, null takes the default one. The
	 *        typed entry points below answer from this very set as well, so a set holding no rule of the
	 *        matching type carries no restriction of it anywhere in the facade, and an empty set
	 *        restricts nothing at all.
	 */
	public function __construct(?array $rules = null)
	{
		$this->rules = $rules ?? $this->createDefaultRules();
		$this->regionRule = $this->findRule(RegionRule::class);
		$this->employeeExternalBlockRule = $this->findRule(EmployeeExternalBlockRule::class);
		$this->employeeUnsupportedTypeRule = $this->findRule(EmployeeUnsupportedTypeRule::class);
		$this->massPersonalFieldRule = $this->findRule(MassPersonalFieldRule::class);
	}

	/**
	 * @return RestrictionRule[]
	 */
	private function createDefaultRules(): array
	{
		return [
			new RegionRule(),
			new EmployeeExternalBlockRule(),
			new EmployeeUnsupportedTypeRule(),
			new HcmLinkReferenceRule(),
			new CrmDenylistRule(),
			new MassPersonalFieldRule(),
		];
	}

	/**
	 * @template T of RestrictionRule
	 * @param class-string<T> $ruleClass
	 * @return T|null
	 */
	private function findRule(string $ruleClass): ?RestrictionRule
	{
		foreach ($this->rules as $rule)
		{
			if ($rule instanceof $ruleClass)
			{
				return $rule;
			}
		}

		return null;
	}

	/**
	 * Regional fields (registration number, creation date) exist for the ru region only.
	 *
	 * Entry point of the gates that decide whether such a field or block is created at all: they judge
	 * by the region axis alone, so they need no document behind the context.
	 */
	public function isRegionalFieldAvailable(ScenarioContext $context): bool
	{
		return $this->regionRule?->isRegionalFieldAvailable($context->region) ?? true;
	}

	/**
	 * Whether an authoring surface must hide the regional external block in the given scenario.
	 *
	 * Entry point for the surfaces working with block codes instead of placeholder names; the answer
	 * is the one the placeholder list gets from EmployeeExternalBlockRule.
	 */
	public function isExternalBlockHidden(string $blockCode, ScenarioContext $context): bool
	{
		return $this->employeeExternalBlockRule?->isExternalBlockHidden($blockCode, $context) ?? false;
	}

	/**
	 * Whether the regional external block (registration number, creation date) belongs to the document.
	 *
	 * Single entry point of the two gates that decide it: the block creation of configure
	 * (Blanks\Block\Factory) and the regional fields of the template send (Operation\Document\Template\Send).
	 * A company-initiated document always gets the block, because the send wizard has a regional settings
	 * step where the company supplies the value; an employee-initiated one gets it only when the blank
	 * really holds the matching placeholder block. That runtime state of the blank is resolved by the
	 * caller through $blankHoldsRegionalBlock, and only when the axes make it matter: the company flow
	 * must not read the blank at all.
	 *
	 * The mutual exclusion with an HCM link occupying the field stays where it belongs, in the caller of
	 * configure: this predicate carries the scenario axis, not the downstream mechanics.
	 *
	 * @param \Closure(): bool $blankHoldsRegionalBlock
	 */
	public function isRegionalBlockRequired(ScenarioContext $context, \Closure $blankHoldsRegionalBlock): bool
	{
		if (!$context->isInitiatedByEmployee())
		{
			return true;
		}

		return $blankHoldsRegionalBlock();
	}

	/**
	 * Whether a field of this type must not be offered to the given member of the document.
	 *
	 * Entry point of the field factory gate (Factory\Field). It judges by the raw user field type,
	 * before the conversion to a sign field type, because the conversion turns a url into a plain
	 * string and the restriction would no longer see it; the member role comes in explicitly, because
	 * the factory knows the member instead of a parsed placeholder name.
	 */
	public function isFieldTypeUnsupported(
		string $rawFieldType,
		?string $memberRole,
		ScenarioContext $context,
	): bool
	{
		$typeRule = $this->employeeUnsupportedTypeRule;

		return $typeRule?->isUnsupportedForRole($rawFieldType, $memberRole, $context) ?? false;
	}

	public function getRestrictionReason(
		ParsedField $field,
		ScenarioContext $context,
		RestrictionPolicy $policy,
	): ?RestrictionReason
	{
		foreach ($this->rules as $rule)
		{
			$reason = $rule->getReason($field, $context, $policy);
			if ($reason !== null)
			{
				return $reason;
			}
		}

		return null;
	}

	public function isSupported(
		ParsedField $field,
		ScenarioContext $context,
		RestrictionPolicy $policy,
	): bool
	{
		return $this->getRestrictionReason($field, $context, $policy) === null;
	}

	/**
	 * Signature of the axes the filter really reads, for the cache key of a filtered list.
	 *
	 * Not a snapshot of the whole context: an axis no rule reads gives no other list, it only
	 * multiplies the cold rebuilds of the portal-wide one. The axes below are the inputs of the rules
	 * above, in the form the rules see them: the company of the external HR system decides as chosen
	 * or not chosen, and the mass axis decides as its own rule treats it under this policy. A rule
	 * that starts reading another axis has to be added here as well.
	 */
	public function getFilteredListSignature(ScenarioContext $context, RestrictionPolicy $policy): string
	{
		$isMassDocument = $this->massPersonalFieldRule?->isTreatedAsMassDocument($context, $policy) ?? false;

		return implode('_', [
			'r' . ($context->region === '' ? '-' : $context->region),
			'i' . $context->initiatedByType->value,
			'm' . ($isMassDocument ? '1' : '0'),
			'h' . ($context->hcmLinkCompanyId === null ? '-' : '+'),
		]);
	}

	/**
	 * Drops unsupported placeholders from the collected map, keeping its keys.
	 *
	 * Works with raw field names, so it must run before the conversion to aliases.
	 *
	 * @param array<string, PlaceholderCollection> $placeholdersMap
	 * @param array<string, string> $rawFieldTypeByFieldCode raw type of a field by the code its
	 *        placeholder name carries. The name holds the converted type, and the conversion turns a
	 *        url into a plain string, so the caller that collected the fields brings the raw type
	 *        along; a code missing from the map keeps the type of its name.
	 * @return array<string, PlaceholderCollection>
	 */
	public function filterSupported(
		array $placeholdersMap,
		ScenarioContext $context,
		RestrictionPolicy $policy,
		array $rawFieldTypeByFieldCode = [],
	): array
	{
		$result = [];

		foreach ($placeholdersMap as $key => $collection)
		{
			$result[$key] = $this->filterCollection($collection, $context, $policy, $rawFieldTypeByFieldCode);
		}

		return $result;
	}

	/**
	 * @param array<string, string> $rawFieldTypeByFieldCode
	 */
	private function filterCollection(
		PlaceholderCollection $collection,
		ScenarioContext $context,
		RestrictionPolicy $policy,
		array $rawFieldTypeByFieldCode,
	): PlaceholderCollection
	{
		$filtered = new PlaceholderCollection();

		foreach ($collection as $placeholder)
		{
			$field = ParsedField::fromName($placeholder->value);
			$field = $field->withRawFieldType($rawFieldTypeByFieldCode[$field->fieldCode] ?? '');
			if (!$this->isSupported($field, $context, $policy))
			{
				continue;
			}

			// the item is immutable and carries the semantic type, so it moves as it is
			$filtered->add($placeholder);
		}

		return $filtered;
	}
}
