<?php

namespace Bitrix\Call;

use Bitrix\Call\Model\CallTable;
use Bitrix\Call\Call\PlainCall;
use Bitrix\Call\Call\BitrixCall;
use Bitrix\Call\Call\ConferenceCall;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

class CallFactory
{
	/**
	 * @return string|Call|BitrixCall
	 */
	protected static function getProviderClass(string $provider, int $type)
	{
		return match (true)
		{
			$type === Call::TYPE_PERMANENT => ConferenceCall::class,
			$provider === Call::PROVIDER_BITRIX => BitrixCall::class,
			$provider === Call::PROVIDER_PLAIN => PlainCall::class,
			default => Call::class
		};
	}

	/**
	 * @return Call|BitrixCall
	 */
	public static function createWithEntity(
		int $type,
		string $provider,
		string $entityType,
		string $entityId,
		int $initiatorId,
		?string $callUuid = null,
		?int $scheme = null
	): Call
	{
		$providerClass = self::getProviderClass($provider, $type);
		return $providerClass::createWithEntity($type, $provider, $entityType, $entityId, $initiatorId, $callUuid, $scheme);
	}

	/**
	 * @return Call|BitrixCall
	 */
	public static function createWithArray(string $provider, array $fields): Call
	{
		$type = (int)($fields['TYPE'] ?? Call::TYPE_INSTANT);
		$providerClass = self::getProviderClass($provider, $type);
		return $providerClass::createWithArray($fields);
	}

	/**
	 * @return Call|BitrixCall
	 */
	public static function getCallInstance(string $provider, array $fields): Call
	{
		$type = (int)($fields['TYPE'] ?? Call::TYPE_INSTANT);
		$providerClass = self::getProviderClass($provider, $type);
		return $providerClass::createCallInstance($fields);
	}

	/**
	 * @return Call|BitrixCall|null
	 */
	public static function searchActive(int $type, string $provider, string $entityType, string $entityId): ?Call
	{
		$fields = self::search($type, $provider, $entityType, $entityId);
		if ($fields)
		{
			$instance = self::createWithArray($provider, $fields);

			if ($instance->hasActiveUsers(false))
			{
				return $instance;
			}
		}

		return null;
	}

	/**
	 * @return Call|BitrixCall|null
	 */
	public static function searchActiveCall(int $type, string $provider, string $entityType, string $entityId): ?Call
	{
		$fields = self::search($type, $provider, $entityType, $entityId);
		if ($fields)
		{
			$instance = self::getCallInstance($provider, $fields);

			if ($instance->hasActiveUsers(false))
			{
				return $instance;
			}
		}

		return null;
	}

	/**
	 * @return Call|BitrixCall|null
	 */
	public static function searchActiveByUuid(string $provider, string $uuid): ?Call
	{
		$fields = self::searchByUuid($uuid);
		if ($fields)
		{
			return self::createWithArray($provider, $fields);
		}

		return null;
	}

	/**
	 * Gets list active calls of a user on portal.
	 * @return array
	 */
	public static function getUserActiveCalls(int $userId): array
	{
		return array_values(self::getUsersActiveCalls([$userId])[$userId] ?? []);
	}

	/**
	 * Batched counterpart of {@see self::getUserActiveCalls()}: single SELECT
	 * (chunked by 500) joined on CALL_USER, partitioned in PHP by USER_ID.
	 *
	 * Same filter as {@see self::getUserActiveCalls()} (STATE in NEW/INVITING,
	 * START_DATE within depth, CALL_USER.USER_ID in $userIds). Each row is a
	 * full CallTable scalar set (consumable by {@see self::getCallInstance()})
	 * keyed by callId inside the per-user bucket — calls a user shares with
	 * another user of $userIds appear in both buckets, deduplicated by callId
	 * inside each.
	 *
	 * @param int[] $userIds
	 * @return array<int, array<int, array>> [userId => [callId => callRow]]
	 */
	public static function getUsersActiveCalls(array $userIds): array
	{
		$userIds = array_values(array_unique(array_map('intval', $userIds)));
		if ($userIds === [])
		{
			return [];
		}

		$depthHours = Recent::ACTIVE_CALLS_DEPTH_HOURS;
		$date = (new DateTime())->add("-{$depthHours} hour");

		$result = [];
		foreach ($userIds as $uid)
		{
			$result[$uid] = [];
		}

		foreach (array_chunk($userIds, 500) as $chunk)
		{
			$query = CallTable::query()
				->setSelect(array_keys(CallTable::getEntity()->getScalarFields()))
				->addSelect('CALL_USER.USER_ID', 'BX_MATCHED_USER_ID')
				->whereIn('STATE', [\Bitrix\Call\Call::STATE_NEW, \Bitrix\Call\Call::STATE_INVITING])
				->where('START_DATE', '>=', $date)
				->whereIn('CALL_USER.USER_ID', $chunk)
			;
			$rows = $query->exec();
			while ($row = $rows->fetch())
			{
				$matchedUserId = (int)($row['BX_MATCHED_USER_ID'] ?? 0);
				$callId = (int)($row['ID'] ?? 0);
				if ($matchedUserId <= 0 || $callId <= 0)
				{
					continue;
				}
				unset($row['BX_MATCHED_USER_ID']);
				$result[$matchedUserId][$callId] = $row;
			}
		}

		return $result;
	}

	/**
	 * Checks if user has active call.
	 * @param int $targetUserId
	 * @param int $initiatorUserId
	 * @return bool
	 */
	public static function hasUserActiveCalls(int $targetUserId, int $initiatorUserId): bool
	{
		$depthHours = Recent::ACTIVE_CALLS_DEPTH_HOURS;
		$activeCallTimeLimit = (new DateTime())->add("-{$depthHours} hour");

		$query = CallTable::query()
			->addSelect('ID')
			->whereIn('STATE', [\Bitrix\Call\Call::STATE_NEW, \Bitrix\Call\Call::STATE_INVITING])
			->where('START_DATE', '>=', $activeCallTimeLimit)
			->where('CALL_USER.USER_ID', $targetUserId)
			->setLimit(1)
		;

		// call states
		$timeAnswer = (new DateTime())->add("-30 second");
		$conditionCalling = (new ConditionTree())
			->where('CALL_USER.STATE', \Bitrix\Call\CallUser::STATE_CALLING)
			->where('CALL_USER.LAST_SEEN', '>', $timeAnswer)
		;
		$conditionReady = (new ConditionTree())
			->where('CALL_USER.STATE', \Bitrix\Call\CallUser::STATE_READY)
			->whereNotNull('CALL_USER.LAST_SEEN')
			->whereNotNull('CALL_USER.FIRST_JOINED')
		;
		$conditionTree = (new ConditionTree())
			->logic(ConditionTree::LOGIC_OR)
			->where($conditionCalling)
			->where($conditionReady)
		;
		$query->where($conditionTree);

		// plain calls
		$conditionPlain = (new ConditionTree())
			->where('PROVIDER', \Bitrix\Call\Call::PROVIDER_PLAIN)
			->where('ENTITY_TYPE', \Bitrix\Call\Integration\EntityType::CHAT)
			->where((new ConditionTree())
				->logic(ConditionTree::LOGIC_OR)
				->where((new ConditionTree())
					->where('ENTITY_ID', $targetUserId)
					->where('INITIATOR_ID', $initiatorUserId)
				)
				->where((new ConditionTree())
					->where('ENTITY_ID', $initiatorUserId)
					->where('INITIATOR_ID', $targetUserId)
				)
			)
		;
		$query->whereNot($conditionPlain);

		$activeCall = $query->exec()->fetchAll();

		return (bool)$activeCall;
	}

	/**
	 * Checks if user is currently in any active call except the specified one.
	 * Covers STATE_CALLING (active dial, LAST_SEEN within 30s) and STATE_READY (joined, has LAST_SEEN).
	 * @deprecated prefer {@see self::filterUsersInActiveCalls()} when checking more than one user.
	 */
	public static function isUserInActiveCall(int $userId, int $excludeCallId): bool
	{
		return self::filterUsersInActiveCalls([$userId], $excludeCallId) !== [];
	}

	/**
	 * Batched counterpart of {@see self::isUserInActiveCall()}.
	 *
	 * Returns the subset of $userIds that are currently in an active call other
	 * than $excludeCallId. Semantics match isUserInActiveCall — STATE in NEW/INVITING
	 * AND ((CALL_USER.STATE = CALLING AND LAST_SEEN > now-30s) OR
	 *      (CALL_USER.STATE = READY AND LAST_SEEN AND FIRST_JOINED are set)).
	 *
	 * Executes a single SELECT per chunk of 500 userIds (packet-size guard),
	 * instead of one SELECT per user. Safe to call with an empty array — returns
	 * an empty array without touching the DB.
	 *
	 * @param int[] $userIds Users to check
	 * @param int $excludeCallId Call to exclude
	 * @return int[] subset of $userIds that are busy (values only, keys not preserved)
	 */
	public static function filterUsersInActiveCalls(array $userIds, int $excludeCallId): array
	{
		$userIds = array_values(array_unique(array_map('intval', $userIds)));
		if ($userIds === [])
		{
			return [];
		}

		$depthHours = Recent::ACTIVE_CALLS_DEPTH_HOURS;
		$date = (new DateTime())->add("-{$depthHours} hour");
		$timeAnswer = (new DateTime())->add("-30 second");

		$conditionCalling = (new ConditionTree())
			->where('CALL_USER.STATE', \Bitrix\Call\CallUser::STATE_CALLING)
			->where('CALL_USER.LAST_SEEN', '>', $timeAnswer)
		;
		$conditionReady = (new ConditionTree())
			->where('CALL_USER.STATE', \Bitrix\Call\CallUser::STATE_READY)
			->whereNotNull('CALL_USER.LAST_SEEN')
			->whereNotNull('CALL_USER.FIRST_JOINED')
		;
		$stateCondition = (new ConditionTree())
			->logic(ConditionTree::LOGIC_OR)
			->where($conditionCalling)
			->where($conditionReady)
		;

		$busy = [];
		foreach (array_chunk($userIds, 500) as $chunk)
		{
			$rows = CallTable::query()
				->addSelect('CALL_USER.USER_ID', 'USER_ID')
				->whereIn('STATE', [\Bitrix\Call\Call::STATE_NEW, \Bitrix\Call\Call::STATE_INVITING])
				->where('START_DATE', '>=', $date)
				->whereIn('CALL_USER.USER_ID', $chunk)
				->whereNot('ID', $excludeCallId)
				->where($stateCondition)
				->exec()
			;
			while ($row = $rows->fetch())
			{
				$busy[] = (int)$row['USER_ID'];
			}
		}

		return $busy;
	}

	/**
	 * @param int $type
	 * @param string $provider
	 * @param string $entityType
	 * @param string $entityId
	 * @return array|null
	 */
	protected static function search(int $type, string $provider, string $entityType, string $entityId): ?array
	{
		$depthHours = Recent::ACTIVE_CALLS_DEPTH_HOURS;
		$query = CallTable::query()
			->addSelect('*')
			->where('TYPE', $type)
			->where('PROVIDER', $provider)
			->where('ENTITY_TYPE', $entityType)
			->where('ENTITY_ID', $entityId)
			->whereNot('STATE', Call::STATE_FINISHED)
			->whereNull('END_DATE')
			->addFilter('>START_DATE', (new DateTime)->add("-{$depthHours} hours"))
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
		;

		$callFields = $query->exec()->fetch();

		return $callFields ?: null;
	}

	protected static function searchByUuid(string $uuid): ?array
	{
		$callFields = CallTable::query()
			->addSelect("*")
			->where("UUID", $uuid)
			->setLimit(1)
			->exec()
			->fetch()
		;

		return $callFields ?: null;
	}
}
