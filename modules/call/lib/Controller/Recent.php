<?php

namespace Bitrix\Call\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\ActionFilter\Scope;
use Bitrix\Main\Error;
use Bitrix\Call\Recent as RecentService;
use Bitrix\Call\Settings;
use Bitrix\Call\Model\CallUserTable;

/**
 * @internal
 */
class Recent extends Controller
{
	/**
	 * Whitelist of fields exposed by {@see self::getActiveAction()} in the lean
	 * (default) form. Anything else returned by
	 * {@see \Bitrix\Call\Recent::getActiveCalls()} (PUBLIC_ID, PARENT_ID/UUID,
	 * ENDPOINT, AI_ANALYZE, ...) is dropped before the response is sent. USERS
	 * and USER_DATA are added on top of this list only when the caller opts in
	 * via `withUserData` -- see {@see self::USER_DATA_FIELDS}.
	 */
	private const RESPONSE_FIELDS = [
		'ID',
		'TYPE',
		'SCHEME',
		'INITIATOR_ID',
		'PROVIDER',
		'ENTITY_TYPE',
		'ENTITY_ID',
		'STATE',
		'START_DATE',
		'END_DATE',
		'LOG_URL',
		'CHAT_ID',
		'ASSOCIATED_ENTITY',
		'UUID',
		'RECORD_AUDIO',
		'CALL_TOKEN',
		'CONNECTION_DATA',
		'LOG_TOKEN',
		'USER_STATE',
	];

	/**
	 * Fields appended to every call in the full form (`withUserData = true`).
	 * They bring the getActive payload to parity with the init-preload snapshot
	 * so a joined-late client can instantiate the call without an extra request.
	 */
	private const USER_DATA_FIELDS = [
		'USERS',
		'USER_DATA',
	];

	/**
	 * Values treated as boolean `true` for the flag parameters. AJAX delivers
	 * them as 'Y'/'1' strings, REST as native true/1; everything else (including
	 * the 'N' string, which is truthy under a naive bool cast) is false. Mirrors
	 * {@see \Bitrix\Call\Controller\CallManager}.
	 */
	private const VALID_TRUE_VALUES = [true, 1, '1', 'Y', 'y', 'true'];

	/**
	 * @inheritDoc
	 */
	public function configureActions(): array
	{
		return [
			'getActive' => [
				'+prefilters' => [new Scope(Scope::AJAX | Scope::REST)],
			],
		];
	}

	/**
	 * Returns active calls of the current user (the "recent" surface). Served on
	 * both transports: AJAX (web) and REST (mobile via getRestClient).
	 *
	 * By default the snapshot is served cache-first via
	 * {@see \Bitrix\Call\Recent::getActiveCallsForUser()}; when `forceRecalc` is
	 * truthy it is rebuilt from `b_call_user` via
	 * {@see \Bitrix\Call\Recent::updateUserActiveCallsCacheThrottled()} instead --
	 * a safety net for the "joined-late, cache not warm yet" race, not the default
	 * path, and throttled per user so it cannot be abused as a rebuild-storm DoS.
	 *
	 * Returns `calls: []` (degradation, not an error) when
	 * {@see \Bitrix\Call\Settings::isNewCallsEnabled()} is off.
	 * Active call horizon is 12 hours.
	 *
	 * The response is lean by default (whitelist below). With `withUserData`
	 * truthy the full form additionally exposes USERS and USER_DATA so a client
	 * that joined the call late can instantiate it without a second round-trip.
	 *
	 * @restMethod call.Recent.getActive
	 *
	 * @param mixed $forceRecalc Rebuild the snapshot instead of reading cache.
	 *                           Accepts 'Y'/'N' (AJAX) or true/false (REST).
	 * @param mixed $withUserData Include USERS and USER_DATA (full form).
	 *                            Accepts 'Y'/'N' (AJAX) or true/false (REST).
	 *
	 * Response shape:
	 * ```
	 * [
	 *   'calls' => [
	 *     <callId:int> => [
	 *       // ---- flat fields from Call::toArray($currentUserId) ----
	 *       'ID'               => int,
	 *       'TYPE'             => int,             // 1=instant, 2=permanent, 3=large
	 *       'SCHEME'           => int,             // 1=classic, 2=jwt
	 *       'INITIATOR_ID'     => int,
	 *       'PROVIDER'         => 'Plain'|'Bitrix'|'Voximplant',
	 *       'ENTITY_TYPE'      => string,
	 *       'ENTITY_ID'        => string,
	 *       'STATE'            => string,          // new|inviting|answered|finished
	 *       'START_DATE'       => DateTime|null,
	 *       'END_DATE'         => DateTime|null,
	 *       'LOG_URL'          => string|null,
	 *       'CHAT_ID'          => int|null,
	 *       'ASSOCIATED_ENTITY'=> array,           // empty array when no entity
	 *       'UUID'             => string|null,
	 *       'RECORD_AUDIO'     => string,          // 'Y'|'N'
	 *
	 *       // ---- recent-surface enrichment ----
	 *       'CALL_TOKEN'      => string,           // JWT for the chat (empty when no CHAT_ID)
	 *       'CONNECTION_DATA' => array|null,       // Bitrix provider: {endpoint:string|null, jwt:string}; Plain: null
	 *       'LOG_TOKEN'       => string,           // signed token for raw call log; empty when portal host / call_log_secret missing
	 *       'USER_STATE'      => string|null,      // CallUser state of the current user: unavailable|calling|ready|idle|declined|busy; null if row missing
	 *     ],
	 *     ...
	 *   ],
	 * ]
	 * ```
	 *
	 * Fields produced by the cache layer but always dropped:
	 * `IS_PUBLIC`, `PUBLIC_ID`, `PARENT_ID`, `PARENT_UUID`, `ENDPOINT`,
	 * `AI_ANALYZE`. `USERS` and `USER_DATA` are opt-in via `withUserData`.
	 *
	 * @return array|null `null` is returned together with `USER_NOT_AUTHORIZED`
	 *                    in the error list when the request has no current user.
	 */
	public function getActiveAction($forceRecalc = false, $withUserData = false): ?array
	{
		$userId = (int)($this->getCurrentUser()?->getId() ?? 0);
		if ($userId <= 0)
		{
			$this->addError(new Error('User not authorized', 'USER_NOT_AUTHORIZED'));
			return null;
		}

		if (!Settings::isNewCallsEnabled())
		{
			return ['calls' => []];
		}

		$forceRecalc = in_array($forceRecalc, self::VALID_TRUE_VALUES, true);
		$withUserData = in_array($withUserData, self::VALID_TRUE_VALUES, true);

		$activeCalls = $forceRecalc
			? RecentService::updateUserActiveCallsCacheThrottled($userId)
			: RecentService::getActiveCallsForUser($userId);

		$userStateByCallId = $this->loadUserStateByCallId(array_keys($activeCalls), $userId);

		$responseFields = $withUserData
			? [...self::RESPONSE_FIELDS, ...self::USER_DATA_FIELDS]
			: self::RESPONSE_FIELDS;
		$allowed = array_flip($responseFields);

		$calls = [];
		foreach ($activeCalls as $callId => $callData)
		{
			$callData['USER_STATE'] = $userStateByCallId[(int)$callId] ?? null;
			$calls[$callId] = array_intersect_key($callData, $allowed);
		}

		return ['calls' => $calls];
	}

	/**
	 * One SELECT against `b_call_user` for the (callIds, userId) pair. Active
	 * calls per user are bounded (handful at most), so a single batched query
	 * is enough — no chunking required.
	 *
	 * @param int[] $callIds
	 * @return array<int, string> [callId => CallUser::state]
	 */
	private function loadUserStateByCallId(array $callIds, int $userId): array
	{
		if ($callIds === [])
		{
			return [];
		}

		$rows = CallUserTable::query()
			->setSelect(['CALL_ID', 'STATE'])
			->whereIn('CALL_ID', $callIds)
			->where('USER_ID', $userId)
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$result[(int)$row['CALL_ID']] = (string)$row['STATE'];
		}

		return $result;
	}
}
