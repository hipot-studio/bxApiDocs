<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Mailbox;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\Enum\MailboxConnectionRequestStatus;
use Bitrix\Mail\Helper\Message;
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
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Pull\Event;

final class MailboxConnectionRequestService
{
	public const ERROR_LIMIT_EXCEEDED = 'MAIL_CONNECTION_REQUEST_LIMIT_EXCEEDED';
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
		if (!Feature::isMailboxConnectionRequestAvailable())
		{
			return false;
		}

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

		if (!MailboxConnector::checkConnectionLimits($this->userId))
		{
			$result->addError(new Error('Mailbox limit exceeded', self::ERROR_LIMIT_EXCEEDED));

			return $result;
		}

		$addResult = $this->addPendingRequest($this->userId, $comment);
		if (!$addResult->isSuccess())
		{
			$result->addErrors($addResult->getErrors());

			return $result;
		}

		$this->invalidateAndNotifyPendingCountChange();

		$adminIds = $this->getResponsible();

		$chat = new ConnectionRequestChat();
		$chatResult = $chat->getOrCreateChat($this->userId, $adminIds);
		if ($chatResult->isSuccess())
		{
			$chatData = $chatResult->getData();
			$chatId = $chatData['chatId'];

			MailboxConnectionRequestTable::update($addResult->getId(), ['CHAT_ID' => $chatId]);

			$chat->sendRequestMessage($chatId, $this->userId, $comment);
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
	 *     COMMENT: ?string,
	 *     CREATED_AT: \Bitrix\Main\Type\DateTime
	 * }>
	 */
	public function getPendingRequestsPaginated(int $limit, int $offset): array
	{
		return MailboxConnectionRequestTable::query()
			->setSelect(['ID', 'REQUESTER_ID', 'COMMENT', 'CREATED_AT'])
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

		// Find all pending requests with chats and ensure admins are present
		$pendingRequests = MailboxConnectionRequestTable::getList([
			'filter' => ['=STATUS' => MailboxConnectionRequestStatus::Pending->value],
			'select' => ['ID', 'CHAT_ID', 'REQUESTER_ID'],
		])->fetchAll();

		$chat = new ConnectionRequestChat();
		foreach ($pendingRequests as $pendingRequest)
		{
			$chatId = $service->getOrCreateChatIdForRequest($pendingRequest, array_values($adminIds));
			if ($chatId > 0)
			{
				$chat->ensureAdminsInChat($chatId, array_values($adminIds));
			}
		}
	}

	public function getResponsible(): array
	{
		$responsibleId = $this->getResponsibleAdminId();
		if ($responsibleId > 0)
		{
			return [$responsibleId];
		}

		$adminIds = [];
		if (Loader::includeModule('bitrix24'))
		{
			$adminIdsRaw = \CBitrix24::getAllAdminId();
			foreach($adminIdsRaw as $adminIdRaw)
			{
				$adminIds[] = (int)$adminIdRaw;
			}

			return $adminIds;
		}

		$res = \CGroup::getGroupUserEx(1);
		while ($row = $res->fetch())
		{
			$adminIds[] = (int)$row['USER_ID'];
		}

		return $adminIds;
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

		return $pendingCount;
	}

	private static function getPendingCountCacheDir(): string
	{
		$hashPrefix = substr(md5(self::PENDING_COUNT_CACHE_KEY), 2, 2);

		return self::PENDING_COUNT_CACHE_DIR_BASE . '/' . $hashPrefix . '/' . self::PENDING_COUNT_CACHE_KEY . '/';
	}

	private function getOrCreateChatIdForRequest(array $request, ?array $adminIds = null): int
	{
		$chatId = (int)($request['CHAT_ID'] ?? 0);
		if ($chatId > 0)
		{
			return $chatId;
		}

		$requestId = (int)($request['ID'] ?? 0);
		$requesterId = (int)($request['REQUESTER_ID'] ?? 0);
		if ($requestId <= 0 || $requesterId <= 0)
		{
			return 0;
		}

		$chat = new ConnectionRequestChat();
		$chatResult = $chat->getOrCreateChat($requesterId, array_values($adminIds ?? $this->getResponsible()));
		if (!$chatResult->isSuccess())
		{
			return 0;
		}

		$chatId = (int)($chatResult->getData()['chatId'] ?? 0);
		if ($chatId <= 0)
		{
			return 0;
		}

		MailboxConnectionRequestTable::update($requestId, ['CHAT_ID' => $chatId]);

		return $chatId;
	}

	private function addPendingRequest(int $requesterId, string $comment): AddResult
	{
		return MailboxConnectionRequestTable::add([
			'REQUESTER_ID' => $requesterId,
			'COMMENT' => $comment !== '' ? $comment : null,
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
