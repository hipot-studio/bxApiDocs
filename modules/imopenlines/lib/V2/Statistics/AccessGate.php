<?php

namespace Bitrix\ImOpenLines\V2\Statistics;

use Bitrix\ImOpenLines\Config;
use Bitrix\ImOpenLines\Model\ConfigTable;
use Bitrix\ImOpenLines\Model\QueueTable;
use Bitrix\ImOpenLines\Security\Helper as SecurityHelper;
use Bitrix\ImOpenLines\Security\Permissions;
use Bitrix\Imopenlines\Limit;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

Loc::loadMessages(__FILE__);

/**
 * Thin tariff gate for imopenlines.v2 statistics REST methods.
 *
 * It does not duplicate box/cloud tariff logic — it only wraps the existing
 * {@see Limit} checks so every statistics method (phase 2/3) shares the same
 * access decision and error contract.
 */
final class AccessGate
{
	public const ERROR_TARIFF_RESTRICTION = 'TARIFF_RESTRICTION';

	/**
	 * Checks whether the current tariff allows access to imopenlines.v2 statistics methods.
	 *
	 * Wraps {@see Limit::canUseReport()} (the `report_open_lines` feature flag).
	 * Returns a successful empty {@see Result} when access is allowed, or a Result
	 * with a single {@see Error} (code {@see self::ERROR_TARIFF_RESTRICTION}) otherwise.
	 */
	public static function checkReportAccess(): Result
	{
		$result = new Result();

		if (!Limit::canUseReport())
		{
			$result->addError(new Error(
				Loc::getMessage('IMOL_STAT_ACCESS_GATE_TARIFF_RESTRICTION'),
				self::ERROR_TARIFF_RESTRICTION
			));
		}

		return $result;
	}

	/**
	 * Whether the current tariff allows showing head (supervisor) vote/comment.
	 *
	 * Wraps {@see Limit::canUseVoteHead()}. Callers should use this to decide whether
	 * to mask `voteHead`/`commentHead` fields to null in the REST response rather than
	 * omitting the tariff check altogether.
	 */
	public static function canUseVoteHead(): bool
	{
		return Limit::canUseVoteHead();
	}

	/**
	 * Whether voteHead/commentHead should be shown for a specific session's line.
	 *
	 * Unlike {@see self::canUseVoteHead()} (tariff only), this also checks the
	 * caller's per-line {@see \Bitrix\ImOpenLines\Security\Permissions::ENTITY_VOTE_HEAD}
	 * permission via {@see Config::canVoteAsHead()} — a user without that right on
	 * the session's line must not see head vote/comment even on a tariff that allows
	 * the feature overall.
	 */
	public static function canShowVoteHeadForConfig(int $configId, int $userId): bool
	{
		return Config::canVoteAsHead($configId, true, $userId);
	}

	/**
	 * Precomputes the per-line vote-head visibility for a whole page of rows in one
	 * batch, returning a cache array (CONFIG_ID => bool) ready to be passed straight into
	 * {@see self::maskVoteHeadFieldsForConfig()}'s `$cache` parameter.
	 *
	 * The per-row masking helper caches by CONFIG_ID, but on a page spanning many lines
	 * it still resolves {@see Config::canVoteAsHead()} (per-line rights + a queue lookup)
	 * once per distinct line - up to ~200 lookups for a full page. This reuses the same
	 * batched permission resolution already used for the `hasVoteHead` filter
	 * ({@see self::resolveAllowedConfigIdsForVoteHead()}, a fixed small number of queries
	 * regardless of how many lines are on the page), so the masking loop then only reads
	 * the prefilled cache. The result is identical to calling
	 * {@see self::canShowVoteHeadForConfig()} per line (see that method's docblock: the
	 * batch resolution matches `Config::canVoteAsHead($configId, true, $userId)` for every
	 * candidate line).
	 *
	 * @param int[] $configIds CONFIG_IDs of the rows on the page (duplicates allowed).
	 * @return array<int, bool> Visibility keyed by CONFIG_ID (missing lines => false).
	 */
	public static function buildVoteHeadVisibilityCache(array $configIds, int $userId): array
	{
		$configIds = array_values(array_unique(array_map('intval', $configIds)));

		$cache = array_fill_keys($configIds, false);
		if (empty($configIds))
		{
			return $cache;
		}

		foreach (self::resolveAllowedConfigIdsForVoteHead($configIds, $userId) as $configId)
		{
			$cache[(int)$configId] = true;
		}

		return $cache;
	}

	/**
	 * Masks voteHead/commentHead fields to null unless both the tariff and the
	 * caller's per-line vote-head permission allow them.
	 *
	 * @param array $fields Formatted REST fields, expected to (optionally) contain
	 *                       'voteHead' and 'commentHead' keys.
	 * @param int $configId Line the row belongs to.
	 * @param int $userId User the REST call is running as.
	 * @param array<int, bool> &$cache Per-request cache keyed by CONFIG_ID. Callers
	 *                                  iterating many rows should declare one array
	 *                                  and pass it by reference across the whole
	 *                                  loop so the permission check runs once per
	 *                                  line, not once per row (or prefill it via
	 *                                  {@see self::buildVoteHeadVisibilityCache()}).
	 */
	public static function maskVoteHeadFieldsForConfig(array $fields, int $configId, int $userId, array &$cache): array
	{
		$cache[$configId] ??= self::canShowVoteHeadForConfig($configId, $userId);

		if ($cache[$configId])
		{
			return $fields;
		}

		if (array_key_exists('voteHead', $fields))
		{
			$fields['voteHead'] = null;
		}

		if (array_key_exists('commentHead', $fields))
		{
			$fields['commentHead'] = null;
		}

		return $fields;
	}

	/**
	 * Resolves the CONFIG_ID scope a `hasVoteHead` REST filter may safely be applied
	 * to, closing a side channel: without this, applying `>VOTE_HEAD 0` /
	 * `=VOTE_HEAD 0` unconditionally would let a user without the per-line
	 * {@see Config::canVoteAsHead()} permission on a given line infer the (masked)
	 * VOTE_HEAD value from whether that line's sessions are present in the result
	 * set / affect `hasNextPage`, even though {@see self::maskVoteHeadFieldsForConfig()}
	 * already hides the value itself.
	 *
	 * @param mixed $configIdFilter The raw `configId` REST filter value (scalar,
	 *                                array, or null/absent).
	 * @param int $userId
	 * @return array{apply: bool, configIds: int[]|null} `apply` false means: do not
	 *         add the hasVoteHead predicate to the query at all — no line in scope
	 *         has the permission, so dropping the predicate keeps the result
	 *         composition independent of the hidden field. `apply` true with
	 *         `configIds` null means every candidate line already has the
	 *         permission, so the predicate can be applied as-is. `apply` true with a
	 *         `configIds` array means: apply the predicate AND additionally restrict
	 *         `CONFIG_ID` to this (sub)list, so lines without the permission are
	 *         excluded from the result outright instead of being included/excluded
	 *         based on the hidden value.
	 */
	public static function resolveVoteHeadFilterScope(mixed $configIdFilter, int $userId): array
	{
		// Resolve the permission level first. For PERMISSION_ANY/NONE the concrete candidate
		// line set is irrelevant, so we can answer without resolveCandidateConfigIds() - which,
		// when no configId filter is supplied, would otherwise load every line via ConfigTable
		// just to (for ANY/NONE) not use the list at all.
		$allowedUserIds = self::resolveVoteHeadAllowedUserIds($userId);

		if (!is_array($allowedUserIds))
		{
			// PERMISSION_ANY: every line already has the permission, apply the predicate as-is.
			return ['apply' => true, 'configIds' => null];
		}

		if (empty($allowedUserIds))
		{
			// PERMISSION_NONE (or tariff off): no line has the permission, drop the predicate.
			return ['apply' => false, 'configIds' => null];
		}

		// PERMISSION_SELF/DEPARTMENT: the candidate line set is needed to narrow to the lines
		// the caller actually has the permission on.
		$candidateConfigIds = self::resolveCandidateConfigIds($configIdFilter);

		if (empty($candidateConfigIds))
		{
			// Nothing to restrict: either an explicit empty configId[] filter (the underlying
			// CONFIG_ID predicate will already yield no rows) or no lines exist at all.
			return ['apply' => true, 'configIds' => null];
		}

		$allowed = self::resolveAllowedConfigIdsForVoteHead($candidateConfigIds, $userId);

		if (empty($allowed))
		{
			return ['apply' => false, 'configIds' => null];
		}

		if (count($allowed) === count($candidateConfigIds))
		{
			return ['apply' => true, 'configIds' => null];
		}

		return ['apply' => true, 'configIds' => $allowed];
	}

	/**
	 * Resolves the vote-head allowed-user-id set for the given user, keyed off `$userId`
	 * (see the note in {@see self::resolveAllowedConfigIdsForVoteHead()}):
	 * - a non-array result means PERMISSION_ANY (every line allowed);
	 * - an empty array means PERMISSION_NONE or the tariff does not allow vote head;
	 * - a non-empty array is the SELF/DEPARTMENT allowed-user-id set.
	 */
	private static function resolveVoteHeadAllowedUserIds(int $userId): mixed
	{
		if (!Limit::canUseVoteHead())
		{
			return [];
		}

		$permission = Permissions::createWithUserId($userId)->getPermission(
			Permissions::ENTITY_VOTE_HEAD,
			Permissions::ACTION_PERFORM
		);

		return SecurityHelper::getAllowedUserIds($userId, $permission);
	}

	/**
	 * @return int[]
	 */
	private static function resolveCandidateConfigIds(mixed $configIdFilter): array
	{
		if ($configIdFilter !== null)
		{
			$ids = is_array($configIdFilter) ? $configIdFilter : [$configIdFilter];

			return array_values(array_unique(array_map('intval', $ids)));
		}

		// No configId filter supplied: the underlying query can touch any line, so
		// every line's permission has to be checked to avoid leaking hidden
		// VOTE_HEAD data for lines the caller cannot see it on.
		$rows = ConfigTable::getList(['select' => ['ID']])->fetchAll();

		return array_map(static fn(array $row) => (int)$row['ID'], $rows);
	}

	/**
	 * Resolves, out of a set of candidate CONFIG_IDs, which ones the given user has the
	 * {@see Config::canVoteAsHead()} / {@see Permissions::ENTITY_VOTE_HEAD} permission
	 * on - with a fixed, small number of queries regardless of how many candidate lines
	 * there are, instead of calling {@see Config::canVoteAsHead()} (and therefore
	 * running one `QueueTable::getList()` lookup) once per distinct line.
	 *
	 * This reimplements {@see Config::canDoOperation()}'s decision for
	 * ENTITY_VOTE_HEAD/ACTION_PERFORM rather than calling it per line:
	 * - The permission *level* (NONE/SELF/DEPARTMENT/ANY) is resolved for the requested
	 *   `$userId` (`Security\Permissions::createWithUserId($userId)`, see the note at the
	 *   resolution site below) and does not depend on the line at all, so it is resolved
	 *   once.
	 * - PERMISSION_ANY means every candidate line is allowed - no further queries.
	 * - PERMISSION_NONE means no candidate line is allowed - no further queries.
	 * - PERMISSION_SELF/DEPARTMENT resolve to a fixed allowed-user-id set
	 *   ({@see SecurityHelper::getAllowedUserIds()}), and a line is allowed if either
	 *   one of those users is a member of its queue ({@see QueueTable}, batched into a
	 *   single grouped query over all candidates) or the line's MODIFY_USER_ID equals
	 *   $userId (the same fallback `canDoOperation()` uses, batched into a single
	 *   ConfigTable query over whatever lines the queue check did not already allow).
	 *
	 * Tariff (`Limit::canUseVoteHead()`) is re-checked here too so this method's result
	 * is self-contained and matches `Config::canVoteAsHead($configId, true, $userId)`
	 * for every candidate line, even though callers in this class already gate on
	 * {@see self::canUseVoteHead()} before reaching here.
	 *
	 * @param int[] $candidateConfigIds
	 * @return int[] Allowed CONFIG_IDs, subset of $candidateConfigIds.
	 */
	private static function resolveAllowedConfigIdsForVoteHead(array $candidateConfigIds, int $userId): array
	{
		if (empty($candidateConfigIds))
		{
			return [];
		}

		// Permission level is keyed off the $userId this method was asked about, not the
		// ambient current user: the two coincide in the normal REST flow but can differ in
		// tests / CLI / background calls, and the rest of the method already keys off $userId.
		$allowedUserIds = self::resolveVoteHeadAllowedUserIds($userId);

		if (!is_array($allowedUserIds))
		{
			// PERMISSION_ANY: every candidate line is allowed.
			return $candidateConfigIds;
		}

		if (empty($allowedUserIds))
		{
			// PERMISSION_NONE: no candidate line is allowed.
			return [];
		}

		// Query 1/2: lines where one of the allowed users is an actual queue member.
		$rows = QueueTable::getList([
			'select' => ['CONFIG_ID'],
			'filter' => [
				'=USER_ID' => $allowedUserIds,
				'=CONFIG_ID' => $candidateConfigIds,
			],
			'group' => ['CONFIG_ID'],
		])->fetchAll();

		$allowed = [];
		foreach ($rows as $row)
		{
			$allowed[(int)$row['CONFIG_ID']] = true;
		}

		$remaining = array_values(array_diff($candidateConfigIds, array_keys($allowed)));

		if (!empty($remaining))
		{
			// Query 2/2: fallback for lines not matched above - the caller created
			// (MODIFY_USER_ID) the line, same fallback as Config::canDoOperation().
			$modifyRows = ConfigTable::getList([
				'select' => ['ID'],
				'filter' => [
					'=ID' => $remaining,
					'=MODIFY_USER_ID' => $userId,
				],
			])->fetchAll();

			foreach ($modifyRows as $row)
			{
				$allowed[(int)$row['ID']] = true;
			}
		}

		return array_keys($allowed);
	}
}
