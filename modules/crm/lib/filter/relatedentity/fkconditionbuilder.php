<?php

namespace Bitrix\Crm\Filter\RelatedEntity;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ORM\Fields\ExpressionField;

/**
 * Builds EXISTS / column-predicate condition for relations stored as a foreign key column
 * in the child entity table. Covers two storage strategies:
 *  - Compatible (legacy CRM entities, e.g. b_crm_quote.DEAL_ID, b_crm_deal.LEAD_ID),
 *  - Factory (smart entities, e.g. b_crm_dynamic_items_X.CONTACT_ID).
 *
 * Direction matters:
 *  - sourceIsParent = true  - source is parent, FK lives in child table - use EXISTS subquery.
 *  - sourceIsParent = false - source is child, FK lives in source's main table - use a direct
 *    column predicate against the source alias.
 *
 * Invariant for current pairs: $childFkColumn must equal both the DB column name in
 * $childTableName AND the ORM field name on the child entity. All current pairs satisfy
 * this - Compatible storage uses the same identifier for both, and smart entities reach
 * us only through the ORM path. buildLegacyCondition writes $childFkColumn into raw SQL
 * (DB column), while buildOrmCondition puts the same identifier into ExpressionField
 * buildFrom (ORM field). If a future pair ever uses an ORM field name that diverges from
 * the DB column (e.g. via Factory::getFieldsMap()), buildLegacyCondition would emit
 * invalid SQL: split $childFkColumn into childDbColumn + childOrmField in that case.
 */
final class FkConditionBuilder implements ConditionBuilder
{
	public function __construct(
		private readonly string $childTableName,
		private readonly string $childFkColumn,
		private readonly bool $sourceIsParent,
	)
	{
	}

	public function buildLegacyCondition(
		int $sourceTypeId,
		int $targetTypeId,
		bool $hasRelation,
		string $sourceTableAlias
	): string
	{
		if ($this->sourceIsParent)
		{
			$keyword = $hasRelation ? 'EXISTS' : 'NOT EXISTS';
			$table = $this->childTableName;
			$fk = $this->childFkColumn;

			return "{$keyword} (SELECT 1 FROM {$table} WHERE {$table}.{$fk} = {$sourceTableAlias}.ID)";
		}

		// FK columns on legacy CRM tables are DEFAULT NULL (Quote.DEAL_ID, Deal.LEAD_ID,
		// Lead.COMPANY_ID, etc.). NULL > 0 yields UNKNOWN which excludes the row from the
		// "has" set as desired, but NULL = 0 also yields UNKNOWN - so the "no" branch must
		// explicitly accept IS NULL alongside 0 to cover standalone rows.
		$column = "{$sourceTableAlias}.{$this->childFkColumn}";
		if ($hasRelation)
		{
			return "{$column} > 0";
		}

		return "({$column} = 0 OR {$column} IS NULL)";
	}

	public function buildOrmCondition(
		int $sourceTypeId,
		int $targetTypeId,
		bool $hasRelation
	): array
	{
		$runtimeFieldName = "REL_FILTER_{$sourceTypeId}_{$targetTypeId}";

		if ($this->sourceIsParent)
		{
			$table = $this->childTableName;
			$fk = $this->childFkColumn;

			$expression = new ExpressionField(
				$runtimeFieldName,
				"(CASE WHEN EXISTS (SELECT 1 FROM {$table} WHERE {$table}.{$fk} = %s) THEN 1 ELSE 0 END)",
				['ID']
			);
		}
		else
		{
			$expression = new ExpressionField(
				$runtimeFieldName,
				'(CASE WHEN %s > 0 THEN 1 ELSE 0 END)',
				[$this->childFkColumn]
			);
		}

		return [$runtimeFieldName, $expression, $hasRelation ? 1 : 0];
	}

	public function getCacheKey(): string
	{
		$direction = $this->sourceIsParent ? 'parent' : 'child';

		return 'fk|' . $this->childTableName . '|' . $this->childFkColumn . '|' . $direction;
	}

	/**
	 * Positive boolean SQL template plus its ORM field arguments, without the surrounding
	 * `CASE WHEN ... THEN 1 ELSE 0` wrapper. Used by {@see CompositeFkConditionBuilder} to OR-combine
	 * several FK candidates into one expression. The positive form is sufficient because the
	 * combined CASE WHEN with a 0/1 filter value already selects the desired "has"/"no" branch
	 * (UNKNOWN → ELSE 0, which correctly maps NULL FK columns into the "no" set).
	 *
	 * Returns a two-element list: [sqlTemplate, ormFieldArgs]. sqlTemplate is a SQL fragment with
	 * `%s` placeholders bound positionally to ormFieldArgs (ORM field names on the source entity).
	 *
	 * @return array
	 * @internal
	 */
	public function buildOrmPositivePredicate(): array
	{
		if ($this->sourceIsParent)
		{
			$table = $this->childTableName;
			$fk = $this->childFkColumn;

			return ["EXISTS (SELECT 1 FROM {$table} WHERE {$table}.{$fk} = %s)", ['ID']];
		}

		return ['%s > 0', [$this->childFkColumn]];
	}

	public function hasAnyRecords(int $sourceTypeId, int $targetTypeId): bool
	{
		// Probe the child table for a single row with a non-empty FK. A schema mismatch
		// (e.g. parentIdFieldName configured but the column does not exist on a particular
		// installation) translates to "no records" so the option is silently dropped from the UI
		// instead of crashing the page.
		$table = $this->childTableName;
		$fk = $this->childFkColumn;

		try
		{
			$row = Application::getConnection()
				->query("SELECT 1 FROM {$table} WHERE {$table}.{$fk} > 0 LIMIT 1")
				->fetch()
			;
		}
		catch (SqlQueryException)
		{
			return false;
		}

		return $row !== false;
	}

	/**
	 * Returns true when the configured table and FK column actually exist in the DB schema.
	 * Distinct from {@see self::hasAnyRecords()} which returns false both for missing schema
	 * and empty data - here we only check the schema so the caller (Registry) can drop
	 * misconfigured candidates from a composite while keeping legitimately empty ones.
	 *
	 * The check uses Connection::getTableFields() which is cached per request inside the
	 * connection, so repeated calls are free.
	 */
	public function isStorageAvailable(): bool
	{
		try
		{
			$fields = Application::getConnection()->getTableFields($this->childTableName);
		}
		catch (\Throwable)
		{
			return false;
		}

		return isset($fields[$this->childFkColumn]);
	}
}
