<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Sync;

use Bitrix\Crm\Activity\Provider\Sync;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Integration\Call\SyncCallsGateway;
use Bitrix\Crm\V2\Internal\Integration\Im\SyncChatAccess;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepositoryInterface;
use Bitrix\Main\Result;
use Closure;

/**
 * Calls of the chat of a Sync activity that a user may open, for the card of the activity.
 *
 * @internal
 */
final class SyncCallListService
{
	public const CALL_LIMIT = 20;

	private const CHAT_ID_COLUMN = 'ASSOCIATED_ENTITY_ID';

	private SyncCallsGateway $calls;
	private SyncChatAccess $chatAccess;
	private Closure $canReadOwner;
	private ActivityRepositoryInterface $activities;

	public function __construct(
		?SyncCallsGateway $calls = null,
		?SyncChatAccess $chatAccess = null,
		?Closure $canReadOwner = null,
		?ActivityRepositoryInterface $activities = null,
	)
	{
		$this->calls = $calls ?? new SyncCallsGateway();
		$this->chatAccess = $chatAccess ?? new SyncChatAccess();
		$this->canReadOwner = $canReadOwner
			?? static fn(int $userId, int $activityId): bool
				=> Container::getInstance()->getUserPermissions($userId)->activity()->canRead($activityId)
		;
		$this->activities = $activities ?? new ActivityRepository();
	}

	/**
	 * @return Result data: array{items: list<array{callId: int, startDate: ?int, hasFollowUp: bool, detailUrl: ?string}>, hasOlderCalls: bool, canOpenChat: bool}
	 */
	public function list(int $userId, int $activityId): Result
	{
		$result = new Result();

		$state = $activityId > 0 ? $this->activities->getStateById($activityId) : null;
		/** @var Activity|null $activity */
		$activity = $state[ActivityRepositoryInterface::STATE_ACTIVITY] ?? null;
		if ($activity === null || $activity->getProviderId() !== Sync::PROVIDER_ID)
		{
			return $result->addError(ErrorCode::getNotFoundError());
		}

		if (!($this->canReadOwner)($userId, $activityId))
		{
			return $result->addError(ErrorCode::getAccessDeniedError());
		}

		// The chat is a provider column: the model of the repository does not hold it, the transit does.
		$chatId = (int)$state[ActivityRepositoryInterface::STATE_TRANSIT]->get(self::CHAT_ID_COLUMN);
		$canOpenChat = $this->chatAccess->canOpen($chatId, $userId);
		if ($chatId <= 0 || !$this->calls->isAvailable())
		{
			return $result->setData([
				'items' => [],
				'hasOlderCalls' => false,
				'canOpenChat' => $canOpenChat,
			]);
		}

		$calls = $this->calls->listAccessibleByChat($chatId, $userId, self::CALL_LIMIT, $canOpenChat);
		if (!$calls->isSuccess())
		{
			return $result->addErrors($calls->getErrors());
		}

		$data = $calls->getData();

		return $result->setData([
			'items' => $data['items'] ?? [],
			'hasOlderCalls' => (bool)($data['hasOlderCalls'] ?? false),
			'canOpenChat' => $canOpenChat,
		]);
	}
}
