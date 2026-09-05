<?php

namespace Bitrix\Mail\Internals;

use Bitrix\Mail\Internals\Entity\UserSignature;
use Bitrix\Mail\Service\SharedSignature\AssignmentResolver;
use Bitrix\Mail\Service\SharedSignature\SignatureMigrator;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\Entity;

/**
 * Class UserSignatureTable
 *
 * Backward-compatibility adapter over the unified signature model: the class name, the field
 * names and the shape of the result stay as they were, the data comes from SharedSignatureTable
 * (scope 'owner') together with the sender targets of its assignments. While the migration is
 * running a miss — the unified model knows nothing about the requested user or row — falls back
 * to the legacy table b_mail_user_signature. That fallback lives here and in SignatureResolver,
 * never in the core.
 *
 * The migration copies the legacy table row by row and in portions of its own, so one owner can
 * have a copied part and a part that still lives in the legacy table alone. A read by owner is
 * therefore answered by both parts at once, per row: the copies plus the rows no copy points at.
 * Switching the whole owner over to the unified model as soon as one copy exists would hide the
 * rest of his personal signatures from the composer, CRM and mobile until the next portion, and
 * the substitution would silently pick another signature. The price is a read of the legacy table
 * next to the copies, bounded by the border SignatureMigrator::copiedUpTo() confirmed.
 *
 * Both parts keep the identifiers they have, and the two sequences are independent, so during the
 * migration one number may name a row of either part. What that costs is bounded: a read by owner
 * answers with both parts, so no personal signature disappears, while a read by that number alone
 * cannot tell the spaces apart and answers with whichever row it meets first. Callers to whom the
 * distinction matters resolve it themselves — SignatureResolver falls back to the personal rows of
 * the user for a remembered choice, SignatureMigrator::findMigratedId() follows the legacy link.
 *
 * Reads only. Writing goes to the unified model through
 * \Bitrix\Mail\Service\SharedSignature\SharedSignatureService; the legacy table is not written
 * through this adapter. Adapted are getList() and getCount() together with everything built on
 * them — getById(), getRow(), getRowById(); a hand-built query() still addresses the legacy table.
 *
 * @deprecated Kept for the core, CRM and mobile versions that still read signatures from this
 * entity directly. Removal condition: the minimum supported version of the main module receives
 * signatures through the OnMailSenderListBuild event instead of this class.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_UserSignature_Query query()
 * @method static EO_UserSignature_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_UserSignature_Result getById($id)
 * @method static EO_UserSignature_Result getList(array $parameters = [])
 * @method static EO_UserSignature_Entity getEntity()
 * @method static \Bitrix\Mail\Internals\Entity\UserSignature createObject($setDefaultValues = true)
 * @method static \Bitrix\Mail\Internals\EO_UserSignature_Collection createCollection()
 * @method static \Bitrix\Mail\Internals\Entity\UserSignature wakeUpObject($row)
 * @method static \Bitrix\Mail\Internals\EO_UserSignature_Collection wakeUpCollection($rows)
 */
class UserSignatureTable extends DataManager
{
	const TYPE_ADDRESS = 'address';
	const TYPE_SENDER = 'sender';

	/** Fields the adapter is able to answer from the unified model. */
	private const ADAPTED_FIELDS = ['ID', 'USER_ID', 'SENDER', 'SIGNATURE'];

	/**
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_mail_user_signature';
	}

	/**
	 * @return array
	 */
	public static function getMap()
	{
		return [
			new Entity\IntegerField('ID', [
				'primary' => true,
				'autocomplete' => true,
			]),
			new Entity\IntegerField('USER_ID', [
				'required' => true,
			]),
			new Entity\TextField('SIGNATURE'),
			new Entity\StringField('SENDER'),
		];
	}

	/**
	 * @return \Bitrix\Main\ORM\Objectify\EntityObject|string
	 */
	public static function getObjectClass()
	{
		return UserSignature::class;
	}

	/**
	 * Reads the legacy table itself, bypassing the adaptation — the rows as they are physically
	 * stored. Serves the migration with its background stepper and the part of a read by owner that
	 * answers with the rows no copy points at yet.
	 *
	 * @param array $parameters
	 * @return \Bitrix\Main\ORM\Query\Result
	 */
	public static function getLegacyList(array $parameters = [])
	{
		return parent::getList($parameters);
	}

	/**
	 * Serves the read from the unified model whenever it can, and leaves the query to the legacy
	 * table otherwise. getById(), getRow() and getRowById() go through here as well.
	 *
	 * @param array $parameters
	 * @return \Bitrix\Main\ORM\Query\Result|UserSignatureAdapterResult
	 */
	public static function getList(array $parameters = [])
	{
		$query = self::parseLegacyQuery($parameters);
		$rows = $query === null ? null : self::readUnifiedRows($query);

		if ($rows === null)
		{
			return parent::getList($parameters);
		}

		$total = count($rows);
		$rows = self::applyOrder($rows, $query['order']);
		$rows = self::applyLimit($rows, $query['limit'], $query['offset']);

		return new UserSignatureAdapterResult($rows, $query['select'], $total);
	}

	/**
	 * @param array|\Bitrix\Main\ORM\Query\Filter\ConditionTree $filter
	 * @param array $cache
	 * @return int
	 */
	public static function getCount($filter = [], array $cache = [])
	{
		if (is_array($filter))
		{
			$query = self::parseLegacyQuery(['filter' => $filter]);
			$rows = $query === null ? null : self::readUnifiedRows($query);

			if ($rows !== null)
			{
				return count($rows);
			}
		}

		return parent::getCount($filter, $cache);
	}

	/**
	 * Reduces the legacy getList() parameters to the subset the adapter is able to answer.
	 * Returns null for anything outside it — such a query keeps being served by the legacy
	 * table, exactly as before.
	 *
	 * @return array{conditions: array<array{field: string, values: array}>, select: string[]|null, order: string|null, limit: int|null, offset: int|null}|null
	 */
	private static function parseLegacyQuery(array $parameters): ?array
	{
		$supported = ['select', 'filter', 'order', 'limit', 'offset', 'count_total'];
		if (array_diff(array_keys($parameters), $supported) !== [])
		{
			return null;
		}

		$select = self::parseSelect($parameters['select'] ?? null);
		if ($select === false)
		{
			return null;
		}

		$conditions = self::parseFilter($parameters['filter'] ?? []);
		if ($conditions === null)
		{
			return null;
		}

		$order = self::parseOrder($parameters['order'] ?? null);
		if ($order === false)
		{
			return null;
		}

		foreach (['limit', 'offset'] as $key)
		{
			if (isset($parameters[$key]) && !is_numeric($parameters[$key]))
			{
				return null;
			}
		}

		return [
			'conditions' => $conditions,
			'select' => $select,
			'order' => $order,
			'limit' => isset($parameters['limit']) ? (int)$parameters['limit'] : null,
			'offset' => isset($parameters['offset']) ? (int)$parameters['offset'] : null,
		];
	}

	/**
	 * @return string[]|null|false false — unsupported, null — every field.
	 */
	private static function parseSelect($select)
	{
		if ($select === null)
		{
			return null;
		}

		if (!is_array($select))
		{
			return false;
		}

		$fields = [];
		foreach ($select as $alias => $field)
		{
			// Aliases, '*' and joined fields belong to the legacy path.
			if (!is_int($alias) || !is_string($field) || !in_array($field, self::ADAPTED_FIELDS, true))
			{
				return false;
			}

			$fields[] = $field;
		}

		return $fields;
	}

	/**
	 * Accepts a flat filter over the adapted fields with the default or the '=' operator, which
	 * is everything the legacy readers use. Nested blocks, LOGIC and other operators yield null.
	 *
	 * @return array<array{field: string, values: array, exact: bool}>|null
	 */
	private static function parseFilter($filter): ?array
	{
		if (!is_array($filter))
		{
			return null;
		}

		$conditions = [];
		foreach ($filter as $key => $value)
		{
			if (!is_string($key))
			{
				return null;
			}

			$exact = str_starts_with($key, '=');
			$field = $exact ? substr($key, 1) : $key;
			if (!in_array($field, self::ADAPTED_FIELDS, true))
			{
				return null;
			}

			$values = is_array($value) ? array_values($value) : [$value];
			foreach ($values as $item)
			{
				if ($item !== null && !is_scalar($item))
				{
					return null;
				}
			}

			$conditions[] = ['field' => $field, 'values' => $values, 'exact' => $exact];
		}

		return $conditions;
	}

	/**
	 * @return string|null|false false — unsupported, null — unordered.
	 */
	private static function parseOrder($order)
	{
		if ($order === null)
		{
			return null;
		}

		if (!is_array($order) || count($order) !== 1)
		{
			return false;
		}

		$field = (string)array_key_first($order);
		$direction = mb_strtolower((string)reset($order));

		if ($field !== 'ID' || !in_array($direction, ['asc', 'desc'], true))
		{
			return false;
		}

		return $direction;
	}

	/**
	 * Reads the unified model and shapes it as the legacy entity. A read by owner is answered
	 * together with the legacy rows of that owner the migration has not copied yet.
	 *
	 * @return array<array{ID: int, USER_ID: int, SENDER: string, SIGNATURE: string}>|null
	 *         null means a miss: the unified model knows nothing about this query and the
	 *         legacy table answers instead.
	 */
	private static function readUnifiedRows(array $query): ?array
	{
		try
		{
			$ids = self::conditionValues($query['conditions'], 'ID');
			$ownerIds = self::conditionValues($query['conditions'], 'USER_ID');

			if ($ids === null && $ownerIds === null)
			{
				// Neither a row nor an owner is named: a portal-wide scan is not what this
				// adapter is for, and the migration stepper walks the legacy table this way.
				return null;
			}

			$filter = ['=SCOPE' => SharedSignatureTable::SCOPE_OWNER];

			if ($ids !== null)
			{
				if ($ids === [])
				{
					return [];
				}

				$filter['=ID'] = $ids;
			}

			if ($ownerIds !== null)
			{
				if ($ownerIds === [])
				{
					return [];
				}

				$filter['=OWNER_ID'] = $ownerIds;
			}

			$signatures = [];
			$result = SharedSignatureTable::getList([
				'select' => ['ID', 'OWNER_ID', 'SIGNATURE'],
				'filter' => $filter,
			]);
			while ($row = $result->fetch())
			{
				$signatures[(int)$row['ID']] = $row;
			}

			if (empty($signatures))
			{
				// The unified model has never heard of this owner or row: the legacy table answers
				// the query itself, exactly as it did before the unified model appeared.
				return null;
			}

			$rows = self::buildRows($signatures, $query['conditions']);

			if ($ids === null)
			{
				// A named row belongs to one of the two identifier spaces and the adapter cannot
				// tell which, so only a read by owner is answered by both parts.
				$rows = array_merge($rows, self::readPendingLegacyRows($ownerIds, $query['conditions']));
			}

			return $rows;
		}
		catch (\Throwable $e)
		{
			// A portal whose schema is not updated yet keeps working through the legacy table.
			return null;
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $signatures Unified rows by identifier.
	 * @param array<array{field: string, values: array, exact: bool}> $conditions
	 * @return array<array{ID: int, USER_ID: int, SENDER: string, SIGNATURE: string}>
	 */
	private static function buildRows(array $signatures, array $conditions): array
	{
		$assignments = self::loadAssignments(array_keys($signatures));

		$rows = [];
		foreach ($signatures as $id => $signature)
		{
			foreach (self::resolveSenders($assignments[$id] ?? []) as $sender)
			{
				$row = [
					'ID' => (int)$id,
					'USER_ID' => (int)$signature['OWNER_ID'],
					'SENDER' => $sender,
					'SIGNATURE' => (string)$signature['SIGNATURE'],
				];

				if (self::matchesConditions($row, $conditions))
				{
					$rows[] = $row;
				}
			}
		}

		return $rows;
	}

	/**
	 * Legacy rows of the given owners no copy points at, in the shape they are physically stored in.
	 * Answering an owner without them is what makes a part of his signatures disappear while the
	 * migration is running.
	 *
	 * The legacy table has no index by owner, so the search is bounded by the border the migration
	 * confirmed: below it everything has a copy already, and asking about the rest is a walk over the
	 * primary key that normally meets nothing at all.
	 *
	 * @param int[] $ownerIds
	 * @param array<array{field: string, values: array, exact: bool}> $conditions
	 * @return array<array{ID: int, USER_ID: int, SENDER: string, SIGNATURE: string}>
	 */
	private static function readPendingLegacyRows(array $ownerIds, array $conditions): array
	{
		if (empty($ownerIds))
		{
			return [];
		}

		$legacyRows = [];
		$result = self::getLegacyList([
			'select' => self::ADAPTED_FIELDS,
			'filter' => [
				'=USER_ID' => $ownerIds,
				'>ID' => SignatureMigrator::copiedUpTo(),
			],
			'order' => ['ID' => 'ASC'],
		]);
		while ($row = $result->fetch())
		{
			$row['ID'] = (int)$row['ID'];
			$row['USER_ID'] = (int)$row['USER_ID'];

			$legacyRows[$row['ID']] = $row;
		}

		if (empty($legacyRows))
		{
			return [];
		}

		foreach (self::readCopiedLegacyIds(array_keys($legacyRows)) as $legacyId)
		{
			unset($legacyRows[$legacyId]);
		}

		$rows = [];
		foreach ($legacyRows as $row)
		{
			if (self::matchesConditions($row, $conditions))
			{
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * @param int[] $legacyIds
	 * @return int[] Identifiers of the given legacy rows that have a copy in the unified model.
	 */
	private static function readCopiedLegacyIds(array $legacyIds): array
	{
		$copied = [];
		$result = SharedSignatureTable::getList([
			'select' => ['LEGACY_ID'],
			'filter' => ['=LEGACY_ID' => $legacyIds],
		]);
		while ($row = $result->fetch())
		{
			$copied[] = (int)$row['LEGACY_ID'];
		}

		return $copied;
	}

	/**
	 * Sender strings a signature is bound to, in the legacy meaning of the SENDER field.
	 * An empty set of assignments means "any sender of the owner" and yields one empty string.
	 * Targets that the legacy shape cannot express (a mailbox, a department, a user) hide the
	 * signature from this adapter instead of turning it into the general one.
	 *
	 * @param array{sender?: string[], other?: bool} $assignments
	 * @return string[]
	 */
	private static function resolveSenders(array $assignments): array
	{
		$senders = $assignments['sender'] ?? [];

		if (!empty($senders))
		{
			return $senders;
		}

		return empty($assignments['other']) ? [''] : [];
	}

	/**
	 * @param int[] $signatureIds
	 * @return array<int, array{sender?: string[], other?: bool}>
	 */
	private static function loadAssignments(array $signatureIds): array
	{
		$grouped = [];

		$result = SharedSignatureAssignmentTable::getList([
			'select' => ['SIGNATURE_ID', 'TARGET_TYPE', 'TARGET_VALUE'],
			'filter' => ['=SIGNATURE_ID' => $signatureIds],
			'order' => ['ID' => 'ASC'],
		]);
		while ($row = $result->fetch())
		{
			$signatureId = (int)$row['SIGNATURE_ID'];

			if ((string)$row['TARGET_TYPE'] === SharedSignatureAssignmentTable::TARGET_SENDER)
			{
				$grouped[$signatureId]['sender'][] = (string)($row['TARGET_VALUE'] ?? '');
			}
			else
			{
				$grouped[$signatureId]['other'] = true;
			}
		}

		return $grouped;
	}

	/**
	 * Values of every condition on the given field, intersected. Null means the field is not
	 * filtered at all.
	 *
	 * @param array<array{field: string, values: array}> $conditions
	 * @return int[]|null
	 */
	private static function conditionValues(array $conditions, string $field): ?array
	{
		$values = null;

		foreach ($conditions as $condition)
		{
			if ($condition['field'] !== $field)
			{
				continue;
			}

			$current = array_map('intval', $condition['values']);
			$values = $values === null ? $current : array_values(array_intersect($values, $current));
		}

		return $values;
	}

	/**
	 * @param array<array{field: string, values: array, exact: bool}> $conditions
	 */
	private static function matchesConditions(array $row, array $conditions): bool
	{
		foreach ($conditions as $condition)
		{
			$field = $condition['field'];
			$matched = false;

			foreach ($condition['values'] as $value)
			{
				if (self::valueMatches($field, $row[$field], $value, $condition['exact']))
				{
					$matched = true;
					break;
				}
			}

			if (!$matched)
			{
				return false;
			}
		}

		return true;
	}

	private static function valueMatches(string $field, $rowValue, $filterValue, bool $exact): bool
	{
		if ($field === 'ID' || $field === 'USER_ID')
		{
			return (int)$rowValue === (int)$filterValue;
		}

		// A key without the '=' prefix is a LIKE in the database, which is how the signature grid
		// searches by a substring. Matching it as an equality would answer such a filter with
		// nothing at all.
		if (!$exact && self::isPattern((string)$filterValue))
		{
			return self::matchesPattern((string)$rowValue, (string)$filterValue);
		}

		if ($field === 'SENDER')
		{
			// The legacy comparison ran in the database, where a *_ci collation ignores case and
			// VARCHAR padding ignores trailing spaces. Same rule as everywhere else in the module.
			return AssignmentResolver::normalizeSenderKey((string)$rowValue)
				=== AssignmentResolver::normalizeSenderKey((string)$filterValue);
		}

		return (string)$rowValue === (string)$filterValue;
	}

	private static function isPattern(string $value): bool
	{
		return str_contains($value, '%') || str_contains($value, '_');
	}

	/**
	 * Applies an SQL LIKE pattern the way a *_ci collation does: case-insensitively, with '%'
	 * standing for any run of characters and '_' for a single one.
	 */
	private static function matchesPattern(string $value, string $pattern): bool
	{
		$regexp = preg_quote($pattern, '/');
		$regexp = str_replace(['%', '_'], ['.*', '.'], $regexp);

		return (bool)preg_match('/^' . $regexp . '$/isu', $value);
	}

	/**
	 * @param array<array<string, mixed>> $rows
	 * @return array<array<string, mixed>>
	 */
	private static function applyOrder(array $rows, ?string $direction): array
	{
		if ($direction === null)
		{
			return $rows;
		}

		usort($rows, static fn(array $a, array $b): int => $direction === 'desc'
			? $b['ID'] <=> $a['ID']
			: $a['ID'] <=> $b['ID']
		);

		return $rows;
	}

	/**
	 * @param array<array<string, mixed>> $rows
	 * @return array<array<string, mixed>>
	 */
	private static function applyLimit(array $rows, ?int $limit, ?int $offset): array
	{
		if ($limit === null && $offset === null)
		{
			return $rows;
		}

		return array_slice($rows, $offset ?? 0, $limit !== null && $limit > 0 ? $limit : null);
	}
}
