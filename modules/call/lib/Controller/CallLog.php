<?php

namespace Bitrix\Call\Controller;

use Bitrix\Call\Controller\ActionFilter\DenyGuest;
use Bitrix\Call\Controller\ActionFilter\SameOrigin;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Call\Service\CallLogService;

/**
 * @internal
 */
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

	public function configureActions(): array
	{
		// The journal is the only thing a guest is given here: reading it and clearing their own badge.
		// Everything else is denied at the action, because the guest allow-list it used to rely on covers
		// the REST transport alone (@see DenyGuest).
		$denyGuest = ['+prefilters' => [new DenyGuest()]];

		return [
			// A guest is authorised by the session cookie alone (@see \Bitrix\Im\V2\Guest\Auth\GuestRestAuth),
			// and the Csrf filter does not run in the REST scope. POST takes away the GET navigation, Origin
			// takes away the cross-site form; the clients already send POST and no Origin or a bare portal host.
			'markAllAsSeen' => [
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new SameOrigin(),
				],
			],
			'markAsSeen' => $denyGuest,
			'delete' => $denyGuest,
			'getChat' => $denyGuest,
		];
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
			// Get call events, enriched and filled up to $count. For a guest an IM call they may no longer
			// open comes back without data (see CallLogService::getImCallData): such an entry is dropped
			// rather than shown nameless, and the page is refilled from behind rather than handed over short
			// (see CallLogService::getPage) - the clients page by the size of the answer.
			// The drop is guest-only on purpose - for everyone else an entry without data is what the journal
			// has always shown, and changing that belongs to its own task.
			$calls = $this->service->getPage($userId, $filter, $lastId, $count);

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
