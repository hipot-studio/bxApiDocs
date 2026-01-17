<?php

namespace Bitrix\Call\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Call\Service\CallLogService;

class CallLog extends Controller
{
	private const DEFAULT_LIMIT = 50;
	private const MAX_LIMIT = 100;

	private CallLogService $service;

	public function __construct()
	{
		parent::__construct();
		$this->service = new CallLogService();
	}

	/**
	 * Get list of call events for current user
	 *
	 * @restMethod call.CallLog.list
	 *
	 * @param array $filter Filter parameters (STATUS, TYPE)
	 * @param int $lastId Last ID for pagination
	 * @param int $count Number of records to return (default 50, max 100)
	 * @return array|null
	 */
	public function listAction(array $filter = [], int $lastId = 0, int $count = self::DEFAULT_LIMIT): ?array
	{
		$userId = $this->getCurrentUser()->getId();
		if (!$userId)
		{
			$this->addError(new Error('User not authorized', 'USER_NOT_AUTHORIZED'));
			return null;
		}

		// Validate and normalize count
		$count = max(1, min($count, self::MAX_LIMIT));

		try
		{
			// Get call events
			$calls = $this->service->getList($userId, $filter, $lastId, $count);

			// Enrich with call data
			foreach ($calls as &$call)
			{
				$call['callData'] = $this->service->getCallData($call, $userId);
			}

			// Get missed counter
			$missedCounter = $this->service->getMissedCounter($userId);
			return [
				'calls' => $calls,
				'missedCounter' => $missedCounter
			];
		}
		catch (\Exception $e)
		{
			$this->addError(new Error('Internal error', 'INTERNAL_ERROR'));
			return null;
		}
	}

	/**
	 * Mark calls as seen
	 *
	 * @restMethod call.CallLog.markAsSeen
	 *
	 * @param array $callIds Array of call log IDs
	 * @return int|null New counter value
	 */
	public function markAsSeenAction(array $callIds = []): ?int
	{
		$userId = $this->getCurrentUser()->getId();
		if (!$userId)
		{
			$this->addError(new Error('User not authorized', 'USER_NOT_AUTHORIZED'));
			return null;
		}

		try
		{
			return $this->service->markAsSeen($userId, $callIds);
		}
		catch (\Exception $e)
		{
			$this->addError(new Error('Internal error', 'INTERNAL_ERROR'));
			return null;
		}
	}

	/**
	 * Mark all calls as seen for current user
	 *
	 * @restMethod call.CallLog.markAllAsSeen
	 *
	 * @return int|null New counter value (should be 0)
	 */
	public function markAllAsSeenAction(): ?int
	{
		$userId = $this->getCurrentUser()->getId();
		if (!$userId)
		{
			$this->addError(new Error('User not authorized', 'USER_NOT_AUTHORIZED'));
			return null;
		}

		try
		{
			return $this->service->markAllAsSeen($userId);
		}
		catch (\Exception $e)
		{
			$this->addError(new Error('Internal error', 'INTERNAL_ERROR'));
			return null;
		}
	}

	/**
	 * Delete call log entry for current user
	 *
	 * @restMethod call.CallLog.delete
	 *
	 * @param int $callId Call log ID to delete
	 * @return array|null Result with deletion status and new missed counter
	 */
	public function deleteAction(int $callId = 0): ?array
	{
		$userId = $this->getCurrentUser()->getId();
		if (!$userId)
		{
			$this->addError(new Error('User not authorized', 'USER_NOT_AUTHORIZED'));
			return null;
		}

		if ($callId <= 0)
		{
			$this->addError(new Error('Invalid call ID', 'INVALID_CALL_ID'));
			return null;
		}

		try
		{
			$deleted = $this->service->deleteEntry($userId, $callId);
			$newCounter = $this->service->getMissedCounter($userId);

			return [
				'deleted' => $deleted,
				'missedCounter' => $newCounter
			];
		}
		catch (\Exception $e)
		{
			$this->addError(new Error('Internal error', 'INTERNAL_ERROR'));
			return null;
		}
	}

	/**
	 * Get chat ID between current user and specified user
	 *
	 * @restMethod call.CallLog.getChat
	 *
	 * @param int $userId User ID to get chat with
	 * @return array|null Result with chatId
	 */
	public function getChatAction(int $userId = 0): ?array
	{
		$currentUserId = $this->getCurrentUser()->getId();
		if (!$currentUserId)
		{
			$this->addError(new Error('User not authorized', 'USER_NOT_AUTHORIZED'));
			return null;
		}

		if ($userId <= 0)
		{
			$this->addError(new Error('Invalid user ID', 'INVALID_USER_ID'));
			return null;
		}

		if (!Loader::includeModule('im'))
		{
			return null;
		}
		$chatId = \Bitrix\Im\Dialog::getChatId($userId);

		return ['chatId' => $chatId];
	}
}
