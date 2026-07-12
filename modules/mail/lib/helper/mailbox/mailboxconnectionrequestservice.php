<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Mailbox;

use Bitrix\Mail\Helper\Enum\MailboxConnectionRequestStatus;
use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Internal\Async\Message\RepairConnectionRequestChatsMessage;
use Bitrix\Mail\Integration\Im\ConnectionRequestChat;
use Bitrix\Mail\Internals\MailboxConnectionRequestTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Bitrix\Pull\Event;

final class MailboxConnectionRequestService
{
	public const ERROR_LIMIT_EXCEEDED = 'MAIL_CONNECTION_REQUEST_LIMIT_EXCEEDED';
	public const ERROR_MESSAGE_DELIVERY_FAILED = 'MAIL_CONNECTION_REQUEST_MESSAGE_DELIVERY_FAILED';
	private const ERROR_ACCESS_DENIED = 'MAIL_CONNECTION_REQUEST_ACCESS_DENIED';
	private const CONNECTION_REQUEST_RESPONSIBLE_ADMIN_ID_OPTION = 'connection_request_responsible_admin_id';

	private const PENDING_COUNT_CACHE_TTL = 3600;
	private const PENDING_COUNT_CACHE_KEY = 'mailbox_connection_request_pending_count';
	private const PENDING_COUNT_CACHE_TAG = 'mail_mailbox_connection_request_pending_count';
	private const PENDING_COUNT_CACHE_DIR_BASE = '/mail/mailbox_connection_request_pending_count';

	private int $userId;

	public function __construct(?int $userId = null)
	{
		$this->userId = $userId ?? (int)CurrentUser::get()->getId();
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function isResponsibleAdmin(): bool
	{
		return in_array($this->userId, $this->getResponsible(), true);
	}

	public function createRequest(string $comment = ''): Result
	{
		$result = new Result();

		if ($this->hasActivePendingRequest($this->userId))
		{
			$result->setData(['isRepeat' => true]);

			return $result;
		}

		$addResult = $this->addPendingRequest($this->userId);
		if (!$addResult->isSuccess())
		{
			$result->addErrors($addResult->getErrors());

			return $result;
		}

		$this->invalidateAndNotifyPendingCountChange();

		$adminIds = $this->getResponsible();

		$chatResult = $this->getOrCreateChatForRequest([
			'ID' => $addResult->getId(),
			'REQUESTER_ID' => $this->userId,
			'CHAT_ID' => 0,
		], $adminIds);
		if ($chatResult->isSuccess())
		{
			$chat = new ConnectionRequestChat();
			$chatId = (int)($chatResult->getData()['chatId'] ?? 0);
			try
			{
				$chat->sendRequestMessage($chatId, $this->userId, $comment);
			}
			catch (\Throwable $exception)
			{
				Application::getInstance()->getExceptionHandler()->writeToLog($exception);
				MailboxConnectionRequestTable::delete($addResult->getId());
				$this->invalidateAndNotifyPendingCountChange();
				$result->addError(
					new Error('Failed to send connection request message', self::ERROR_MESSAGE_DELIVERY_FAILED),
				);

				return $result;
			}
		}

		$result->setData([
			'isRepeat' => false,
			'requestId' => $addResult->getId(),
		]);

		return $result;
	}

	public function rejectRequest(int $requestId): Result
	{
		$validationResult = $this->validatePendingRequest($requestId);
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		$request = $validationResult->getData()['request'];

		$updateResult = $this->updateRequestStatus($requestId, MailboxConnectionRequestStatus::Rejected, [
			'ADMIN_ID' => $this->userId,
		]);

		if (!$updateResult->isSuccess())
		{
			return (new Result())->addErrors($updateResult->getErrors());
		}

		$pendingCount = $this->invalidateAndNotifyPendingCountChange();

		$chatId = $this->getOrCreateChatIdForRequest($request);
		if ($chatId > 0)
		{
			$chat = new ConnectionRequestChat();
			$chat->sendRejectedMessage($chatId, $this->userId);
		}

		return (new Result())->setData(['pendingCount' => $pendingCount]);
	}

	public function completeRequest(int $requestId, int $mailboxId): Result
	{
		$validationResult = $this->validatePendingRequest($requestId);
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		$request = $validationResult->getData()['request'];

		$updateResult = $this->updateRequestStatus($requestId, MailboxConnectionRequestStatus::Connected, [
			'MAILBOX_ID' => $mailboxId,
			'ADMIN_ID' => $this->userId,
		]);

		if (!$updateResult->isSuccess())
		{
			return (new Result())->addErrors($updateResult->getErrors());
		}

		$mailbox = \Bitrix\Mail\MailboxTable::getList([
			'select' => ['EMAIL'],
			'filter' => ['=ID' => $mailboxId],
			'limit' => 1,
		])->fetch();

		$pendingCount = $this->invalidateAndNotifyPendingCountChange();

		$chatId = $this->getOrCreateChatIdForRequest($request);
		if ($chatId > 0 && $mailbox)
		{
			$chat = new ConnectionRequestChat();
			$chat->sendCompletedMessage($chatId, $this->userId, $mailbox['EMAIL']);
		}

		return (new Result())->setData(['pendingCount' => $pendingCount]);
	}

	public function getOwnPendingRequest(): Result
	{
		$row = MailboxConnectionRequestTable::query()
			->setSelect(['ID'])
			->where('REQUESTER_ID', $this->userId)
			->where('STATUS', MailboxConnectionRequestStatus::Pending->value)
			->setLimit(1)
			->fetch()
		;

		return (new Result())->setData([
			'hasActiveRequest' => (bool)$row,
			'canSendRequest' => !$this->isResponsibleAdmin(),
		]);
	}

	public function cancelOwnRequest(): Result
	{
		$request = MailboxConnectionRequestTable::getList([
			'filter' => [
				'=REQUESTER_ID' => $this->userId,
				'=STATUS' => MailboxConnectionRequestStatus::Pending->value,
			],
			'limit' => 1,
			'select' => ['ID', 'CHAT_ID', 'REQUESTER_ID'],
		])->fetch();

		if (!$request)
		{
			return (new Result())->addError(new Error('No pending request found'));
		}

		$requestId = (int)$request['ID'];

		$updateResult = $this->updateRequestStatus($requestId, MailboxConnectionRequestStatus::Cancelled);

		if (!$updateResult->isSuccess())
		{
			return (new Result())->addErrors($updateResult->getErrors());
		}

		$this->invalidateAndNotifyPendingCountChange();

		$chatId = $this->getOrCreateChatIdForRequest($request);
		if ($chatId > 0)
		{
			$chat = new ConnectionRequestChat();
			$chat->sendCancelledMessage($chatId, (int)$request['REQUESTER_ID']);
		}

		if (Loader::includeModule('pull'))
		{
			$adminIds = $this->getResponsible();
			Event::add($adminIds, [
				'module_id' => 'mail',
				'command' => 'connection_request_cancelled',
				'params' => [
					'requestId' => $requestId,
				],
			]);
		}

		return new Result();
	}

	/**
	 * @return array<array{
	 *     ID: int,
	 *     REQUESTER_ID: int,
	 *     CREATED_AT: \Bitrix\Main\Type\DateTime
	 * }>
	 */
	public function getPendingRequestsPaginated(int $limit, int $offset): array
	{
		return MailboxConnectionRequestTable::query()
			->setSelect(['ID', 'REQUESTER_ID', 'CREATED_AT'])
			->where('STATUS', MailboxConnectionRequestStatus::Pending->value)
			->setOrder(['CREATED_AT' => 'DESC'])
			->setLimit($limit)
			->setOffset($offset)
			->fetchAll()
		;
	}

	public function getPendingCountForController(): Result
	{
		$result = new Result();

		$result->setData([
			'count' => $this->getPendingCount(),
		]);

		return $result;
	}

	public function getPendingCount(): int
	{
		if (!$this->isResponsibleAdmin())
		{
			return 0;
		}

		return $this->getPendingCountRaw();
	}

	public function getPendingCountRaw(): int
	{
		$cache = Cache::createInstance();
		$dir = self::getPendingCountCacheDir();

		if ($cache->initCache(self::PENDING_COUNT_CACHE_TTL, self::PENDING_COUNT_CACHE_KEY, $dir))
		{
			return max(0, (int)$cache->getVars());
		}

		$cache->startDataCache();

		$taggedCache = Application::getInstance()->getTaggedCache();
		$taggedCache->startTagCache($dir);
		$taggedCache->registerTag(self::PENDING_COUNT_CACHE_TAG);

		$count = (int)MailboxConnectionRequestTable::getCount(
			['=STATUS' => MailboxConnectionRequestStatus::Pending->value],
		);

		$taggedCache->endTagCache();
		$cache->endDataCache($count);

		return $count;
	}

	public function getRequestById(int $requestId): ?array
	{
		$row = MailboxConnectionRequestTable::getById($requestId)->fetch();

		return $row ?: null;
	}

	public function getResponsibleAdminId(): int
	{
		return (int)Option::get('mail', self::CONNECTION_REQUEST_RESPONSIBLE_ADMIN_ID_OPTION, '0');
	}

	public function setResponsibleAdminId(int $adminId): void
	{
		Option::set('mail', self::CONNECTION_REQUEST_RESPONSIBLE_ADMIN_ID_OPTION, (string)$adminId);
	}

	public static function resetResponsibleAdminIfNeeded(int $userId): void
	{
		$service = new self($userId);

		if ($service->getResponsibleAdminId() !== $userId)
		{
			return;
		}

		Option::delete('mail', ['name' => self::CONNECTION_REQUEST_RESPONSIBLE_ADMIN_ID_OPTION]);

		$pendingCount = $service->getPendingCountRaw();
		if ($pendingCount <= 0)
		{
			return;
		}

		$adminIds = $service->getResponsible();
		$adminIds = array_filter($adminIds, static fn (int $id) => $id !== $userId);

		if (empty($adminIds))
		{
			return;
		}

		foreach ($adminIds as $adminId)
		{
			Message::setUserUnseenCounter($adminId, SITE_ID);
		}

		if (Loader::includeModule('pull'))
		{
			Event::add(array_values($adminIds), [
				'module_id' => 'mail',
				'command' => 'connection_request_count_changed',
				'params' => [
					'pendingCount' => $pendingCount,
				],
			]);
		}

		(new MailboxGridButtonCounterRefreshService())->sendToUsers(array_values($adminIds));

		self::dispatchPendingRequestChatsRepair();
	}

	public function repairPendingRequestChats(): void
	{
		$adminIds = array_values($this->getResponsible());
		if (empty($adminIds))
		{
			return;
		}

		$chat = new ConnectionRequestChat();
		$pendingRequests = MailboxConnectionRequestTable::getList([
			'filter' => ['=STATUS' => MailboxConnectionRequestStatus::Pending->value],
			'select' => ['ID', 'CHAT_ID', 'REQUESTER_ID'],
		]);

		while ($pendingRequest = $pendingRequests->fetch())
		{
			try
			{
				$this->repairPendingRequestChat($pendingRequest, $adminIds, $chat);
			}
			catch (\Throwable $exception)
			{
				Application::getInstance()->getExceptionHandler()->writeToLog($exception);
			}
		}
	}

	public function getResponsible(): array
	{
		$responsibleId = $this->getResponsibleAdminId();
		if ($responsibleId > 0)
		{
			$activeResponsibleIds = $this->filterActiveUserIds([$responsibleId]);
			if (!empty($activeResponsibleIds))
			{
				return $activeResponsibleIds;
			}
		}

		return $this->filterActiveUserIds($this->getAllAdminIds());
	}

	private function getAllAdminIds(): array
	{
		$adminIds = [];
		if (Loader::includeModule('bitrix24'))
		{
			$adminIdsRaw = \CBitrix24::getAllAdminId();
			foreach ($adminIdsRaw as $adminIdRaw)
			{
				$adminIds[] = (int)$adminIdRaw;
			}

			return array_values(array_unique($adminIds));
		}

		$res = \CGroup::getGroupUserEx(1);
		while ($row = $res->fetch())
		{
			$adminIds[] = (int)$row['USER_ID'];
		}

		return array_values(array_unique($adminIds));
	}

	public function hasActivePendingRequest(int $requesterId): bool
	{
		$row = MailboxConnectionRequestTable::getList([
			'filter' => [
				'=REQUESTER_ID' => $requesterId,
				'=STATUS' => MailboxConnectionRequestStatus::Pending->value,
			],
			'limit' => 1,
			'select' => ['ID'],
		])->fetch();

		return $row !== false;
	}

	private function invalidateAndNotifyPendingCountChange(): int
	{
		Application::getInstance()->getTaggedCache()->clearByTag(self::PENDING_COUNT_CACHE_TAG);

		$pendingCount = $this->getPendingCountRaw();

		$adminIds = $this->getResponsible();
		foreach ($adminIds as $adminId)
		{
			Message::setUserUnseenCounter($adminId, SITE_ID);
		}

		if (Loader::includeModule('pull'))
		{
			Event::add($adminIds, [
				'module_id' => 'mail',
				'command' => 'connection_request_count_changed',
				'params' => [
					'pendingCount' => $pendingCount,
				],
			]);
		}

		(new MailboxGridButtonCounterRefreshService())->sendToUsers($adminIds);

		return $pendingCount;
	}

	private static function getPendingCountCacheDir(): string
	{
		$hashPrefix = substr(md5(self::PENDING_COUNT_CACHE_KEY), 2, 2);

		return self::PENDING_COUNT_CACHE_DIR_BASE . '/' . $hashPrefix . '/' . self::PENDING_COUNT_CACHE_KEY . '/';
	}

	private static function dispatchPendingRequestChatsRepair(): void
	{
		(new RepairConnectionRequestChatsMessage())->send('mail_connection_request_chats_repair');
	}

	private function filterActiveUserIds(array $userIds): array
	{
		$userIds = array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn (int $userId): bool => $userId > 0,
		)));

		if (empty($userIds))
		{
			return [];
		}

		$activeUserIdMap = [];
		$users = UserTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $userIds)
			->where('ACTIVE', 'Y')
			->where(
				Query::filter()
					->logic('or')
					->where('CONFIRM_CODE', '')
					->whereNull('CONFIRM_CODE'),
			)
			->exec()
		;

		while ($user = $users->fetch())
		{
			$activeUserIdMap[(int)$user['ID']] = true;
		}

		return array_values(array_filter(
			$userIds,
			static fn (int $userId): bool => isset($activeUserIdMap[$userId]),
		));
	}

	private function getOrCreateChatIdForRequest(array $request, ?array $adminIds = null): int
	{
		$chatResult = $this->getOrCreateChatForRequest($request, $adminIds);

		return (int)($chatResult->getData()['chatId'] ?? 0);
	}

	private function repairPendingRequestChat(array $request, array $adminIds, ConnectionRequestChat $chat): void
	{
		$chatResult = $this->getOrCreateChatForRequest($request, $adminIds);
		if (!$chatResult->isSuccess())
		{
			return;
		}

		$newChatId = (int)($chatResult->getData()['chatId'] ?? 0);
		if ($newChatId <= 0)
		{
			return;
		}

		if (!(bool)($chatResult->getData()['needsRequestMessage'] ?? false))
		{
			return;
		}

		$requesterId = (int)($request['REQUESTER_ID'] ?? 0);
		$chat->sendRequestMessage($newChatId, $requesterId, '');
	}

	private function getOrCreateChatForRequest(array $request, ?array $adminIds = null): Result
	{
		$result = new Result();

		$requestId = (int)($request['ID'] ?? 0);
		$requesterId = (int)($request['REQUESTER_ID'] ?? 0);
		$adminIds = array_values($adminIds ?? $this->getResponsible());

		if ($requestId <= 0 || $requesterId <= 0 || empty($adminIds))
		{
			return $result;
		}

		$chat = new ConnectionRequestChat();
		$chatResult = $chat->getOrCreateValidChat($requesterId, $adminIds);
		if (!$chatResult->isSuccess())
		{
			return $result->addErrors($chatResult->getErrors());
		}

		$chatId = (int)($chatResult->getData()['chatId'] ?? 0);
		if ($chatId <= 0)
		{
			return $result;
		}

		if ($chatId !== (int)($request['CHAT_ID'] ?? 0))
		{
			MailboxConnectionRequestTable::update($requestId, ['CHAT_ID' => $chatId]);
		}

		$chat->ensureAdminsInChat($chatId, $adminIds);

		return $result->setData($chatResult->getData());
	}

	private function addPendingRequest(int $requesterId): AddResult
	{
		return MailboxConnectionRequestTable::add([
			'REQUESTER_ID' => $requesterId,
			'STATUS' => MailboxConnectionRequestStatus::Pending->value,
			'CREATED_AT' => new DateTime(),
			'UPDATED_AT' => new DateTime(),
		]);
	}

	private function updateRequestStatus(
		int $requestId,
		MailboxConnectionRequestStatus $status,
		array $fields = [],
	): UpdateResult
	{
		$fields['STATUS'] = $status->value;
		$fields['UPDATED_AT'] = new DateTime();

		return MailboxConnectionRequestTable::update($requestId, $fields);
	}

	private function validatePendingRequest(int $requestId): Result
	{
		$result = new Result();

		if (!$this->isResponsibleAdmin())
		{
			$result->addError(new Error('Access denied', self::ERROR_ACCESS_DENIED));

			return $result;
		}

		$request = $this->getRequestById($requestId);
		if ($request === null)
		{
			$result->addError(new Error('Request not found'));

			return $result;
		}

		if ($request['STATUS'] !== MailboxConnectionRequestStatus::Pending->value)
		{
			$result->addError(new Error('Request is not pending'));

			return $result;
		}

		$result->setData(['request' => $request]);

		return $result;
	}
}
