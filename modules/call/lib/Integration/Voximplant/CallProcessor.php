<?php

namespace Bitrix\Call\Integration\Voximplant;

use Bitrix\Call\Service\CallLogService;
use Bitrix\Call\Model\CallUserLogTable;
use CVoxImplantMain;

class CallProcessor
{
	// Voximplant call statuses
	private const CALL_STATUS_SUCCESS = 1;

	// Voximplant call failed codes
	private const CALL_FAILED_CODE_SUCCESS = 200;           // Successful call
	private const CALL_FAILED_CODE_MISSED = 304;            // Not answered / missed
	private const CALL_FAILED_CODE_DECLINED = 603;          // Declined
	private const CALL_FAILED_CODE_BUSY = 486;              // Busy
	private const CALL_FAILED_CODE_BLACKLIST = 423;         // Blacklisted
	private const CALL_FAILED_CODE_CANCEL = 487;            // Request terminated
	private const CALL_FAILED_CODE_TIMEOUT = 408;           // Request timeout
	private const CALL_FAILED_CODE_UNAVAILABLE = 480;       // Temporarily unavailable

	/**
	 * Process Voximplant call and create call log entries
	 *
	 * @param array $eventData Event data containing 'fields' and 'participants'
	 */
	public function processCall(array $eventData): void
	{
		$statisticRecord = $eventData['fields'] ?? $eventData;
		$participants = $eventData['participants'] ?? null;
		$initiatorId = $eventData['initiatorId'] ?? 0;

		// Get participants from event data or fallback to PORTAL_USER_ID
		$callUsers = [];
		if (is_array($participants) && !empty($participants))
		{
			$callUsers = $participants;
		}
		else
		{
			// Fallback to PORTAL_USER_ID if no participants provided
			$portalUserId = (int)$statisticRecord['PORTAL_USER_ID'];
			if ($portalUserId)
			{
				$callUsers = [$portalUserId];
			}
			else
			{
				return;
			}
		}

		$callLogService = new CallLogService();

		// Process each participant
		foreach ($callUsers as $userId)
		{
			$userId = (int)$userId;
			if (!$userId)
			{
				continue;
			}

			$status = $this->mapVoximplantStatusToCallLog(
				$statisticRecord,
				$userId,
				$initiatorId
			);

			if (!$status)
			{
				continue; // Skip calls that don't need to be logged for this user
			}

			// Create call log entry for the user
			$callLogService->addOrUpdateEvent(
				CallUserLogTable::SOURCE_TYPE_VOXIMPLANT,
				(int)$statisticRecord['ID'],
				$userId,
				$status,
				$statisticRecord['CALL_START_DATE']
			);
		}
	}

	/**
	 * Map Voximplant call status to Call Log status
	 *
	 * @param array $statisticRecord
	 * @param int $userId User ID to determine the status for
	 * @param int $initiatorId Initiator user ID
	 * @return string|null
	 */
	private function mapVoximplantStatusToCallLog(array $statisticRecord, int $userId, int $initiatorId): ?string
	{
		$incoming = (int)$statisticRecord['INCOMING'];
		$callStatus = (int)$statisticRecord['CALL_STATUS'];
		$callDuration = (int)$statisticRecord['CALL_DURATION'];
		$callFailedCode = (int)($statisticRecord['CALL_FAILED_CODE'] ?? 0);

		// Info calls - skip
		if ($incoming === CVoxImplantMain::CALL_INFO)
		{
			return null;
		}

		$isInitiator = ($initiatorId === $userId);

		// Outgoing calls - initiator gets "initiated", receiver gets based on result
		if ($incoming === CVoxImplantMain::CALL_OUTGOING || $incoming === CVoxImplantMain::CALL_CALLBACK)
		{
			if ($isInitiator)
			{
				return CallUserLogTable::STATUS_INITIATED;
			}

			// For receiver in outgoing call (internal call scenario)
			if ($callFailedCode === self::CALL_FAILED_CODE_SUCCESS || $callDuration > 0)
			{
				return CallUserLogTable::STATUS_ANSWERED;
			}

			return CallUserLogTable::STATUS_MISSED;
		}

		// Incoming calls - different logic for initiator vs other participants
		if ($incoming === CVoxImplantMain::CALL_INCOMING || $incoming === CVoxImplantMain::CALL_INCOMING_REDIRECT)
		{
			// Blacklisted - skip logging
			if ($callFailedCode === self::CALL_FAILED_CODE_BLACKLIST)
			{
				return null;
			}

			// Check failed codes first, before checking duration
			// Declined (explicitly or busy)
			if (in_array($callFailedCode, [
				self::CALL_FAILED_CODE_DECLINED,
				self::CALL_FAILED_CODE_BUSY
			]))
			{
				return CallUserLogTable::STATUS_DECLINED;
			}

			// Cancelled / timeout / unavailable / missed - treat as missed
			if (in_array($callFailedCode, [
				self::CALL_FAILED_CODE_CANCEL,
				self::CALL_FAILED_CODE_TIMEOUT,
				self::CALL_FAILED_CODE_UNAVAILABLE,
				self::CALL_FAILED_CODE_MISSED
			]))
			{
				return CallUserLogTable::STATUS_MISSED;
			}

			// Successful call - answered (check code 200 or duration only for success status)
			if ($callFailedCode === self::CALL_FAILED_CODE_SUCCESS)
			{
				return CallUserLogTable::STATUS_ANSWERED;
			}

			// Fallback: check call status and duration
			if ($callStatus === self::CALL_STATUS_SUCCESS && $callDuration > 0)
			{
				return CallUserLogTable::STATUS_ANSWERED;
			}

			return CallUserLogTable::STATUS_MISSED;
		}

		return null;
	}
}
