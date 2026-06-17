<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Mailbox;

use Bitrix\Mail\Helper\Enum\MailboxStatus;
use Bitrix\Mail\Helper\Dto\MailboxConnect\CrmOptions;
use Bitrix\Mail\Helper\Dto\MailboxConnect\MailboxConnectDTO;
use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Mail\Imap;
use Bitrix\Mail\Integration\Im\Notification;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\Smtp;
use Bitrix\Mail\Helper\MailboxSearchIndexHelper;
use Bitrix\Mail\Helper\Message;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\UserTable;

final class PasswordlessConnectHelper
{
	private const ERROR_NOT_FOUND = 'PASSWORDLESS_NOT_FOUND';
	private const ERROR_ACCESS_DENIED = 'PASSWORDLESS_ACCESS_DENIED';

	private const SENT_COUNT_CACHE_TTL = 3600;
	private const SENT_COUNT_CACHE_KEY = 'passwordless_sent_total_count';
	private const SENT_COUNT_CACHE_TAG = 'mail_passwordless_sent_count';
	private const SENT_COUNT_CACHE_DIR_BASE = '/mail/passwordless_sent_count';

	public static function getUserPendingCount(int $userId, string $siteId): int
	{
		return (int)MailboxTable::getCount([
			'=USER_ID' => $userId,
			'=LID' => $siteId,
			'=ACTIVE' => MailboxStatus::Pending->value,
			'=SERVER_TYPE' => 'imap',
		]);
	}

	public function createRequest(int $adminId, int $userId, MailboxConnectDTO $dto): Result
	{
		$result = new Result();

		$dto->userIdToConnect = $userId;
		$dto->password = '';
		$dto->storageOauthUid ??= '';

		if (!MailboxConnector::checkConnectionLimits($userId))
		{
			$result->addError(new Error(
				Loc::getMessage('MAIL_PASSWORDLESS_ERROR_LIMIT') ?? '',
				MailboxConnector::LIMIT_ERROR_KEY,
			));

			return $result;
		}

		$email = trim((string)$dto->email);
		if ($email !== '' && !empty(Mailbox::findActiveMailbox($userId, $email, SITE_ID)))
		{
			$result->addError(new Error(
				Loc::getMessage('MAIL_PASSWORDLESS_ERROR_DUPLICATE') ?? '',
				MailboxConnector::EXISTS_ERROR_KEY,
			));

			return $result;
		}

		$connector = new MailboxConnector();
		$connector->setMailboxConnectDTO($dto);

		$connectionData = $connector->getMailboxConnectionData($dto);
		if ($connectionData === null)
		{
			foreach ($connector->getErrors() as $error)
			{
				$result->addError($error);
			}

			return $result;
		}

		$dto->service = $connectionData['service'];
		$dto->site = $connectionData['site'];
		$dto->login = $connectionData['login'];

		$mailboxData = $connector->buildMailboxData($dto);

		$imapServer = $mailboxData['SERVER'] ?? '';
		if ($imapServer !== '' && !MailboxConnector::isValidMailHost($imapServer))
		{
			$result->addError(new Error(
				Loc::getMessage('MAIL_PASSWORDLESS_ERROR_INVALID_HOST') ?? '',
				MailboxConnector::SERVER_RESPONSE_ERROR_KEY,
				['type' => MailboxConnector::RESPONSE_ERROR_CODE_IMAP_CONNECTION],
			));

			return $result;
		}

		$smtpServer = trim($dto->serverSmtp ?? '');
		if ($smtpServer !== '' && !MailboxConnector::isValidMailHost($smtpServer))
		{
			$result->addError(new Error(
				Loc::getMessage('MAIL_PASSWORDLESS_ERROR_INVALID_HOST') ?? '',
				MailboxConnector::SERVER_RESPONSE_ERROR_KEY,
				['type' => MailboxConnector::RESPONSE_ERROR_CODE_SMTP_CONNECTION],
			));

			return $result;
		}

		$pendingOptions = $this->buildPendingOptions($adminId, $dto);
		$mailboxData['ACTIVE'] = MailboxStatus::Pending->value;
		$mailboxData['PASSWORD'] = '';
		$mailboxData['OPTIONS'] = array_merge($mailboxData['OPTIONS'], $pendingOptions);

		$smtpServer = trim($dto->serverSmtp ?? '');
		if ($smtpServer !== '')
		{
			$mailboxData['OPTIONS']['passwordless_smtp'] = [
				'server' => $smtpServer,
				'port' => ($dto->portSmtp ?? ''),
				'login' => $dto->loginSmtp ?? '',
				'protocol' => $dto->sslSmtp ? 'ssl' : '',
				'limit' => ($dto->useLimitSmtp ? ($dto->limitSmtp ?? 0) : 0),
				'senderName' => $dto->senderName ?? '',
				'useSenderName' => $dto->useSenderName ?? false,
			];
		}

		unset($mailboxData['SYNC_LOCK'], $mailboxData['SERVICE_NAME']);

		$addResult = MailboxTable::add($mailboxData);

		if (!$addResult->isSuccess())
		{
			$result->addErrors($addResult->getErrors());

			return $result;
		}

		$mailboxId = $addResult->getId();

		MailboxSearchIndexHelper::saveSearchIndexForMailbox($mailboxId);

		Notification::sendPasswordlessRequestNotification(
			$userId,
			$adminId,
			$mailboxId,
		);

		Message::setUserUnseenCounter($userId, SITE_ID);
		$this->invalidateAndNotifySentCountChange();

		$result->setData([
			'mailboxId' => $mailboxId,
			'email' => $mailboxData['EMAIL'],
		]);

		return $result;
	}

	public function completeRequest(int $mailboxId, string $password): Result
	{
		$result = new Result();

		$mailbox = $this->getPendingMailbox($mailboxId);
		if ($mailbox === null)
		{
			$result->addError(new Error(
				Loc::getMessage('MAIL_PASSWORDLESS_ERROR_NOT_FOUND') ?? '',
				self::ERROR_NOT_FOUND,
			));

			return $result;
		}

		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId !== (int)$mailbox['USER_ID'])
		{
			$result->addError(new Error(
				Loc::getMessage('MAIL_PASSWORDLESS_ERROR_ACCESS_DENIED') ?? '',
				self::ERROR_ACCESS_DENIED,
			));

			return $result;
		}

		// Temporarily mark as canceled so the connector doesn't see it as a duplicate
		$setCanceledResult = MailboxTable::update($mailboxId, ['ACTIVE' => MailboxStatus::Canceled->value]);
		if (!$setCanceledResult->isSuccess())
		{
			$result->addErrors($setCanceledResult->getErrors());

			return $result;
		}

		$connectionSucceeded = false;
		try
		{
			$dto = $this->buildDtoFromPendingMailbox($mailbox, $password);

			$connector = new MailboxConnector();
			$connectResult = $connector->connectMailboxWithCustomCrm($dto);
			$errors = $connector->getErrors();

			if (!empty($errors))
			{
				foreach ($errors as $error)
				{
					$result->addError($error);
				}

				return $result;
			}

			// Connection succeeded — remove the old pending record
			MailboxTable::delete($mailboxId);
			Notification::deletePasswordlessRequestNotification($mailboxId);

			Message::setUserUnseenCounter($currentUserId, SITE_ID);
			$this->invalidateAndNotifySentCountChange();

			$connectionSucceeded = true;

			$result->setData([
				'mailboxId' => (int)($connectResult['id'] ?? 0),
				'email' => $connectResult['email'] ?? $mailbox['EMAIL'],
			]);
		}
		finally
		{
			if (!$connectionSucceeded)
			{
				MailboxTable::update($mailboxId, ['ACTIVE' => MailboxStatus::Pending->value]);
			}
		}

		return $result;
	}

	public function cancelRequest(int $mailboxId): Result
	{
		$result = new Result();

		$mailbox = $this->getPendingMailbox($mailboxId);
		if ($mailbox === null)
		{
			$result->addError(new Error(
				Loc::getMessage('MAIL_PASSWORDLESS_ERROR_NOT_FOUND') ?? '',
				self::ERROR_NOT_FOUND,
			));

			return $result;
		}

		MailboxTable::update($mailboxId, ['ACTIVE' => MailboxStatus::Canceled->value]);
		Notification::deletePasswordlessRequestNotification($mailboxId);

		Message::setUserUnseenCounter((int)$mailbox['USER_ID'], SITE_ID);

		return $result;
	}

	/**
	 * @return array{mailboxId: int, email: string}|null
	 */
	public function getPendingRequestForUser(int $userId): ?array
	{
		$row = MailboxTable::query()
			->setSelect(['ID', 'EMAIL'])
			->where('USER_ID', $userId)
			->where('ACTIVE', MailboxStatus::Pending->value)
			->where('SERVER_TYPE', 'imap')
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->fetch()
		;

		if (!$row)
		{
			return null;
		}

		return [
			'mailboxId' => (int)$row['ID'],
			'email' => $row['EMAIL'],
		];
	}

	public function validateConnectionSettings(MailboxConnectDTO $dto): Result
	{
		$result = new Result();

		$imapHost = trim($dto->server ?? '');
		$imapPort = (int)($dto->port ?? 993);
		$imapTls = $dto->ssl ?? true;

		if ($imapHost === '')
		{
			$result->addError(new Error(
				Loc::getMessage('MAIL_PASSWORDLESS_ERROR_IMAP_EMPTY_HOST') ?? '',
				MailboxConnector::SERVER_RESPONSE_ERROR_KEY,
				['type' => MailboxConnector::RESPONSE_ERROR_CODE_IMAP_CONNECTION],
			));

			return $result;
		}

		if (!MailboxConnector::isValidMailHost($imapHost))
		{
			$result->addError(new Error(
				Loc::getMessage('MAIL_PASSWORDLESS_ERROR_INVALID_HOST') ?? '',
				MailboxConnector::SERVER_RESPONSE_ERROR_KEY,
				['type' => MailboxConnector::RESPONSE_ERROR_CODE_IMAP_CONNECTION],
			));

			return $result;
		}

		$imap = new Imap($imapHost, $imapPort, $imapTls, false, '', '');
		$imapError = null;
		if (!$imap->connect($imapError))
		{
			$result->addError(new Error(
				$imapError ?: Loc::getMessage('MAIL_PASSWORDLESS_ERROR_IMAP_UNAVAILABLE') ?? '',
				MailboxConnector::SERVER_RESPONSE_ERROR_KEY,
				['type' => MailboxConnector::RESPONSE_ERROR_CODE_IMAP_CONNECTION],
			));

			return $result;
		}

		$smtpHost = trim($dto->serverSmtp ?? '');
		if ($smtpHost !== '')
		{
			$smtpPort = (int)($dto->portSmtp ?? 465);
			$smtpTls = $dto->sslSmtp ?? true;

			if (!MailboxConnector::isValidMailHost($smtpHost))
			{
				$result->addError(new Error(
					Loc::getMessage('MAIL_PASSWORDLESS_ERROR_INVALID_HOST') ?? '',
					MailboxConnector::SERVER_RESPONSE_ERROR_KEY,
					['type' => MailboxConnector::RESPONSE_ERROR_CODE_SMTP_CONNECTION],
				));

				return $result;
			}

			$smtp = new Smtp($smtpHost, $smtpPort, $smtpTls, false, '', '');
			$smtpError = [];
			if (!$smtp->connect($smtpError))
			{
				$result->addError(new Error(
					$smtpError ?: Loc::getMessage('MAIL_PASSWORDLESS_ERROR_SMTP_UNAVAILABLE') ?? '',
					MailboxConnector::SERVER_RESPONSE_ERROR_KEY,
					['type' => MailboxConnector::RESPONSE_ERROR_CODE_SMTP_CONNECTION],
				));

				return $result;
			}
		}

		return $result;
	}

	public function getSentRequests(int $limit, int $offset, array $filter = []): array
	{
		$query = $this->buildSentRequestsQuery($filter);
		$query->setLimit($limit);
		$query->setOffset($offset);
		$query->setOrder(['ID' => 'DESC']);

		return $query->fetchAll();
	}

	public function getSentRequestsTotalCount(array $filter = []): int
	{
		$query = $this->buildSentRequestsQuery($filter);
		$query->setSelect([new ExpressionField('CNT', 'COUNT(%s)', 'ID')]);

		$result = $query->exec()->fetch();

		return (int)($result['CNT'] ?? 0);
	}

	public function resendRequest(int $mailboxId, int $adminId): Result
	{
		$result = new Result();

		$mailbox = $this->getPasswordlessMailbox($mailboxId);
		if ($mailbox === null)
		{
			$result->addError(new Error(
				Loc::getMessage('MAIL_PASSWORDLESS_ERROR_NOT_FOUND') ?? '',
				self::ERROR_NOT_FOUND,
			));

			return $result;
		}

		$userId = (int)$mailbox['USER_ID'];

		$options = $mailbox['OPTIONS'] ?? [];
		$options['passwordless_admin_id'] = $adminId;
		$options['passwordless_created_at'] = time();

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$updateResult = MailboxTable::update($mailboxId, [
				'ACTIVE' => MailboxStatus::Pending->value,
				'OPTIONS' => $options,
			]);

			if (!$updateResult->isSuccess())
			{
				$connection->rollbackTransaction();
				$result->addErrors($updateResult->getErrors());

				return $result;
			}

			Notification::deletePasswordlessRequestNotification($mailboxId);
			Notification::sendPasswordlessRequestNotification($userId, $adminId, $mailboxId);

			Message::setUserUnseenCounter($userId, SITE_ID);

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}

		$this->invalidateAndNotifySentCountChange();

		$result->setData([
			'mailboxId' => $mailboxId,
			'email' => $mailbox['EMAIL'],
		]);

		return $result;
	}

	public function deleteRecord(int $mailboxId): Result
	{
		$result = new Result();

		$mailbox = $this->getInactiveMailbox($mailboxId);
		if ($mailbox === null)
		{
			$result->addError(new Error(
				Loc::getMessage('MAIL_PASSWORDLESS_ERROR_NOT_FOUND') ?? '',
				self::ERROR_NOT_FOUND,
			));

			return $result;
		}

		MailboxTable::delete($mailboxId);
		Notification::deletePasswordlessRequestNotification($mailboxId);
		$this->invalidateAndNotifySentCountChange();

		return $result;
	}

	private function buildDtoFromPendingMailbox(array $mailbox, string $password): MailboxConnectDTO
	{
		$options = $mailbox['OPTIONS'] ?? [];

		$crmEnabled = in_array('crm_connect', $options['flags'] ?? [], true);
		$crmPublic = in_array('crm_public_bind', $options['flags'] ?? [], true);

		$crmConfig = [];
		if ($crmEnabled)
		{
			$crmConfig['crm_public'] = $crmPublic;

			$crmKeys = [
				'crm_new_entity_in',
				'crm_new_entity_out',
				'crm_lead_source',
				'crm_lead_resp',
				'crm_new_lead_for',
				'crm_sync_days',
			];

			foreach ($crmKeys as $key)
			{
				if (isset($options[$key]))
				{
					$crmConfig[$key] = $options[$key];
				}
			}
		}

		$crmOptions = CrmOptions::fromArray([
			'enabled' => $crmEnabled,
			'config' => $crmConfig,
		]);

		$smtpServer = $options['smtp_server'] ?? '';
		$useSmtp = $smtpServer !== '';

		$passwordlessSmtp = is_array($options['passwordless_smtp'] ?? null) ? $options['passwordless_smtp'] : [];

		$loginSmtp = null;
		$senderName = null;
		$useSenderName = null;
		$useLimitSmtp = null;
		$limitSmtp = null;

		if ($useSmtp)
		{
			$configuredLogin = trim((string)($passwordlessSmtp['login'] ?? ''));
			$loginSmtp = $configuredLogin !== '' ? $configuredLogin : $mailbox['LOGIN'];

			if (isset($passwordlessSmtp['senderName']))
			{
				$senderName = (string)$passwordlessSmtp['senderName'];
			}

			if (isset($passwordlessSmtp['useSenderName']))
			{
				$useSenderName = (bool)$passwordlessSmtp['useSenderName'];
			}

			$limitValue = (int)($passwordlessSmtp['limit'] ?? 0);
			if ($limitValue > 0)
			{
				$useLimitSmtp = true;
				$limitSmtp = $limitValue;
			}
		}

		return new MailboxConnectDTO(
			email: $mailbox['EMAIL'],
			login: $mailbox['LOGIN'],
			password: $password,
			serviceId: (int)$mailbox['SERVICE_ID'] ?: null,
			server: $mailbox['SERVER'],
			port: (string)$mailbox['PORT'],
			ssl: $mailbox['USE_TLS'] === 'Y',
			storageOauthUid: '',
			syncAfterConnection: true,
			useSmtp: $useSmtp,
			serverSmtp: $useSmtp ? $smtpServer : null,
			portSmtp: isset($options['smtp_port']) ? (string)$options['smtp_port'] : null,
			sslSmtp: ($options['smtp_use_tls'] ?? 'N') === 'Y',
			loginSmtp: $loginSmtp,
			passwordSMTP: $useSmtp ? $password : null,
			useLimitSmtp: $useLimitSmtp,
			limitSmtp: $limitSmtp,
			mailboxName: $mailbox['NAME'],
			senderName: $senderName,
			iCalAccess: ($options['ical_access'] ?? 'N') === 'Y',
			crmOptions: $crmOptions,
			userIdToConnect: (int)$mailbox['USER_ID'],
			messageMaxAge: (int)($options['message_max_age'] ?? MailboxConnector::MESSAGE_MAX_AGE),
			serviceConfig: MailboxConnector::DEFAULT_SERVICE_CONFIG,
			useSenderName: $useSenderName,
		);
	}

	private function buildPendingOptions(int $adminId, MailboxConnectDTO $dto): array
	{
		$options = [
			'passwordless_admin_id' => $adminId,
			'passwordless_created_at' => time(),
			'message_max_age' => $dto->messageMaxAge ?? MailboxConnector::MESSAGE_MAX_AGE,
			'flags' => [],
			'version' => 6,
		];

		$smtpServer = trim($dto->serverSmtp ?? '');
		if ($smtpServer !== '')
		{
			$options['smtp_server'] = $smtpServer;
			$options['smtp_port'] = (int)($dto->portSmtp ?? 465);
			$options['smtp_use_tls'] = $dto->sslSmtp ? 'Y' : 'N';
		}

		$crmOptions = $dto->crmOptions;
		if ($crmOptions?->enabled)
		{
			$options['flags'][] = 'crm_connect';

			if ($crmOptions->public)
			{
				$options['flags'][] = 'crm_public_bind';
			}

			$crmSyncDays = $crmOptions->syncDays ?? MailboxConnector::CRM_MAX_AGE;
			$options['crm_sync_from'] = strtotime(sprintf('-%u days', $crmSyncDays));

			if ($crmOptions->newEntityIn !== null)
			{
				$options['crm_new_entity_in'] = $crmOptions->newEntityIn->value;
			}

			if ($crmOptions->newEntityOut !== null)
			{
				$options['crm_new_entity_out'] = $crmOptions->newEntityOut->value;
			}

			if ($crmOptions->leadSource !== null)
			{
				$options['crm_lead_source'] = $crmOptions->leadSource;
			}

			if (!empty($crmOptions->leadResp))
			{
				$options['crm_lead_resp'] = $crmOptions->leadResp;
			}

			if (!empty($crmOptions->newLeadFor))
			{
				$options['crm_new_lead_for'] = $crmOptions->newLeadFor;
			}

			if ($crmOptions->vcf)
			{
				$options['crm_vcf'] = 'Y';
			}
		}

		if (Loader::includeModule('calendar') && $dto->iCalAccess !== null)
		{
			$options['ical_access'] = $dto->iCalAccess ? 'Y' : 'N';
		}

		return $options;
	}

	private function getPendingMailbox(int $mailboxId): ?array
	{
		$row = MailboxTable::query()
			->setSelect(['*'])
			->where('ID', $mailboxId)
			->where('ACTIVE', MailboxStatus::Pending->value)
			->where('SERVER_TYPE', 'imap')
			->setLimit(1)
			->fetch()
		;

		return $row ?: null;
	}

	private function getPasswordlessMailbox(int $mailboxId): ?array
	{
		$row = MailboxTable::query()
			->setSelect(['*'])
			->where('ID', $mailboxId)
			->whereIn('ACTIVE', [
				MailboxStatus::Pending->value,
				MailboxStatus::Canceled->value,
			])
			->where('SERVER_TYPE', 'imap')
			->setLimit(1)
			->fetch()
		;

		return $row ?: null;
	}

	private function getInactiveMailbox(int $mailboxId): ?array
	{
		$row = MailboxTable::query()
			->setSelect(['ID', 'ACTIVE'])
			->where('ID', $mailboxId)
			->where('ACTIVE', MailboxStatus::Canceled->value)
			->where('SERVER_TYPE', 'imap')
			->setLimit(1)
			->fetch()
		;

		return $row ?: null;
	}

	public static function getSentTotalCount(): int
	{
		$cache = Cache::createInstance();
		$dir = self::getSentCountCacheDir();

		if ($cache->initCache(self::SENT_COUNT_CACHE_TTL, self::SENT_COUNT_CACHE_KEY, $dir))
		{
			return max(0, (int)$cache->getVars());
		}

		$cache->startDataCache();

		$taggedCache = Application::getInstance()->getTaggedCache();
		$taggedCache->startTagCache($dir);
		$taggedCache->registerTag(self::SENT_COUNT_CACHE_TAG);

		$count = (new self())->getSentRequestsTotalCount();

		$taggedCache->endTagCache();
		$cache->endDataCache($count);

		return $count;
	}

	private function invalidateAndNotifySentCountChange(): void
	{
		Application::getInstance()->getTaggedCache()->clearByTag(self::SENT_COUNT_CACHE_TAG);
		$this->sendSentTotalCountPullEvent(self::getSentTotalCount());
	}

	private static function getSentCountCacheDir(): string
	{
		$hashPrefix = substr(md5(self::SENT_COUNT_CACHE_KEY), 2, 2);

		return self::SENT_COUNT_CACHE_DIR_BASE . '/' . $hashPrefix . '/' . self::SENT_COUNT_CACHE_KEY . '/';
	}

	private function sendSentTotalCountPullEvent(int $count): void
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		$adminIds = (new MailboxConnectionRequestService())->getResponsible();
		if (empty($adminIds))
		{
			return;
		}

		\Bitrix\Pull\Event::add($adminIds, [
			'module_id' => 'mail',
			'command' => 'passwordless_sent_total_count_changed',
			'params' => [
				'count' => $count,
			],
		]);
	}

	private function buildSentRequestsQuery(array $filter = []): Query
	{
		$query = MailboxTable::query()
			->registerRuntimeField(
				'OWNER',
				[
					'data_type' => UserTable::class,
					'reference' => [
						'=this.USER_ID' => 'ref.ID',
					],
					'join_type' => 'LEFT',
				],
			)
			->setSelect([
				'ID',
				'EMAIL',
				'USER_ID',
				'ACTIVE',
				'OPTIONS',
				'OWNER_NAME' => 'OWNER.NAME',
				'OWNER_LAST_NAME' => 'OWNER.LAST_NAME',
				'OWNER_LOGIN' => 'OWNER.LOGIN',
				'OWNER_PERSONAL_PHOTO' => 'OWNER.PERSONAL_PHOTO',
				'OWNER_WORK_POSITION' => 'OWNER.WORK_POSITION',
			])
			->whereIn('ACTIVE', [
				MailboxStatus::Pending->value,
				MailboxStatus::Canceled->value,
			])
			->where('SERVER_TYPE', 'imap')
		;

		$this->applySentRequestsFilter($query, $filter);

		return $query;
	}

	private function applySentRequestsFilter(Query $query, array $filter): void
	{
		if (empty($filter))
		{
			return;
		}

		if (!empty($filter['USER_ID']))
		{
			if (is_array($filter['USER_ID']))
			{
				$query->whereIn('USER_ID', array_map('intval', $filter['USER_ID']));
			}
			else
			{
				$query->where('USER_ID', (int)$filter['USER_ID']);
			}
		}

		if (!empty($filter['EMAIL']))
		{
			$query->whereLike('EMAIL', '%' . $filter['EMAIL'] . '%');
		}

		if (!empty($filter['ACTIVE']))
		{
			if (is_array($filter['ACTIVE']))
			{
				$query->whereIn('ACTIVE', $filter['ACTIVE']);
			}
			else
			{
				$query->where('ACTIVE', $filter['ACTIVE']);
			}
		}

		if (!empty($filter['FIND']))
		{
			$searchValue = MailboxSearchIndexHelper::prepareStringToSearch($filter['FIND']);
			if ($searchValue !== null && \Bitrix\Main\Search\Content::canUseFulltextSearch($filter['FIND']))
			{
				$query->registerRuntimeField(
					'MAILBOX_SEARCH_INDEX',
					[
						'data_type' => \Bitrix\Mail\Internals\Search\MailboxListSearchIndexTable::class,
						'reference' => [
							'=this.ID' => 'ref.MAILBOX_ID',
						],
						'join_type' => 'INNER',
					],
				);
				$query->whereMatch('MAILBOX_SEARCH_INDEX.SEARCH_INDEX', $searchValue);
			}
		}
	}
}
