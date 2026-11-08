<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Sync;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Integration\Call\SyncChatGateway;
use Bitrix\Crm\V2\Internal\Integration\Im\SyncChatCloser;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
final class SyncCreationService
{
	public const ERROR_CHAT_NOT_CREATED = 'SYNC_CHAT_NOT_CREATED';
	public const ERROR_ACTIVITY_NOT_CREATED = 'SYNC_ACTIVITY_NOT_CREATED';
	public const ERROR_CHAT_NOT_CLOSED = 'SYNC_CHAT_NOT_CLOSED';
	public const ERROR_ROLLBACK_INCOMPLETE = 'SYNC_ROLLBACK_INCOMPLETE';

	private SyncAvailability $availability;
	private SyncChatGateway $chatGateway;
	private SyncChatCloser $chatCloser;
	private SyncActivityWriter $activityWriter;
	private LoggerInterface $logger;

	public function __construct(
		?SyncAvailability $availability = null,
		?SyncChatGateway $chatGateway = null,
		?SyncChatCloser $chatCloser = null,
		?SyncActivityWriter $activityWriter = null,
		?LoggerInterface $logger = null,
	)
	{
		$this->chatGateway = $chatGateway ?? new SyncChatGateway();
		$this->availability = $availability ?? new SyncAvailability($this->chatGateway);
		$this->chatCloser = $chatCloser ?? new SyncChatCloser();
		$this->activityWriter = $activityWriter ?? new SyncActivityWriter();
		$this->logger = $logger ?? Container::getInstance()->getLogger('Default');
	}

	/**
	 * @return Result data on success: ['chatId' => int, 'dialogId' => string, 'activityId' => int]
	 */
	public function create(int $ownerTypeId, int $ownerId, int $userId): Result
	{
		$checkResult = $this->check($ownerTypeId, $ownerId, $userId);
		if (!$checkResult->isSuccess())
		{
			return $checkResult;
		}

		$result = new Result();

		$activityResult = $this->activityWriter->add(new ItemIdentifier($ownerTypeId, $ownerId), $userId);
		if (!$activityResult->isSuccess())
		{
			$this->logFailure('The activity of the sync is not created', $activityResult, null, null);

			return $result->addError($this->makeError(self::ERROR_ACTIVITY_NOT_CREATED));
		}
		$activityId = (int)$activityResult->getData()['activityId'];

		$chatResult = $this->chatGateway->createChat($userId, $activityId);
		if (!$chatResult->isSuccess())
		{
			$this->logFailure('The chat of the sync is not created', $chatResult, $activityId, null);
			$result->addError($this->makeError(self::ERROR_CHAT_NOT_CREATED));
			if (!$this->deleteActivity($activityId, null, $result))
			{
				$this->logIncompleteRollback($activityId, null);
			}

			return $result;
		}
		$chatId = (int)$chatResult->getData()['chatId'];

		$setChatIdResult = $this->activityWriter->setChatId($activityId, $chatId);
		if (!$setChatIdResult->isSuccess())
		{
			$this->logFailure('The chat is not written to the activity of the sync', $setChatIdResult, $activityId, $chatId);
			$result->addError($this->makeError(self::ERROR_ACTIVITY_NOT_CREATED));
			$this->rollbackChatAndActivity($activityId, $chatId, $result);

			return $result;
		}

		return $result->setData([
			'chatId' => $chatId,
			'dialogId' => (string)$chatResult->getData()['dialogId'],
			'activityId' => $activityId,
		]);
	}

	private function check(int $ownerTypeId, int $ownerId, int $userId): Result
	{
		$result = $this->availability->check($ownerTypeId);
		if (!$result->isSuccess())
		{
			return $result;
		}

		// a missing item is refused as an unreadable one, so that its absence reveals nothing
		$factory = Container::getInstance()->getFactory($ownerTypeId);
		$permissions = Container::getInstance()->getUserPermissions($userId);
		if (
			!$factory
			|| !$factory->getItem($ownerId, ['ID'])
			|| !$permissions->item()->canRead($ownerTypeId, $ownerId)
			|| !$permissions->activity()->canAddToOwner($ownerTypeId, $ownerId)
		)
		{
			return $result->addError(ErrorCode::getAccessDeniedError());
		}

		return $result;
	}

	private function rollbackChatAndActivity(int $activityId, int $chatId, Result $result): void
	{
		$detachResult = $this->chatCloser->detach($chatId);
		$isChatDetached = $detachResult->isSuccess();
		if (!$isChatDetached)
		{
			$this->logFailure('The chat of the sync is not detached', $detachResult, $activityId, $chatId);
			$result->addError($this->makeError(self::ERROR_CHAT_NOT_CLOSED));
		}

		$deleteChatResult = $this->chatCloser->delete($chatId);
		if (!$deleteChatResult->isSuccess())
		{
			$this->logFailure('The chat of the sync is not deleted', $deleteChatResult, $activityId, $chatId);
		}

		$isActivityDeleted = $this->deleteActivity($activityId, $chatId, $result);

		if (!$isChatDetached && !$isActivityDeleted)
		{
			$this->logger->error(
				'{method}: the activity #{activityId} of the sync stays and the chat #{chatId} is bound to it'
				. ' until the messenger deletes the chat',
				[
					'method' => __METHOD__,
					'activityId' => $activityId,
					'chatId' => $chatId,
				],
			);
		}
		elseif (!$isChatDetached || !$isActivityDeleted)
		{
			$this->logIncompleteRollback($activityId, $chatId);
		}
	}

	private function deleteActivity(int $activityId, ?int $chatId, Result $result): bool
	{
		$deleteResult = $this->activityWriter->delete($activityId);
		if ($deleteResult->isSuccess())
		{
			return true;
		}

		$this->logFailure('The activity of the sync is not deleted', $deleteResult, $activityId, $chatId);
		$result->addError($this->makeError(self::ERROR_ROLLBACK_INCOMPLETE));

		return false;
	}

	private function logIncompleteRollback(int $activityId, ?int $chatId): void
	{
		$this->logger->warning(
			'{method}: the sync is rolled back incompletely, activity #{activityId}, chat #{chatId}',
			[
				'method' => __METHOD__,
				'activityId' => $activityId,
				'chatId' => $chatId,
			],
		);
	}

	private function logFailure(string $message, Result $failure, ?int $activityId, ?int $chatId): void
	{
		$this->logger->warning(
			'{method}: {message}, activity #{activityId}, chat #{chatId}: {errors}',
			[
				'method' => __METHOD__,
				'message' => $message,
				'activityId' => $activityId,
				'chatId' => $chatId,
				'errors' => implode('; ', $failure->getErrorMessages()),
			],
		);
	}

	private function makeError(string $code): Error
	{
		$messageCodes = [
			self::ERROR_CHAT_NOT_CREATED => 'CRM_SYNC_CREATION_ERROR_CHAT_NOT_CREATED',
			self::ERROR_ACTIVITY_NOT_CREATED => 'CRM_SYNC_CREATION_ERROR_ACTIVITY_NOT_CREATED',
			self::ERROR_CHAT_NOT_CLOSED => 'CRM_SYNC_CREATION_ERROR_CHAT_NOT_CLOSED',
			self::ERROR_ROLLBACK_INCOMPLETE => 'CRM_SYNC_CREATION_ERROR_ROLLBACK_INCOMPLETE',
		];

		return new Error((string)Loc::getMessage($messageCodes[$code]), $code);
	}
}
