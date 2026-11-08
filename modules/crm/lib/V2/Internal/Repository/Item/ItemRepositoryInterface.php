<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Item;

use Bitrix\Main\ORM\Objectify\EntityObject;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;

/**
 * Read-only access to a single CRM entity table. Implementations return raw ORM
 * {@see EntityObject}s; hydration into V2 Items is the caller's responsibility.
 *
 * Filters accept either Bitrix's ORM-style array (passed to
 * {@see \Bitrix\Main\ORM\Query\Query::setFilter()}) or a pre-built {@see ConditionTree}
 * (passed to {@see \Bitrix\Main\ORM\Query\Query::where()}). All field names are expected
 * to be ORM column names — the V2 Provider does the camelCase → ORM resolution upstream.
 *
 * @internal
 */
interface ItemRepositoryInterface
{
	/**
	 * @param string[] $select ORM column names; use `['*']` for all scalars.
	 */
	public function getById(int $id, array $select): ?EntityObject;

	/**
	 * Empty `$ids` returns `[]` without hitting the DB; result order is unspecified.
	 *
	 * @param int[] $ids
	 * @param string[] $select ORM column names; use `['*']` for all scalars.
	 * @return EntityObject[]
	 */
	public function getByIds(array $ids, array $select): array;

	/**
	 * @param string[] $select ORM column names; use `['*']` for all scalars.
	 * @param array<string, string>|null $order ORM-name keyed ASC/DESC map.
	 * @param array<string, \Bitrix\Main\ORM\Fields\Field>|null $runtime Optional ORM runtime
	 *        fields (used by permission-filter machinery to inject INNER JOINs).
	 * @return EntityObject[]
	 */
	public function getList(
		array $select,
		array|ConditionTree|null $filter = null,
		?array $order = null,
		?int $limit = null,
		?int $offset = null,
		?array $runtime = null,
	): array;

	/**
	 * @param array<string, \Bitrix\Main\ORM\Fields\Field>|null $runtime
	 */
	public function getCount(array|ConditionTree|null $filter = null, ?array $runtime = null): int;

	/**
	 * Builds an ID-only ORM query with the given already-resolved filter and runtime fields.
	 * Returns null when the repository is unbound.
	 *
	 * @param array<string, \Bitrix\Main\ORM\Fields\Field>|null $runtime
	 */
	public function createIdQuery(array|ConditionTree|null $filter = null, ?array $runtime = null): ?Query;

	/**
	 * Registers the `PARENT_ID_<parentTypeId>` reference/expression fields on this repository's ORM
	 * entity so parent columns are addressable in select / filter / order. Idempotent (guarded by
	 * `hasField`) and safe to call repeatedly; a no-op when the repository is unbound. The caller
	 * must only invoke it for entity types that actually declare parent relations.
	 */
	public function ensureParentFieldReferences(int $entityTypeId): void;
}
