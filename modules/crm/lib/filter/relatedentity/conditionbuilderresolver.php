<?php

namespace Bitrix\Crm\Filter\RelatedEntity;

/**
 * Resolves a ConditionBuilder for a (source, target) entity type pair.
 * Decouples FilterApplier from the concrete Registry implementation: tests can supply a stub
 * implementation without going through service locator and storage strategies.
 */
interface ConditionBuilderResolver
{
	/**
	 * Returns a builder for the given pair, or null when the pair is not supported.
	 */
	public function getConditionBuilder(int $sourceTypeId, int $targetTypeId): ?ConditionBuilder;
}
