<?php

namespace Bitrix\Mail\Helper\Mailbox;

use Bitrix\Mail;
use Bitrix\Mail\Helper\Enum\MailboxStatus;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\Dto\MailboxConnect\MailboxConnectDTO;
use Bitrix\Mail\Helper\LicenseManager;
use Bitrix\Mail\Helper\Enum\CrmEntityType;
use Bitrix\Mail\Helper\Enum\CrmFlag;
use Bitrix\Mail\Helper\Enum\CrmImapFilterContext;
use Bitrix\Mail\Helper\Enum\CrmOption;
use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Helper\Dto\MailboxConnect\CrmOptions;
use Bitrix\Mail\Helper\Mailbox\MailboxSettingsConfig;
use Bitrix\Mail\Helper\MailboxSearchIndexHelper;
use Bitrix\Mail\Integration\Im\Notification;
use Bitrix\Mail\MailServicesTable;
use Bitrix\Main;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Mail\Address;
use Bitrix\Main\Mail\Sender\UserSenderDataProvider;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\SystemException;

final class MailboxConnector
{
	public const NO_RIGHTS_TO_CONNECT_ERROR_KEY = 'NO_RIGHTS_TO_CONNECT';
	public const STANDARD_ERROR_KEY = 'STANDARD_ERROR';
	public const LIMIT_ERROR_KEY = 'LIMIT_ERROR';
	public const OAUTH_ERROR_KEY = 'OAUTH_ERROR';
	public const EXISTS_ERROR_KEY = 'EXISTS_ERROR';
	public const NO_MAIL_SERVICES_ERROR_KEY = 'NO_MAIL_SERVICES';
	public const SMTP_PASS_BAD_SYMBOLS_ERROR_KEY = 'SMTP_PASS_BAD_SYMBOLS';
	// IMAP/SMTP authentication failed (wrong credentials or expired OAuth token)
	public const AUTH_ERROR_KEY = 'AUTH_ERROR';
	// IMAP/SMTP server returned a non-auth error (connection, TLS, protocol, etc.)
	public const SERVER_RESPONSE_ERROR_KEY = 'SERVER_RESPONSE_ERROR';
	// SMTP sender verification failed or sender was not confirmed
	public const SMTP_SENDER_ERROR_KEY = 'SMTP_SENDER_ERROR';
	// Plan limit for shared mailboxes is reached, cannot add new shared users
	public const SHARED_LIMIT_EXCEEDED_ERROR_KEY = 'SHARED_LIMIT_EXCEEDED';
	// CRM settings came with the request, but the current user cannot edit CRM integration
	public const CRM_ACCESS_DENIED_ERROR_KEY = 'CRM_ACCESS_DENIED';

	public const DEFAULT_IMAP_PORT = '993';
	public const DEFAULT_SMTP_PORT = '587';
	public const DEFAULT_SERVICE_CONFIG = ['serviceType' => 'imap', 'name' => 'other'];
	public const CRM_MAX_AGE = 7;
	public const MESSAGE_MAX_AGE = 7;

	private const DEFAULT_PERIOD_CHECK = 60 * 24;

	public const RESPONSE_ERROR_CODE_IMAP_CONNECTION = 'imap_connection';
	public const RESPONSE_ERROR_CODE_WRONG_AUTH = 'auth';
	public const RESPONSE_ERROR_CODE_SMTP_CONNECTION = 'smtp_connection';

	private bool $isSuccess = false;

	private array $errorCollection = [];

	private bool $isSMTPAvailable = false;

	private ?MailboxConnectDTO $mailboxConnectDTO = null;

	public function setMailboxConnectDTO(MailboxConnectDTO $mailboxConnectDTO): void
	{
		$this->mailboxConnectDTO = $mailboxConnectDTO;
	}

	public function getSuccess(): bool
	{
		return $this->isSuccess;
	}

	public function setSuccess(): void
	{
		$this->isSuccess = true;
	}

	public function getErrors(): array
	{
		return $this->errorCollection;
	}

	public function clearErrors(): void
	{
		$this->errorCollection = [];
	}

	private function addError(string $error, string $code, array|string|null $customData = null): void
	{
		if (!$customData)
		{
			$customData = $this->prepareErrorCustomData();
		}

		$this->errorCollection[] = new Main\Error($error, $code, $customData);
	}

	private function addResultErrors(Main\Result $result): void
	{
		foreach ($result->getErrors() as $error)
		{
			$this->errorCollection[] = $error;
		}
	}

	private function prepareErrorCustomData(?string $errorType = null, ?string $details = null): array
	{
		if (is_null($errorType))
		{
			return [];
		}

		$data = [];
		switch ($errorType)
		{
			case self::RESPONSE_ERROR_CODE_WRONG_AUTH:
				$data = ['type' => self::RESPONSE_ERROR_CODE_WRONG_AUTH];
				if ($this->mailboxConnectDTO !== null)
				{
					$data['userIdToConnect'] = $this->mailboxConnectDTO->userIdToConnect;
				}

				break;
			case self::RESPONSE_ERROR_CODE_IMAP_CONNECTION:
				$data = ['type' => self::RESPONSE_ERROR_CODE_IMAP_CONNECTION];

				break;
			case self::RESPONSE_ERROR_CODE_SMTP_CONNECTION:
				$data = ['type' => self::RESPONSE_ERROR_CODE_SMTP_CONNECTION];

				break;
		}

		if ($details)
		{
			$data['details'] = $details;
		}

		return $data;
	}

	private function addSmtpConnectionError(string $message): void
	{
		$this->addError(
			$message,
			self::SERVER_RESPONSE_ERROR_KEY,
			$this->prepareErrorCustomData(self::RESPONSE_ERROR_CODE_SMTP_CONNECTION),
		);
	}

	private function addErrors(
		Main\ErrorCollection $errorCollection,
		bool $isOAuth = false,
		bool $isSender = false,
	): void
	{
		$messages = [];
		$details  = [];

		foreach ($errorCollection as $item)
		{
			if ($item->getCode() < 0)
			{
				$details[] = $item;
			}
			else
			{
				$messages[] = $item;
			}
		}

		$connectionType = $this->detectConnectionTypeFromDetails($details);

		$errorType = null;
		$moreDetailsSection = false;
		$code = self::SERVER_RESPONSE_ERROR_KEY;

		if (count($messages) === 1)
		{
			$errorCode = reset($messages)->getCode();
			switch ($errorCode)
			{
				case Mail\Imap::ERR_AUTH:
				case Mail\Smtp::ERR_AUTH:
					$errorType = self::RESPONSE_ERROR_CODE_WRONG_AUTH;
					$code = self::AUTH_ERROR_KEY;

					$authError = Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_IMAP_AUTH_ERR_EXT');
					if ($isSender)
					{
						$authError = Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_ERR_OAUTH_SMTP');
					}
					elseif ($isOAuth)
					{
						$authError = Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_ERR_OAUTH');
					}

					$messages = [
						new Main\Error($authError, $errorCode),
					];

					break;
				case Mail\Imap::ERR_CONNECT:
				case Mail\Smtp::ERR_CONNECT:
					$defaultConnectionType = $isSender
						? self::RESPONSE_ERROR_CODE_SMTP_CONNECTION
						: self::RESPONSE_ERROR_CODE_IMAP_CONNECTION;
					$errorType = $connectionType ?? $defaultConnectionType;
					break;
				default:
					$moreDetailsSection = true;
			}
		}
		else
		{
			$moreDetailsSection = true;
		}

		$reduce = (static fn($error) => $error->getMessage());

		$message = implode(': ', array_map($reduce, $messages));

		$moreDetails = $moreDetailsSection
			? implode(': ', array_map($reduce, $details))
			: null
		;

		$customData = $this->prepareErrorCustomData($errorType, $moreDetails);
		$this->addError($message, $code, $customData);
	}

	private function detectConnectionTypeFromDetails(array $details): ?string
	{
		if (empty($details))
		{
			return null;
		}

		$detailTokens = array_map(static function (Error $error): string {
			return strtoupper(trim($error->getMessage()));
		}, $details);

		if (in_array('SMTP', $detailTokens, true))
		{
			return self::RESPONSE_ERROR_CODE_SMTP_CONNECTION;
		}

		if (in_array('IMAP', $detailTokens, true))
		{
			return self::RESPONSE_ERROR_CODE_IMAP_CONNECTION;
		}

		return null;
	}

	private function addErrorWithMessage(string $code = self::STANDARD_ERROR_KEY): void
	{
		$messages = [
			self::LIMIT_ERROR_KEY => Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_LIMIT_ERROR'),
			self::OAUTH_ERROR_KEY => Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_OAUTH_ERROR'),
			self::EXISTS_ERROR_KEY => Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_EMAIL_EXISTS_ERROR'),
			self::NO_MAIL_SERVICES_ERROR_KEY => Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_THERE_ARE_NO_MAIL_SERVICES'),
			self::SMTP_PASS_BAD_SYMBOLS_ERROR_KEY => Loc::getMessage('MAIL_MAILBOX_CONNECTOR_SMTP_PASS_BAD_SYMBOLS'),
			self::STANDARD_ERROR_KEY => Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_FORM_ERROR'),
			self::NO_RIGHTS_TO_CONNECT_ERROR_KEY => Loc::getMessage('MAIL_MAILBOX_CONNECTOR_ACCESS_DENIED_ERROR'),
		];

		$message = $messages[$code] ?? $messages[self::STANDARD_ERROR_KEY];

		$this->addError($message, $code);
	}

	private static function getUserOwnedMailboxCount(int $userId): int
	{
		$res = Mail\MailboxTable::query()
			->addSelect(new ExpressionField('OWNED', 'COUNT(%s)', 'ID'))
			->where('USER_ID', $userId)
			->where('SERVER_TYPE', 'imap')
			->whereIn('ACTIVE', [MailboxStatus::Active->value, MailboxStatus::Pending->value])
			->fetch()
		;

		return (int)$res['OWNED'];
	}

	public static function checkConnectionLimits(?int $userId = null): bool
	{
		global $USER;

		if (!$userId)
		{
			$userId = (int)$USER->getId();
		}

		$userMailboxesLimit = LicenseManager::getUserMailboxesLimit();
		if ($userMailboxesLimit < 0)
		{
			return true;
		}

		if (self::getUserOwnedMailboxCount($userId) >= $userMailboxesLimit)
		{
			return false;
		}

		return true;
	}

	/**
	 * @deprecated Use \Bitrix\Mail\Helper\Mailbox\MailboxConnector::checkConnectMailbox
	 */
	public static function canConnectNewMailbox(?int $userId = null): bool
	{
		return self::checkConnectionLimits($userId);
	}

	public function checkConnectMailbox(): bool
	{
		if (!MailboxAccess::hasCurrentUserAccessToAddMailbox())
		{
			$this->addErrorWithMessage(self::NO_RIGHTS_TO_CONNECT_ERROR_KEY);

			return false;
		}

		if (!self::checkConnectionLimits())
		{
			$this->addErrorWithMessage(self::LIMIT_ERROR_KEY);

			return false;
		}

		return true;
	}

	/**
	 * @param int[] $userIds List of user IDs to check
	 * @return array<int, array{userId: int, canConnectNew: bool}>
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public static function getUsersCanConnectNewMailbox(array $userIds): array
	{
		$userIds = array_map('intval', array_unique($userIds));
		$userIds = array_filter($userIds, static fn(int $id): bool => $id > 0);

		if (empty($userIds))
		{
			return [];
		}

		$result = array_fill_keys($userIds, true);

		$limit = LicenseManager::getUserMailboxesLimit();

		if ($limit < 0)
		{
			return self::getResultForUsersCanConnectNewMailbox($result);
		}

		$query = Mail\MailboxTable::query()
			->setSelect(['USER_ID'])
			->setGroup(['USER_ID'])
			->whereIn('USER_ID', $userIds)
			->whereIn('ACTIVE', [MailboxStatus::Active->value, MailboxStatus::Pending->value])
			->where('SERVER_TYPE', 'imap')
			->addSelect(
				new ExpressionField('OWNED_CNT', 'COUNT(%s)', ['ID']),
			)
			->having('OWNED_CNT', '>=', $limit)
		;

		$dbResult = $query->exec();

		while ($row = $dbResult->fetch())
		{
			$result[(int)$row['USER_ID']] = false;
		}

		return self::getResultForUsersCanConnectNewMailbox($result);
	}

	/**
	 * @param array<int, bool> $limitMap
	 * @return array<int, array{userId: int, canConnectNew: bool}>
	 */
	private static function getResultForUsersCanConnectNewMailbox(array $limitMap): array
	{
		$mappedData = [];
		foreach ($limitMap as $userId => $canConnect)
		{
			$mappedData[] = [
				'userId' => $userId,
				'canConnectNew' => $canConnect,
			];
		}

		return $mappedData;
	}

	private function syncMailbox(int $mailboxId): void
	{
		Main\Application::getInstance()->addBackgroundJob(function ($mailboxId): void {
			$mailboxHelper = Mailbox::createInstance($mailboxId, false);
			$mailboxHelper->sync();
		},[$mailboxId]);
	}

	private function setIsSmtpAvailable(): void
	{
		$defaultMailConfiguration = Configuration::getValue("smtp");
		$this->isSMTPAvailable = Main\ModuleManager::isModuleInstalled('bitrix24')
			|| $defaultMailConfiguration['enabled'];
	}

	/**
	 * Is OAuth for SMTP enabled for service
	 *
	 * @param string $serviceName Service name
	 */
	public static function isOauthSmtpEnabled(string $serviceName): bool
	{
		return match ($serviceName)
		{
			'gmail' => Main\Config\Option::get('mail', '~disable_gmail_oauth_smtp') !== 'Y',
			'yandex' => Main\Config\Option::get('mail', '~disable_yandex_oauth_smtp') !== 'Y',
			'mail.ru' => Main\Config\Option::get('mail', '~disable_mailru_oauth_smtp') !== 'Y',
			'office365', 'outlook.com', 'exchangeOnline' => Main\Config\Option::get('mail', '~disable_microsoft_oauth_smtp') !== 'Y',
			default => false,
		};
	}

	public static function isValidMailHost(string $host): bool
	{
		if (\Bitrix\Main\ModuleManager::isModuleInstalled('bitrix24'))
		{
			// Private addresses can't be used in the cloud
			$ip = \Bitrix\Main\Web\IpAddress::createByName($host);
			if ($ip->isPrivate())
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Append SMTP sender, with two attempts for outlook
	 *
	 * @param array $senderFields Sender fields data
	 * @param string $userPrincipalName User Principal Name, appears in outlook oauth data only
	 */
	public static function appendSender(array $senderFields, string $userPrincipalName, int $mailboxId = 0): array
	{
		if ($mailboxId)
		{
			$senderFields['PARENT_ID'] = $mailboxId;
			$senderFields['PARENT_MODULE_ID'] = 'mail';
		}

		$result = Main\Mail\Sender::add($senderFields);

		if (empty($result['confirmed']) && $userPrincipalName)
		{
			$address = new Address($userPrincipalName);
			$currentSmtpLogin = $senderFields['OPTIONS']['smtp']['login'] ?? '';
			if ($currentSmtpLogin && $currentSmtpLogin !== $userPrincipalName && $address->validate())
			{
				// outlook workaround, sometimes SMTP auth only works with userPrincipalName
				$senderFields['OPTIONS']['smtp']['login'] = $userPrincipalName;
				$result = Main\Mail\Sender::add($senderFields);
			}
		}

		return $result;
	}

	public function connectMailboxWithDefaultCrm(MailboxConnectDTO $mailboxConnectDTO): array
	{
		return $this->connectMailboxAndSaveIndex($mailboxConnectDTO, defaultCrm: true);
	}

	public function connectMailboxWithCustomCrm(?MailboxConnectDTO $mailboxConnectDTO = null, bool $useClassDto = false): array
	{
		if ($useClassDto && $this->mailboxConnectDTO)
		{
			return $this->connectMailboxAndSaveIndex($this->mailboxConnectDTO);
		}

		if ($mailboxConnectDTO)
		{
			return $this->connectMailboxAndSaveIndex($mailboxConnectDTO);
		}

		return [];
	}

	public function connectMailbox(
		MailboxConnectDTO $mailboxConnectDTO,
		?bool $defaultCrm = false,
	): array
	{
		global $USER;
		$mailboxConnectDTO->userIdToConnect ??= $USER->getId();
		$mailboxConnectDTO->email = trim($mailboxConnectDTO->email ?? '');
		$mailboxConnectDTO->login = trim($mailboxConnectDTO->login ?? '');
		$mailboxConnectDTO->password = trim($mailboxConnectDTO->password ?? '');
		$this->normalizeEmptyPorts($mailboxConnectDTO);
		$mailboxConnectDTO->port ??= self::DEFAULT_IMAP_PORT;
		$mailboxConnectDTO->ssl ??= true;
		$mailboxConnectDTO->portSmtp ??= self::DEFAULT_SMTP_PORT;
		$mailboxConnectDTO->sslSmtp ??= true;
		$mailboxConnectDTO->syncAfterConnection ??= true;
		$mailboxConnectDTO->useSmtp ??= false;
		$mailboxConnectDTO->iCalAccess ??= false;
		$mailboxConnectDTO->serviceConfig ??= self::DEFAULT_SERVICE_CONFIG;
		$mailboxConnectDTO->uploadOutgoing ??= false;
		$mailboxConnectDTO->shareAccess ??= [];
		$mailboxConnectDTO->crmOptions ??= CrmOptions::disabled();
		$mailboxConnectDTO->storageOauthUid ??= '';

		$connectionData = $this->getMailboxConnectionData($mailboxConnectDTO);
		if ($connectionData === null)
		{
			return [];
		}

		$mailboxConnectDTO->service = $connectionData['service'];
		$mailboxConnectDTO->site = $connectionData['site'];
		$mailboxConnectDTO->login = $connectionData['login'];
		$mailboxConnectDTO->password = $connectionData['password'];
		$isOAuth = $connectionData['isOAuth'];

		$mailboxData = $this->buildMailboxData($mailboxConnectDTO);

		if (!$this->validateImapConnection($mailboxData, $isOAuth))
		{
			return [];
		}

		$mailboxConnectDTO->messageMaxAge ??= self::MESSAGE_MAX_AGE;
		$this->applyMessageSyncFrom($mailboxData, $mailboxConnectDTO->messageMaxAge);

		if (!$defaultCrm)
		{
			if ($mailboxConnectDTO->crmOptions->enabled)
			{
				$mailboxData = $this->applyCrmOptions($mailboxData, $mailboxConnectDTO->crmOptions);
			}
		}
		else
		{
			$mailboxData = $this->setDefaultCrmOptions($mailboxData);
		}

		if (Loader::includeModule('calendar'))
		{
			$mailboxData['OPTIONS']['ical_access'] = $mailboxConnectDTO->iCalAccess ? 'Y' : 'N';
		}

		$this->setIsSmtpAvailable();
		$senderFields = $this->prepareSmtpSender($mailboxData, $mailboxConnectDTO);

		if ($this->hasErrors())
		{
			return [];
		}

		if (!$this->assertSharedMailboxLimit(
			$mailboxConnectDTO->shareAccess ?? [],
			(int)$mailboxData['USER_ID'],
			null,
		))
		{
			return [];
		}

		return $this->createMailboxInternal(
			$mailboxData,
			$senderFields,
			$isOAuth,
			$mailboxConnectDTO->syncAfterConnection ?? false,
			$mailboxConnectDTO->shareAccess ?? [],
		);
	}

	public function getMailboxConnectionData(MailboxConnectDTO $mailboxConnectDTO): ?array
	{
		try
		{
			$this->validateConnectionPrerequisites(
				$mailboxConnectDTO->email,
				$mailboxConnectDTO->userIdToConnect,
				$mailboxConnectDTO->serviceConfig,
				$mailboxConnectDTO->serviceId,
			);

			return $this->prepareConnectionData(
				$mailboxConnectDTO->login,
				$mailboxConnectDTO->password,
				$mailboxConnectDTO->storageOauthUid,
				$mailboxConnectDTO->serviceConfig,
				$mailboxConnectDTO->serviceId,
			);
		}
		catch (\Exception $e)
		{
			$this->addErrorWithMessage($e->getMessage());

			return null;
		}
	}

	private function connectMailboxAndSaveIndex(MailboxConnectDTO $mailboxConnectDTO, bool $defaultCrm = false): array
	{
		$connectResult = $this->connectMailbox($mailboxConnectDTO, $defaultCrm);

		if (isset($connectResult['id']) && empty($this->getErrors()))
		{
			MailboxSearchIndexHelper::saveSearchIndexForMailbox($connectResult['id']);
		}

		return $connectResult;
	}

	private function validateConnectionPrerequisites(
		string $email,
		int $userId,
		?array $serviceConfig = null,
		?int $serviceId = null,
	)
	: void
	{
		if (!self::checkConnectionLimits($userId))
		{
			throw new \Exception(self::LIMIT_ERROR_KEY);
		}

		$service = $this->getMailService($serviceId, $serviceConfig);

		if ($service['ACTIVE'] !== MailboxStatus::Active->value)
		{
			throw new \Exception();
		}

		$address = new Address($email);
		if (!$address->validate())
		{
			throw new \Exception(self::OAUTH_ERROR_KEY);
		}

		$currentSite = \CSite::getById(SITE_ID)->fetch();
		$email = $address->getEmail() ?? '';

		$existingMailbox = Mailbox::findActiveMailbox($userId, $email, $currentSite['LID']);

		if (!empty($existingMailbox))
		{
			throw new \Exception(self::EXISTS_ERROR_KEY);
		}
	}

	private function prepareConnectionData(
		string $login,
		string $password,
		string $storageOauthUid,
		?array $serviceConfig = null,
		?int $serviceId = null,
	): array
	{
		$service = $this->getMailService($serviceId, $serviceConfig);

		$oauthHelper = $this->getOauthHelper($service, $storageOauthUid);
		if ($oauthHelper)
		{
			$oauthHelper->getStoredToken($storageOauthUid);
			$password = $oauthHelper->buildMeta();
			$isOAuth = true;
		}
		else
		{
			$isOAuth = false;
		}

		$currentSite = \CSite::getById(SITE_ID)->fetch();

		return [
			'service' => $service,
			'site' => $currentSite,
			'login' => $login,
			'password' => $password,
			'isOAuth' => $isOAuth,
		];
	}

	private function getMailService(?int $serviceId = null, ?array $serviceConfig = null): array
	{
		$service = null;

		if (is_int($serviceId) && $serviceId > 0)
		{
			$service = Mail\MailServicesTable::getById($serviceId)->fetch();
		}
		elseif ($serviceConfig)
		{
			$service =
				Mail\MailServicesTable::query()
					->setSelect(['*'])
					->where('SERVICE_TYPE', $serviceConfig['serviceType'])
					->where('NAME', $serviceConfig['name'])
					->fetch()
			;
		}

		if (empty($service) || $service['SERVICE_TYPE'] !== 'imap')
		{
			throw new \Exception(self::NO_MAIL_SERVICES_ERROR_KEY);
		}

		return $service;
	}

	private function getOauthHelper(array $service, string $storageOauthUid): ?Mail\Helper\OAuth
	{
		if (empty($storageOauthUid))
		{
			return null;
		}

		$oauthHelper = Mail\MailServicesTable::getOAuthHelper($service);

		return $oauthHelper ?: null;
	}

	public function buildMailboxData(MailboxConnectDTO $mailboxConnectDTO): array
	{
		$useTls = $mailboxConnectDTO->ssl ? 'Y' : 'N';

		$mailboxData = [
			'LID'         => $mailboxConnectDTO->site['LID'],
			'ACTIVE'      => MailboxStatus::Active->value,
			'SERVICE_ID'  => $mailboxConnectDTO->service['ID'],
			'SERVER_TYPE' => $mailboxConnectDTO->service['SERVICE_TYPE'],
			'CHARSET'     => $mailboxConnectDTO->site['CHARSET'],
			'USER_ID'     => $mailboxConnectDTO->userIdToConnect,
			'SYNC_LOCK'   => time(),
			'EMAIL'       => $mailboxConnectDTO->email,
			'LOGIN'       => $mailboxConnectDTO->login,
			'PASSWORD'    => $mailboxConnectDTO->password,
			'USERNAME'    => $mailboxConnectDTO->senderName ?: '',
			'NAME'        => $mailboxConnectDTO->mailboxName ?: $mailboxConnectDTO->email,
			'SERVER'      => $mailboxConnectDTO->service['SERVER'] ?: trim($mailboxConnectDTO->server ?? ''),
			'PORT'        => $mailboxConnectDTO->service['PORT'] ?: $mailboxConnectDTO->port,
			'USE_TLS'     => $mailboxConnectDTO->service['ENCRYPTION'] ?: $useTls,
			'LINK' => $mailboxConnectDTO->service['LINK'] ?? trim($mailboxConnectDTO->link),
			'PERIOD_CHECK' => self::DEFAULT_PERIOD_CHECK,
			'OPTIONS'     => [
				'flags'     => [],
				'sync_from' => time(),
				'crm_sync_from' => time(),
				'activateSync' => false,
				'version'   => 6,
			],
			'SERVICE_NAME' => $mailboxConnectDTO->service['NAME'],
		];

		$serviceUploadOutgoing = $mailboxConnectDTO->service['UPLOAD_OUTGOING'] ?? '';
		if ($serviceUploadOutgoing === 'N')
		{
			$mailboxData['OPTIONS']['flags'][] = 'deny_upload';
		}

		if (empty($serviceUploadOutgoing) && !$mailboxConnectDTO->uploadOutgoing)
		{
			$mailboxData['OPTIONS']['flags'][] = 'deny_upload';
		}

		if (!is_null($mailboxConnectDTO->useSenderName))
		{
			$mailboxData['OPTIONS']['useSenderName'] = $mailboxConnectDTO->useSenderName;
		}

		return $mailboxData;
	}

	private function applyCrmOptions(array $mailboxData, CrmOptions $crmOptions): array
	{
		if (!$this->isCrmIntegrationAvailableForCurrentUser())
		{
			return $mailboxData;
		}

		$mailboxData['OPTIONS']['flags'][] = CrmFlag::Connect->value;

		if ($crmOptions->public)
		{
			$interval = (int)Option::get('mail', 'public_mailbox_sync_interval', 0);
			$mailboxData['PERIOD_CHECK'] = $interval > 0 ? $interval : 10;
			$mailboxData['OPTIONS']['flags'][] = CrmFlag::PublicBind->value;
		}

		$syncDays = $crmOptions->syncDays ?? self::CRM_MAX_AGE;
		$this->applyCrmSyncFrom($mailboxData, $syncDays);

		if (empty($crmOptions->newEntityIn))
		{
			$mailboxData['OPTIONS']['flags'][] = CrmFlag::DenyEntityIn->value;
		}

		if (empty($crmOptions->newEntityOut))
		{
			$mailboxData['OPTIONS']['flags'][] = CrmFlag::DenyEntityOut->value;
		}

		if (!$crmOptions->vcf)
		{
			$mailboxData['OPTIONS']['flags'][] = CrmFlag::DenyNewContact->value;
		}

		$mailboxData['OPTIONS'][CrmOption::NewEntityIn->value] = $crmOptions->newEntityIn?->value;
		$mailboxData['OPTIONS'][CrmOption::NewEntityOut->value] = $crmOptions->newEntityOut?->value;
		$mailboxData['OPTIONS'][CrmOption::LeadSource->value] = $crmOptions->leadSource;
		$mailboxData['OPTIONS'][CrmOption::LeadResp->value] = $crmOptions->leadResp;
		$mailboxData['OPTIONS'][CrmOption::Public->value] = $crmOptions->public;
		$mailboxData['OPTIONS'][CrmOption::Vcf->value] = $crmOptions->vcf;
		$mailboxData['OPTIONS'][CrmOption::SyncDays->value] = $crmOptions->syncDays;

		$mailboxData['OPTIONS'][CrmOption::NewLeadFor->value] = [];
		if (!empty($crmOptions->newLeadFor))
		{
			$validEmails = [];
			foreach ($crmOptions->newLeadFor as $item)
			{
				$address = new Address($item, ['checkingPunycode' => true]);
				if ($address->validate())
				{
					$validEmails[] = $address->getEmail();
				}
			}

			$mailboxData['OPTIONS'][CrmOption::NewLeadFor->value] = array_values(array_unique($validEmails));
		}

		return $mailboxData;
	}

	private function applyMessageSyncFrom(array &$mailboxData, int $messageMaxAge): void
	{
		if ($messageMaxAge < 0)
		{
			unset($mailboxData['OPTIONS']['sync_from']);

			return;
		}

		$syncOldLimit = LicenseManager::getSyncOldLimit();
		if ($syncOldLimit > 0 && $messageMaxAge > $syncOldLimit)
		{
			$messageMaxAge = $syncOldLimit;
		}

		$mailboxData['OPTIONS']['sync_from'] = SyncPeriodBoundary::dayStartUtcMinusDays($messageMaxAge);
	}

	/**
	 * Zero is treated the same as null ("period not changed").
	 */
	private function applySyncFromOnUpdate(array $mailboxData, array $existingOptions, ?int $messageMaxAge): array
	{
		if ($messageMaxAge !== null && $messageMaxAge !== 0)
		{
			$this->applyMessageSyncFrom($mailboxData, $messageMaxAge);

			return $mailboxData;
		}

		if (array_key_exists('sync_from', $existingOptions))
		{
			$mailboxData['OPTIONS']['sync_from'] = $existingOptions['sync_from'];

			return $mailboxData;
		}

		// A missing key is the valid "no boundary" state of an unlimited mailbox: keep it missing.
		unset($mailboxData['OPTIONS']['sync_from']);

		return $mailboxData;
	}

	private function applyCrmSyncFrom(array &$mailboxData, ?int $syncDays = null): void
	{
		$syncDays ??= self::CRM_MAX_AGE;

		if ($syncDays < 0)
		{
			unset($mailboxData['OPTIONS'][CrmOption::SyncFrom->value]);

			return;
		}

		$mailboxData['OPTIONS'][CrmOption::SyncFrom->value] = strtotime(sprintf('-%u days', $syncDays));
	}

	private function defaultCrmOptionValues(int $ownerUserId): array
	{
		return [
			CrmOption::SyncFrom->value     => strtotime(sprintf('-%u days', self::CRM_MAX_AGE)),
			CrmOption::NewEntityIn->value  => CrmEntityType::Lead->value,
			CrmOption::NewEntityOut->value => CrmEntityType::Contact->value,
			CrmOption::LeadSource->value   => 'EMAIL',
			CrmOption::LeadResp->value     => [$ownerUserId],
		];
	}

	private function setDefaultCrmOptions(array $mailboxData): array
	{
		global $USER;

		if ($this->isCrmIntegrationAvailableForCurrentUser())
		{
			$mailboxData['OPTIONS']['flags'][] = CrmFlag::Connect->value;
			$ownerUserId = empty($mailboxData) ? $USER->getId() : $mailboxData['USER_ID'];
			foreach ($this->defaultCrmOptionValues((int)$ownerUserId) as $k => $v)
			{
				$mailboxData['OPTIONS'][$k] = $v;
			}
		}

		return $mailboxData;
	}

	private function isCrmIntegrationAvailableForCurrentUser(): bool
	{
		// Keyed by user: one process may serve several of them (agents, CLI, tests).
		static $isCrmAvailable = [];

		$userId = (int)Main\Engine\CurrentUser::get()->getId();

		return $isCrmAvailable[$userId] ??=
			Loader::includeModule('crm')
			&& MailboxAccess::hasCurrentUserAccessToEditMailboxIntegrationCrm()
			&& Feature::isCrmAvailable()
		;
	}

	private function deleteMailboxSenders(int $mailboxId): void
	{
		$this->setIsSmtpAvailable();
		if (!$this->isSMTPAvailable)
		{
			return;
		}

		$senders = Main\Mail\Sender::getByParentId($mailboxId);
		$senderIds = [];
		$emails = [];

		foreach ($senders as $sender)
		{
			$senderIds[] = (int)$sender['ID'];
			if (!in_array($sender['EMAIL'], $emails, true))
			{
				$emails[] = $sender['EMAIL'];
			}
		}

		if (!empty($senderIds))
		{
			Main\Mail\Sender::delete($senderIds);
		}

		foreach ($emails as $email)
		{
			Main\Mail\Sender::clearCustomSmtpCache($email);
		}
	}

	private function validateSenderName(string $newName, ?string $existingName): bool
	{
		if (empty($newName) || $newName === (string)$existingName)
		{
			return true;
		}

		$checkResult = Main\Mail\Sender::checkSenderNameCharacters($newName);
		if (!$checkResult->isSuccess())
		{
			$this->addError($checkResult->getErrorMessages()[0], self::SMTP_SENDER_ERROR_KEY);

			return false;
		}

		return true;
	}

	private function mergeSenderWithExisting(array &$senderFields, int $mailboxId, string $email, int $userId): void
	{
		$existingSender = self::getMailboxSender($mailboxId, $email, $userId);

		if (!$existingSender)
		{
			return;
		}

		$senderFields['ID'] = $existingSender['ID'];
		$confirmedSmtp = $existingSender['OPTIONS']['smtp'] ?? null;

		if (!empty($confirmedSmtp) && is_array($confirmedSmtp))
		{
			$newSmtp = $senderFields['OPTIONS']['smtp'] ?? [];
			$senderFields['OPTIONS']['smtp'] = array_filter($newSmtp) + $confirmedSmtp;

			$senderFields['IS_CONFIRMED'] = !array_diff(
				['server', 'port', 'protocol', 'login', 'password', 'isOauth'],
				array_keys(array_intersect_assoc($senderFields['OPTIONS']['smtp'], $confirmedSmtp)),
			);
		}
	}


	private function prepareSmtpSender(
		array $mailboxData,
		MailboxConnectDTO $mailboxConnectDTO,
	): ?array
	{
		$isSmtpOauthEnabled =
			!empty(MailServicesTable::getOAuthHelper($mailboxConnectDTO->service))
			&& self::isOauthSmtpEnabled($mailboxConnectDTO->service['NAME'])
		;

		$service = $mailboxConnectDTO->service;
		$isMicrosoftOauthService = in_array(
			$service['NAME'] ?? '',
			['office365', 'exchangeOnline', 'outlook.com'],
			true,
		);

		$mailboxConnectDTO->useSmtp = $mailboxConnectDTO->useSmtp
			|| ($isSmtpOauthEnabled && !$isMicrosoftOauthService);


		if (!$this->isSMTPAvailable || !$mailboxConnectDTO->useSmtp)
		{
			return null;
		}

		$senderFields = [
			'NAME' => $mailboxData['USERNAME'],
			'EMAIL' => $mailboxData['EMAIL'],
			'USER_ID' => $mailboxConnectDTO->userIdToConnect,
			'IS_CONFIRMED' => false,
			'IS_PUBLIC' => false,
			'OPTIONS' => ['source' => 'mail.client.config'],
		];

		$useSmtpSsl = !empty($service['SMTP_ENCRYPTION'])
			? $service['SMTP_ENCRYPTION'] === 'Y'
			: ($mailboxConnectDTO->sslSmtp ?? false);
		$smtpConfig = [
			'server' => $service['SMTP_SERVER'] ?: trim($mailboxConnectDTO->serverSmtp ?? ''),
			'port' => $service['SMTP_PORT'] ?: $mailboxConnectDTO->portSmtp,
			'protocol' => $useSmtpSsl ? 'smtps' : 'smtp',
			'login' => $service['SMTP_LOGIN_AS_IMAP'] === 'Y' ? $mailboxData['LOGIN'] : $mailboxConnectDTO->loginSmtp,
			'password' => '',
		];

		$isLimitEnabledAndExist = $mailboxConnectDTO->useLimitSmtp && $mailboxConnectDTO->limitSmtp !== null;
		if ($isLimitEnabledAndExist)
		{
			$smtpConfig['limit'] = $mailboxConnectDTO->limitSmtp;
		}

		if ($this->canReuseImapCredentialsForSmtp($service, $isSmtpOauthEnabled, $mailboxConnectDTO->storageOauthUid))
		{
			$smtpConfig['password'] = $mailboxData['PASSWORD'];
			$smtpConfig['isOauth'] = !empty($mailboxConnectDTO->storageOauthUid) && $isSmtpOauthEnabled;
		}
		elseif (!empty($mailboxConnectDTO->passwordSMTP))
		{
			if ($this->hasBadSymbolsInPassword($mailboxConnectDTO->passwordSMTP))
			{
				$this->addErrorWithMessage(self::SMTP_PASS_BAD_SYMBOLS_ERROR_KEY);

				return null;
			}

			$smtpConfig['password'] = $mailboxConnectDTO->passwordSMTP;
			$smtpConfig['isOauth'] = !empty($mailboxConnectDTO->storageOauthUid) && $isSmtpOauthEnabled;
		}

		if (empty($service['SMTP_SERVER']))
		{
			$hostname = $this->extractHostnameFromServerString((string)$smtpConfig['server']);
			if ($hostname === null)
			{
				$this->addSmtpConnectionError(
					Loc::getMessage('MAIL_MAILBOX_CONNECTOR_SMTP_SERVER_BAD'),
				);

				return null;
			}

			$smtpConfig['server'] = $hostname;

			if (!self::isValidMailHost($smtpConfig['server']))
			{
				$this->addSmtpConnectionError(
					Loc::getMessage('MAIL_MAILBOX_CONNECTOR_SMTP_SERVER_BAD'),
				);

				return null;
			}
		}

		if (empty($service['SMTP_PORT']))
		{
			if ($smtpConfig['port'] <= 0 || $smtpConfig['port'] > 65535)
			{
				$this->addSmtpConnectionError(
					Loc::getMessage('MAIL_MAILBOX_CONNECTOR_SMTP_PORT_BAD'),
				);

				return null;
			}
		}

		$senderFields['OPTIONS']['smtp'] = $smtpConfig;

		if (isset($mailboxData['OPTIONS']['useSenderName']))
		{
			$senderFields['OPTIONS']['useSenderName'] = $mailboxData['OPTIONS']['useSenderName'];
		}

		return $senderFields;
	}

	private function canReuseImapCredentialsForSmtp(
		array $service,
		bool $isSmtpOauthEnabled,
		string $storageOauthUid,
	): bool
	{
		if (($service['SMTP_PASSWORD_AS_IMAP']) !== 'Y')
		{
			return false;
		}

		$isSimplePasswordAuth = ($storageOauthUid === '' || $storageOauthUid === '0');

		return $isSimplePasswordAuth || $isSmtpOauthEnabled;
	}

	private function hasBadSymbolsInPassword(string $password): bool
	{
		return preg_match('/^\^/', $password) || preg_match('/\x00/', $password);
	}

	private function extractHostnameFromServerString(string $serverString): ?string
	{
		$regex = '/^(?:(?:http|https|ssl|tls|smtp):\/\/)?((?:[a-z0-9](?:-*[a-z0-9])*\.?)+)$/i';

		if (preg_match($regex, $serverString, $matches) && !empty($matches[1]))
		{
			return $matches[1];
		}

		return null;
	}

	private function createMailboxInternal(
		array $mailboxData,
		?array $senderFields,
		bool $isOAuth,
		bool $syncAfterConnection,
		array $access = [],
	): array
	{
		$mailboxId = \CMailbox::add($mailboxData);
		if (!($mailboxId > 0))
		{
			addEventToStatFile('mail', 'add_mailbox', $mailboxData['SERVICE_NAME'], 'failed');

			$this->addErrorWithMessage();

			return [];
		}

		if (!$this->syncNewMailboxCrmImapFilter(
			(int)$mailboxId,
			$mailboxData['OPTIONS']['flags'] ?? [],
		))
		{
			addEventToStatFile('mail', 'add_mailbox', $mailboxData['SERVICE_NAME'], 'failed');

			return [];
		}

		addEventToStatFile('mail', 'add_mailbox', $mailboxData['SERVICE_NAME'], 'success');

		if (!empty($senderFields))
		{
			$this->createMailboxSender((int)$mailboxId, $senderFields, $isOAuth);
		}

		// The mailbox already exists, so these side effects must run even when the sender was rejected.
		$accessState = $this->applyMailboxShareAccess($access, (int)$mailboxData['USER_ID'], $mailboxId);
		if ($accessState !== null)
		{
			Notification::dispatchAccessChangedNotifications(
				$mailboxId,
				(string)$mailboxData['EMAIL'],
				$accessState['previousAccess'],
				$accessState['currentAccess'],
				(int)Main\Engine\CurrentUser::get()->getId(),
				(int)$mailboxData['USER_ID'],
			);
		}

		$mailboxInstance = Mailbox::createInstance($mailboxId);
		if ($mailboxInstance)
		{
			$mailboxInstance->cacheDirs();
		}

		if ($this->hasErrors())
		{
			return [];
		}

		$this->setSuccess();

		if ($syncAfterConnection)
		{
			$this->syncMailbox($mailboxId);
		}

		$senderName = UserSenderDataProvider::getAddressInEmailAngleFormat(
			email: trim((string)$mailboxData['EMAIL']),
			senderName: (string)($senderFields['NAME'] ?? $mailboxData['USERNAME'] ?? ''),
		);

		return [
			'id' => $mailboxId,
			'email' => trim((string)$mailboxData['EMAIL']),
			'senderName' => $senderName,
		];
	}

	private function syncNewMailboxCrmImapFilter(int $mailboxId, array $flags): bool
	{
		$crmFilterResult = $this->syncCrmImapFilter(
			$mailboxId,
			$flags,
			CrmImapFilterContext::MailboxConnect,
		);
		if ($crmFilterResult->isSuccess())
		{
			return true;
		}

		$this->addResultErrors($crmFilterResult);
		\CMailbox::delete($mailboxId);

		return false;
	}

	private function createMailboxSender(int $mailboxId, array $senderFields, bool $isOAuth): void
	{
		$result = self::appendSender($senderFields, '', $mailboxId);

		if (!empty($result['errors']) && $result['errors'] instanceof Main\ErrorCollection)
		{
			$this->addErrors($result['errors'], $isOAuth, true);

			return;
		}

		if (!empty($result['error']))
		{
			$this->addError($result['error'], self::SMTP_SENDER_ERROR_KEY);

			return;
		}

		if (empty($result['confirmed']))
		{
			$this->addError('MAIL_CLIENT_CONFIG_SMTP_CONFIRM', self::SMTP_SENDER_ERROR_KEY);
		}
	}

	private function mergeWithExistingData(MailboxConnectDTO $dto, array $existingData): void
	{
		$imap = $existingData['imap'];
		$smtp = $existingData['smtp'];

		// Email and login are immutable in update — always use existing values.
		$dto->email = $imap['email'];
		$dto->login = $imap['login'];
		$dto->password ??= $imap['password'];
		$dto->serviceId = $imap['serviceId'];
		$dto->server ??= $imap['server'];
		$dto->port ??= $imap['port'];
		$dto->ssl ??= ($imap['ssl'] ?? 'N') === 'Y';
		$dto->storageOauthUid ??= '';
		$dto->syncAfterConnection ??= false;
		$dto->useSmtp ??= ($smtp['enabled'] ?? 'N') === 'Y';
		$dto->serverSmtp ??= $smtp['server'];
		$dto->portSmtp ??= $smtp['port'];
		$dto->sslSmtp ??= ($smtp['ssl'] ?? 'N') === 'Y';
		$dto->loginSmtp ??= $smtp['login'];
		$dto->passwordSMTP ??= '';
		$dto->useLimitSmtp ??= $smtp['useLimit'];
		$dto->limitSmtp ??= $smtp['limit'];
		$dto->mailboxName ??= $existingData['mailboxName'];
		$dto->senderName ??= $existingData['senderName'];
		$dto->iCalAccess ??= ($existingData['iCalAccess'] ?? 'N') === 'Y';
		$dto->crmOptions ??= is_array($existingData['crmOptions'] ?? null)
			? CrmOptions::fromArray($existingData['crmOptions'])
			: $existingData['crmOptions'];
		$dto->userIdToConnect ??= $existingData['userId'];
		$dto->serviceConfig ??= [
			'serviceType' => $existingData['service']['type'] ?? 'imap',
			'name' => $existingData['service']['name'] ?? 'other',
		];
		$dto->shareAccess ??= $existingData['shareAccess'];
		$dto->useSenderName ??= $existingData['useSenderName'];
		$dto->link ??= $existingData['mailbox']['link'] ?? null;
		$dto->uploadOutgoing ??= !in_array('deny_upload', (array)($existingData['options']['flags'] ?? []), true);
	}

	/**
	 * Clients may send an empty string instead of omitting a port (e.g. a form with a blank
	 * port field). Treat it as "not provided" so that defaults or stored values apply.
	 */
	private function normalizeEmptyPorts(MailboxConnectDTO $dto): void
	{
		if ($dto->port !== null && trim($dto->port) === '')
		{
			$dto->port = null;
		}

		if ($dto->portSmtp !== null && trim($dto->portSmtp) === '')
		{
			$dto->portSmtp = null;
		}
	}

	private function hasCredentialsChanged(MailboxConnectDTO $dto): bool
	{
		return $dto->password !== null
			|| $dto->server !== null
			|| $dto->port !== null
			|| $dto->storageOauthUid !== null
		;
	}

	private function validateImapConnection(array $mailboxData, bool $isOAuth): bool
	{
		$unseen = Mail\Helper::getImapUnseen($mailboxData, 'inbox', $error, $errors);
		if ($unseen === false)
		{
			if ($errors instanceof Main\ErrorCollection)
			{
				$this->addErrors($errors, $isOAuth);
			}
			else
			{
				$this->addErrorWithMessage();
			}

			return false;
		}

		return true;
	}

	private function appendMailboxSettingsConfig(array $mailboxData): array
	{
		$settingsConfig = MailboxSettingsConfig::getClientConfig();

		return array_merge($mailboxData, [
			'mailSyncIntervals' => $settingsConfig['mailSyncIntervals'],
			'crmSyncIntervals' => $settingsConfig['crmSyncIntervals'],
			'crmEntities' => $settingsConfig['crmEntities'],
			'crmSources' => $settingsConfig['crmSources'],
			'defaultCrmSource' => $settingsConfig['defaultCrmSource'],
			'defaults' => $settingsConfig['defaults'],
			'crmAvailable' => $settingsConfig['crmAvailable'],
			'canEditCrmIntegration' => $settingsConfig['canEditCrmIntegration'],
		]);
	}

	/**
	 * @return array{
	 *     imap: array{email: string, login: string, serviceId: int, server: string, port: string, ssl: string, isOAuth: bool, oauthUid: string|null},
	 *     smtp: array{enabled: 'Y'|'N', server: string, port: string, ssl: 'Y'|'N', login: string, useLimit: bool, limit: int|null},
	 *     service: array{name: string|null, type: string|null, link: string|null, isOAuth: bool, oauthSmtpEnabled: bool, smtpServer: string, smtpLoginAsImap: bool, smtpPasswordAsImap: bool},
	 *     mailbox: array{link: string},
	 *     denyUpload: bool,
	 *     mailboxName: string,
	 *     senderName: string,
	 *     useSenderName: bool,
	 *     iCalAccess: string,
	 *     crmOptions: array{enabled: 'Y'|'N', filterActive?: bool, config: array<string, mixed>},
	 *     mailSyncIntervals: array<array{value: int, label: string}>,
	 *     crmSyncIntervals: array<array{value: int, label: string}>,
	 *     crmEntities: array<array{value: string, label: string}>,
	 *     crmSources: array<array{value: string, label: string}>,
	 *     defaultCrmSource: string,
	 *     defaults: array<string, mixed>,
	 *     crmAvailable: bool,
	 *     canEditCrmIntegration: bool,
	 *     shareAccess: string[],
	 *     userId: int,
	 * }
	 */
	public function getMailboxDataSafe(int $mailboxId): array
	{
		$mailboxData = $this->getMailboxData($mailboxId);
		if (empty($mailboxData))
		{
			return [];
		}

		if ($mailboxData['crmOptions'] instanceof CrmOptions)
		{
			$mailboxData['crmOptions'] = $this->convertCrmOptionsForMobile($mailboxData['crmOptions']);
		}

		unset($mailboxData['imap']['password'], $mailboxData['options'], $mailboxData['periodCheck']);

		return $this->appendMailboxSettingsConfig($mailboxData);
	}

	/**
	 * @param bool $withCrmFilterState Include the actual gate state as crmOptions.filterActive
	 * @return array{
	 *     imap: array{email: string, login: string, password: string, serviceId: int, server: string, port: string, ssl: string, isOAuth: bool, oauthUid: string|null, oauthUser: array|null},
	 *     smtp: array{enabled: 'Y'|'N', server: string, port: string, ssl: 'Y'|'N', login: string, useLimit: bool, limit: int|null},
	 *     service: array{name: string|null, type: string|null, link: string|null, isOAuth: bool, oauthSmtpEnabled: bool, smtpServer: string, smtpLoginAsImap: bool, smtpPasswordAsImap: bool},
	 *     mailbox: array{link: string},
	 *     denyUpload: bool,
	 *     mailboxName: string,
	 *     senderName: string,
	 *     defaultSenderName: string,
	 *     useSenderName: bool,
	 *     iCalAccess: string,
	 *     crmOptions: array{enabled: 'Y'|'N', filterActive?: bool, config: array<string, mixed>},
	 *     shareAccess: string[],
	 *     shareAccessUsers: array<array{id: int, title: string, imageUrl: string|null}>,
	 *     userId: int,
	 *     periodCheck: int,
	 *     lastMailCheck: array{date: int|null, isSuccess: bool},
	 *     providerRestriction: string|null,
	 *     options: array,
	 * }|null
	 */
	public function getMailboxData(int $mailboxId, bool $withCrmFilterState = true): ?array
	{
		if (!MailboxAccess::hasCurrentUserAnyAccessToMailbox($mailboxId))
		{
			return null;
		}

		$mailbox = Mail\MailboxTable::query()
			->setSelect(['*'])
			->where('ID', $mailboxId)
			->whereIn('ACTIVE', [
				MailboxStatus::Active->value,
				MailboxStatus::Pending->value,
				MailboxStatus::Canceled->value,
			])
			->where('SERVER_TYPE', 'imap')
			->fetch()
		;

		if (empty($mailbox))
		{
			return null;
		}

		$service = null;
		if ($mailbox['SERVICE_ID'] > 0)
		{
			$service = Mail\MailServicesTable::getById($mailbox['SERVICE_ID'])->fetch() ?: null;
		}

		$options = $mailbox['OPTIONS'] ?? [];
		$shareAccess = $this->getShareAccessData($mailboxId);

		$passwordlessSmtp = is_array($options['passwordless_smtp'] ?? null) ? $options['passwordless_smtp'] : [];
		$senderName = !empty($passwordlessSmtp['senderName'])
			? (string)$passwordlessSmtp['senderName']
			: (string)($mailbox['USERNAME'] ?? '');

		$serviceName = $service['NAME'] ?? null;
		$providerRestriction = (
			ProviderAccessRestriction::isFeatureEnabled()
			&& ProviderAccessRestriction::isRestricted($serviceName, $mailbox['EMAIL'] ?? null)
		)
			? ProviderAccessRestriction::resolveProviderName($serviceName)
			: null
		;

		return [
			'imap' => $this->getImapData($mailbox),
			'smtp' => $this->getSmtpData($mailboxId, $mailbox),
			'service' => [
				'name' => $service['NAME'] ?? null,
				'type' => $service['SERVICE_TYPE'] ?? null,
				'link' => $service['LINK'] ?? null,
				'isOAuth' => $this->isMailboxOAuth($mailbox),
				'oauthSmtpEnabled' => !empty($service) && self::isOauthSmtpEnabled($service['NAME'] ?? ''),
				'smtpServer' => $service['SMTP_SERVER'] ?? '',
				'smtpLoginAsImap' => ($service['SMTP_LOGIN_AS_IMAP'] ?? 'N') === 'Y',
				'smtpPasswordAsImap' => ($service['SMTP_PASSWORD_AS_IMAP'] ?? 'N') === 'Y',
			],
			'mailbox' => [
				'link' => (string)($mailbox['LINK'] ?? ''),
			],
			'denyUpload' => in_array('deny_upload', (array)($options['flags'] ?? []), true),
			'mailboxName' => (string)($mailbox['NAME'] ?? ''),
			'senderName' => $senderName,
			'defaultSenderName' => $this->getDefaultSenderName(),
			'useSenderName' => $this->resolveUseSenderName($mailbox, $mailboxId),
			'iCalAccess' => $options['ical_access'] ?? 'N',
			'crmOptions' => $this->getCrmData($mailboxId, $options, $withCrmFilterState),
			'shareAccess' => $shareAccess,
			'shareAccessUsers' => $this->resolveShareAccessUsers($shareAccess),
			'userId' => (int)$mailbox['USER_ID'],
			'periodCheck' => (int)$mailbox['PERIOD_CHECK'],
			'lastMailCheck' => $this->getLastMailCheckData($mailboxId, (int)$mailbox['USER_ID']),
			'providerRestriction' => $providerRestriction,
			'options' => $options,
		];
	}

	/**
	 * @return array{date: int|null, isSuccess: bool}
	 */
	private function getLastMailCheckData(int $mailboxId, int $userId): array
	{
		$syncManager = new MailboxSyncManager($userId);

		return [
			'date' => $syncManager->getLastMailboxSyncTime($mailboxId),
			'isSuccess' => $syncManager->getCachedConnectionStatus($mailboxId),
		];
	}

	private function isMailboxOAuth(array $mailbox): bool
	{
		return (bool)Mail\Helper\OAuth::getInstanceByMeta($mailbox['PASSWORD'] ?? '');
	}

	private static function getMailboxSender(
		int $mailboxId,
		?string $email = null,
		?int $userId = null,
		bool $readOnly = false,
	): ?array
	{
		$mailboxSender = null;
		$senders = Main\Mail\Sender::getByParentId($mailboxId);
		if (!empty($senders))
		{
			foreach ($senders as $sender)
			{
				if ($mailboxSender
					|| empty($sender['OPTIONS']['smtp']['server'])
					|| !empty($sender['OPTIONS']['smtp']['encrypted'])
				)
				{
					if (!$readOnly)
					{
						Main\Mail\Sender::delete([$sender['ID']]);
					}

					continue;
				}

				$mailboxSender = $sender;
			}
		}

		if ($mailboxSender || empty($email) || empty($userId))
		{
			return $mailboxSender;
		}

		$senders = Main\Mail\Sender::getByEmail($email, $userId);
		if (empty($senders))
		{
			return null;
		}

		foreach ($senders as $sender)
		{
			$source = $sender['OPTIONS']['source'] ?? '';
			if (
				$source !== 'mail.client.config'
				|| empty($sender['OPTIONS']['smtp']['server'])
				|| !empty($sender['OPTIONS']['smtp']['encrypted'])
			)
			{
				continue;
			}

			$parentId = (int)($sender['PARENT_ID'] ?? 0);
			if (
				$sender['PARENT_MODULE_ID'] === 'mail'
				&& $parentId > 0
				&& $parentId !== $mailboxId
			)
			{
				continue;
			}

			if (!$readOnly && ($sender['PARENT_MODULE_ID'] !== 'mail' || (int)$sender['PARENT_ID'] !== $mailboxId))
			{
				Main\Mail\Internal\SenderTable::update(
					(int)$sender['ID'],
					[
						'PARENT_MODULE_ID' => 'mail',
						'PARENT_ID' => $mailboxId,
					],
				);

				$sender['PARENT_MODULE_ID'] = 'mail';
				$sender['PARENT_ID'] = $mailboxId;
			}

			$mailboxSender = $sender;

			break;
		}

		return $mailboxSender;
	}

	private static function rebindMailboxSendersToOwner(int $mailboxId, int $newOwnerId): void
	{
		$senderIds = array_column(
			Main\Mail\Internal\SenderTable::query()
				->setSelect(['ID'])
				->where('PARENT_MODULE_ID', 'mail')
				->where('PARENT_ID', $mailboxId)
				->fetchAll(),
			'ID',
		);

		foreach ($senderIds as $senderId)
		{
			Main\Mail\Internal\SenderTable::update((int)$senderId, ['USER_ID' => $newOwnerId]);
		}
	}

	private function resolveUseSenderName(array $mailbox, int $mailboxId): bool
	{
		$sender = self::getMailboxSender($mailboxId, $mailbox['EMAIL'], (int)($mailbox['USER_ID'] ?? 0), readOnly: true);

		if ($sender)
		{
			return Main\Mail\Sender\UserSenderDataProvider::shouldUseCustomSenderName($sender);
		}

		$options = $mailbox['OPTIONS'] ?? [];
		$passwordlessSmtp = is_array($options['passwordless_smtp'] ?? null) ? $options['passwordless_smtp'] : [];

		if (array_key_exists('useSenderName', $passwordlessSmtp))
		{
			return (bool)$passwordlessSmtp['useSenderName'];
		}

		$senderName = !empty($passwordlessSmtp['senderName'])
			? (string)$passwordlessSmtp['senderName']
			: (string)($mailbox['USERNAME'] ?? '');

		if (isset($options['useSenderName']))
		{
			return $options['useSenderName'] && !empty($senderName);
		}

		if (empty($senderName))
		{
			return false;
		}

		$userId = (int)($mailbox['USER_ID'] ?? 0);

		return Main\Mail\Sender\UserSenderDataProvider::getUserFormattedName($userId) !== $senderName;
	}

	/**
	 * @return array{
	 *     email: string,
	 *     login: string,
	 *     password: string,
	 *     serviceId: int,
	 *     server: string,
	 *     port: string,
	 *     ssl: string,
	 *     isOAuth: bool,
	 *     oauthUid: string|null,
	 *     oauthUser: array|null,
	 * }
	 */
	private function getImapData(array $mailbox): array
	{
		$oauthMeta = Mail\Helper\OAuth::parseMeta($mailbox['PASSWORD'] ?? '');
		$oauthUser = null;
		if ($oauthMeta !== null)
		{
			$oauthUser = Mail\Helper\OAuth::getUserDataByMeta($mailbox['PASSWORD'] ?? '') ?: null;
			if (is_array($oauthUser))
			{
				$oauthUser['email'] = $mailbox['EMAIL'];
			}
		}

		return [
			'email' => (string)($mailbox['EMAIL'] ?? ''),
			'login' => (string)($mailbox['LOGIN'] ?? ''),
			'password' => (string)($mailbox['PASSWORD'] ?? ''),
			'serviceId' => (int)$mailbox['SERVICE_ID'],
			'server' => (string)($mailbox['SERVER'] ?? ''),
			'port' => (string)$mailbox['PORT'],
			'ssl' => $mailbox['USE_TLS'] ?: 'N',
			'isOAuth' => $oauthMeta !== null,
			'oauthUid' => $oauthMeta['key'] ?? null,
			'oauthUser' => $oauthUser,
		];
	}

	/**
	 * @return array{
	 *     enabled: 'Y'|'N',
	 *     server: string,
	 *     port: string,
	 *     ssl: 'Y'|'N',
	 *     login: string,
	 *     useLimit: bool,
	 *     limit: int|null,
	 * }
	 */
	private function getSmtpData(int $mailboxId, array $mailbox): array
	{
		$sender = self::getMailboxSender($mailboxId, $mailbox['EMAIL'], (int)($mailbox['USER_ID'] ?? 0), readOnly: true);

		$smtpOptions = $sender['OPTIONS']['smtp'] ?? [];
		$hasSmtp = !empty($smtpOptions['server']);

		return [
			'enabled' => $hasSmtp ? 'Y' : 'N',
			'server' => $smtpOptions['server'] ?? '',
			'port' => (string)($smtpOptions['port'] ?? ''),
			'ssl' => ($smtpOptions['protocol'] ?? '') === 'smtps' ? 'Y' : 'N',
			'login' => $smtpOptions['login'] ?? '',
			'useLimit' => isset($smtpOptions['limit']),
			'limit' => $smtpOptions['limit'] ?? null,
		];
	}

	/**
	 * @return array{
	 *     enabled: 'Y'|'N',
	 *     filterActive?: bool,
	 *     config: array{
	 *         crm_sync_days: int|null,
	 *         crm_new_entity_in: string,
	 *         crm_new_entity_out: string,
	 *         crm_lead_source: string,
	 *         crm_lead_resp: array,
	 *         crm_lead_resp_users: array<array{id: int, title: string, imageUrl: string}>,
	 *         crm_new_lead_for: string,
	 *         crm_public: 'Y'|'N',
	 *         crm_vcf: 'Y'|'N',
	 *     }|array{},
	 * }
	 */
	private function getCrmData(int $mailboxId, array $options, bool $withFilterState = true): array
	{
		$crmEnabled = in_array(CrmFlag::Connect->value, $options['flags'] ?? [], true) ? 'Y' : 'N';
		$crmOptions = ['enabled' => $crmEnabled];

		// The actual gate state is read for the settings form only: write paths sync the gate anyway.
		if ($withFilterState)
		{
			$crmOptions['filterActive'] = CrmImapFilter::isConnected($mailboxId);
		}

		$crmOptions['config'] = [];

		if ($crmEnabled !== 'Y')
		{
			return $crmOptions;
		}

		$syncDays = null;
		if (!empty($options[CrmOption::SyncFrom->value]))
		{
			$syncDays = max((int)floor((time() - (int)$options[CrmOption::SyncFrom->value]) / 86400), 0);
		}
		elseif (isset($options[CrmOption::SyncDays->value]))
		{
			$syncDays = (int)$options[CrmOption::SyncDays->value];
		}

		$flags = (array)($options['flags'] ?? []);
		$isIncomingEntityDenied = in_array(CrmFlag::DenyNewLead->value, $flags, true)
			|| in_array(CrmFlag::DenyEntityIn->value, $flags, true);
		$isOutgoingEntityDenied = in_array(CrmFlag::DenyNewLead->value, $flags, true)
			|| in_array(CrmFlag::DenyEntityOut->value, $flags, true);

		$newLeadFor = $options[CrmOption::NewLeadFor->value] ?? [];
		if (is_array($newLeadFor))
		{
			$newLeadFor = implode(',', $newLeadFor);
		}

		$crmOptions['config'] = [
			'crm_sync_days' => $syncDays,
			'crm_new_entity_in' => $isIncomingEntityDenied ? '' : ($options[CrmOption::NewEntityIn->value] ?? ''),
			'crm_new_entity_out' => $isOutgoingEntityDenied ? '' : ($options[CrmOption::NewEntityOut->value] ?? ''),
			'crm_lead_source' => $options[CrmOption::LeadSource->value] ?? '',
			'crm_lead_resp' => (array)($options[CrmOption::LeadResp->value] ?? []),
			'crm_lead_resp_users' => $this->getCrmLeadRespUsers((array)($options[CrmOption::LeadResp->value] ?? [])),
			'crm_new_lead_for' => $newLeadFor ?? '',
			'crm_public' => in_array(CrmFlag::PublicBind->value, $flags, true) ? 'Y' : 'N',
			'crm_vcf' => !in_array(CrmFlag::DenyNewContact->value, $flags, true) ? 'Y' : 'N',
		];

		return $crmOptions;
	}

	/**
	 * @param int[] $userIds
	 * @return array<array{id: int, title: string, imageUrl: string}>
	 */
	private function getCrmLeadRespUsers(array $userIds): array
	{
		$userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
		if (empty($userIds))
		{
			return [];
		}

		$users = Main\UserTable::getList([
			'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'PERSONAL_PHOTO'],
			'filter' => ['@ID' => $userIds],
		])->fetchAll();

		$order = array_flip($userIds);
		usort($users, static function(array $first, array $second) use ($order): int {
			return ($order[(int)$first['ID']] ?? PHP_INT_MAX) <=> ($order[(int)$second['ID']] ?? PHP_INT_MAX);
		});

		return array_map(static function(array $user): array {
			$photoId = (int)($user['PERSONAL_PHOTO'] ?? 0);

			return [
				'id' => (int)$user['ID'],
				'title' => \CUser::FormatName(\CSite::GetNameFormat(false), $user, true, false),
				'imageUrl' => $photoId > 0 ? (string)\CFile::GetPath($photoId) : '',
			];
		}, $users);
	}

	/**
	 * @param string[] $accessCodes
	 * @return array<int, array{id: int, title: string, imageUrl: string|null}>
	 */
	public function resolveShareAccessUsers(array $accessCodes): array
	{
		$userIds = [];
		foreach ($accessCodes as $code)
		{
			if (preg_match('/^U(\d+)$/', $code, $matches))
			{
				$userIds[] = (int)$matches[1];
			}
		}

		if (empty($userIds))
		{
			return [];
		}

		$users = Main\UserTable::getList([
			'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'PERSONAL_PHOTO'],
			'filter' => ['=ID' => $userIds],
		])->fetchAll();

		$result = [];
		foreach ($users as $user)
		{
			$photoUrl = null;
			if ((int)($user['PERSONAL_PHOTO'] ?? 0) > 0)
			{
				$fileArray = \CFile::GetFileArray($user['PERSONAL_PHOTO']);
				$photoUrl = $fileArray['SRC'] ?? null;
			}

			$result[] = [
				'id' => (int)$user['ID'],
				'title' => \CUser::FormatName(
					\CSite::GetNameFormat(false),
					$user,
					true,
					false,
				),
				'imageUrl' => $photoUrl,
			];
		}

		return $result;
	}

	public function getDefaultSenderName(): string
	{
		return UserSenderDataProvider::getUserFormattedName() ?? '';
	}

	/**
	 * @return array{id: int, title: string, imageUrl: string|null}
	 */
	public function getCurrentUserData(): array
	{
		$userId = (int)Main\Engine\CurrentUser::get()->getId();

		$user = Main\UserTable::getList([
			'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'PERSONAL_PHOTO'],
			'filter' => ['=ID' => $userId],
		])->fetch();

		$photoUrl = null;
		if ($user && (int)($user['PERSONAL_PHOTO'] ?? 0) > 0)
		{
			$fileArray = \CFile::GetFileArray($user['PERSONAL_PHOTO']);
			$photoUrl = $fileArray['SRC'] ?? null;
		}

		return [
			'id' => $userId,
			'title' => $user
				? \CUser::FormatName(\CSite::GetNameFormat(false), $user, true, false)
				: '',
			'imageUrl' => $photoUrl,
		];
	}

	/**
	 * @return array{
	 *     enabled: 'Y'|'N',
	 *     config: array{
	 *         crm_sync_days?: int|null,
	 *         crm_new_entity_in?: string,
	 *         crm_new_entity_out?: string,
	 *         crm_lead_source?: string,
	 *         crm_lead_resp?: int[],
	 *         crm_new_lead_for?: string,
	 *         crm_public?: 'Y'|'N',
	 *         crm_vcf?: 'Y'|'N',
	 *     }|array{}
	 * }
	 */
	private function convertCrmOptionsForMobile(CrmOptions $crmOptions): array
	{
		if (!$crmOptions->enabled)
		{
			return [
				'enabled' => 'N',
				'config' => [],
			];
		}

		return [
			'enabled' => 'Y',
			'config' => [
				'crm_sync_days' => $crmOptions->syncDays,
				'crm_new_entity_in' => $crmOptions->newEntityIn?->value ?? '',
				'crm_new_entity_out' => $crmOptions->newEntityOut?->value ?? '',
				'crm_lead_source' => $crmOptions->leadSource ?? '',
				'crm_lead_resp' => $crmOptions->leadResp,
				'crm_new_lead_for' => implode(',', $crmOptions->newLeadFor),
				'crm_public' => $crmOptions->public ? 'Y' : 'N',
				'crm_vcf' => $crmOptions->vcf ? 'Y' : 'N',
			],
		];
	}

	/**
	 * @return string[]
	 */
	private function getShareAccessData(int $mailboxId): array
	{
		$accessCodes = Mail\Internals\MailboxAccessTable::getList([
			'select' => ['ACCESS_CODE'],
			'filter' => ['=MAILBOX_ID' => $mailboxId],
		])->fetchAll();

		return array_column($accessCodes, 'ACCESS_CODE');
	}

	/**
	 * @return array{
	 *     id: int,
	 *     email: string,
	 * }
	 */
	public function updateMailbox(
		int $mailboxId,
		MailboxConnectDTO $dto,
	): array
	{
		if (!MailboxAccess::hasCurrentUserAccessToEditMailbox($mailboxId))
		{
			$this->addErrorWithMessage();

			return [];
		}

		$existingData = $this->getMailboxData($mailboxId, withCrmFilterState: false);
		if ($existingData === null)
		{
			$this->addErrorWithMessage();

			return [];
		}

		$this->normalizeEmptyPorts($dto);
		$credentialsChanged = $this->hasCredentialsChanged($dto);
		// Must be read before the merge fills crmOptions from the stored state.
		$crmSettingsRequested = $dto->crmOptions !== null;
		$this->mergeWithExistingData($dto, $existingData);

		$originalOwnerId = $existingData['userId'];
		$newOwnerId = $dto->userIdToConnect;
		$ownerChanged = $newOwnerId !== $originalOwnerId;

		if ($ownerChanged)
		{
			if (!MailboxAccess::hasCurrentUserAccessToChangeMailboxOwner($mailboxId))
			{
				$this->addErrorWithMessage();

				return [];
			}

			if (!self::checkConnectionLimits($newOwnerId))
			{
				$this->addErrorWithMessage();

				return [];
			}

			$existingMailbox = Mailbox::findActiveMailbox($newOwnerId, $dto->email, SITE_ID);
			if (!empty($existingMailbox))
			{
				$this->addError(Loc::getMessage('MAIL_MAILBOX_CONNECTOR_EMAIL_EXISTS_NEW_OWNER'), self::EXISTS_ERROR_KEY);

				return [];
			}
		}

		try
		{
			$connectionData = $this->prepareConnectionData(
				$dto->login,
				$dto->password,
				$dto->storageOauthUid,
				$dto->serviceConfig,
				$dto->serviceId,
			);
		}
		catch (\Exception $e)
		{
			$this->addErrorWithMessage((int)$e->getMessage() ?: self::STANDARD_ERROR_KEY);

			return [];
		}

		$dto->service = $connectionData['service'];
		$dto->site = $connectionData['site'];
		$dto->login = $connectionData['login'];
		$dto->password = $connectionData['password'];
		$isOAuth = $connectionData['isOAuth'];

		$mailboxData = $this->buildMailboxData($dto);

		$existingOptions = $existingData['options'] ?? [];
		$mailboxData['OPTIONS'] = array_merge($existingOptions, $mailboxData['OPTIONS']);

		$preservedFlags = array_values(array_diff(
			(array)($existingOptions['flags'] ?? []),
			['deny_upload'],
		));
		$serviceUploadOutgoing = $dto->service['UPLOAD_OUTGOING'] ?? '';
		if ($serviceUploadOutgoing === 'N' || (empty($serviceUploadOutgoing) && $dto->uploadOutgoing === false))
		{
			$preservedFlags[] = 'deny_upload';
		}
		$mailboxData['OPTIONS']['flags'] = $preservedFlags;
		$mailboxData = $this->applySyncFromOnUpdate($mailboxData, $existingOptions, $dto->messageMaxAge);
		$mailboxData['OPTIONS'][CrmOption::SyncFrom->value] = $existingOptions[CrmOption::SyncFrom->value]
			?? $mailboxData['OPTIONS'][CrmOption::SyncFrom->value];
		$mailboxData['OPTIONS']['name'] = $mailboxData['USERNAME'];

		if ($credentialsChanged && !$dto->skipConnectionValidation && !$this->validateImapConnection($mailboxData, $isOAuth))
		{
			return [];
		}

		if ($dto->skipConnectionValidation && $credentialsChanged)
		{
			$imapServer = $mailboxData['SERVER'] ?? '';
			if ($imapServer !== '' && !self::isValidMailHost($imapServer))
			{
				$this->addError(
					Loc::getMessage('MAIL_PASSWORDLESS_ERROR_INVALID_HOST') ?? '',
					self::SERVER_RESPONSE_ERROR_KEY,
				);

				return [];
			}
		}

		$mailboxData['OPTIONS']['flags'] = array_values(array_diff(
			(array)($mailboxData['OPTIONS']['flags'] ?? []),
			CrmFlag::values(),
		));

		if (!$crmSettingsRequested)
		{
			$this->preserveMailboxCrmSettings($mailboxData, $existingData);
		}
		elseif (!$this->isCrmIntegrationAvailableForCurrentUser())
		{
			if (!$this->isCrmStateUnchanged($dto->crmOptions, $existingData))
			{
				$this->addError(
					(string)Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CRM_ACCESS_DENIED'),
					self::CRM_ACCESS_DENIED_ERROR_KEY,
				);

				return [];
			}

			$this->preserveMailboxCrmSettings($mailboxData, $existingData);
		}
		elseif ($dto->crmOptions?->enabled)
		{
			$mailboxData = $this->applyCrmOptions($mailboxData, $dto->crmOptions);
		}

		if (Loader::includeModule('calendar'))
		{
			$mailboxData['OPTIONS']['ical_access'] = $dto->iCalAccess ? 'Y' : 'N';
		}

		$this->setIsSmtpAvailable();

		if (!$dto->useSmtp)
		{
			$this->deleteMailboxSenders($mailboxId);
		}

		$senderFields = $this->prepareSmtpSender($mailboxData, $dto);

		if ($this->hasErrors())
		{
			return [];
		}

		if ($dto->skipConnectionValidation)
		{
			if ($senderFields !== null)
			{
				$mailboxData['OPTIONS']['passwordless_smtp'] = [
					'server' => $senderFields['OPTIONS']['smtp']['server'] ?? '',
					'port' => $senderFields['OPTIONS']['smtp']['port'] ?? '',
					'login' => $senderFields['OPTIONS']['smtp']['login'] ?? '',
					'protocol' => $senderFields['OPTIONS']['smtp']['protocol'] ?? '',
					'limit' => ($dto->useLimitSmtp ? ($dto->limitSmtp ?? 0) : 0),
					'senderName' => $dto->senderName ?? '',
					'useSenderName' => $dto->useSenderName ?? false,
				];
			}
			else
			{
				unset($mailboxData['OPTIONS']['passwordless_smtp']);
			}

			$senderFields = null;
		}

		else if ($senderFields !== null)
		{
			if (!$this->validateSenderName($senderFields['NAME'] ?? '', $existingData['senderName']))
			{
				return [];
			}

			$this->mergeSenderWithExisting($senderFields, $mailboxId, $mailboxData['EMAIL'], $existingData['userId'] ?? 0);

			if ($dto->useLimitSmtp === false)
			{
				unset($senderFields['OPTIONS']['smtp']['limit']);
			}
		}

		if (!$this->assertSharedMailboxLimit(
			$dto->shareAccess ?? [],
			(int)$mailboxData['USER_ID'],
			$mailboxId,
		))
		{
			return [];
		}

		if ($ownerChanged)
		{
			$crmLeadResp = array_map('intval', (array)($mailboxData['OPTIONS']['crm_lead_resp'] ?? []));
			$pos = array_search($originalOwnerId, $crmLeadResp, true);
			if ($pos !== false)
			{
				$crmLeadResp[$pos] = $newOwnerId;
				$crmLeadResp = array_values(array_unique($crmLeadResp));
			}
			$mailboxData['OPTIONS']['crm_lead_resp'] = $crmLeadResp;

			unset(
				$mailboxData['OPTIONS']['fired_owner_at'],
				$mailboxData['OPTIONS']['fired_owner_notified_7d_at'],
			);

			// Drop the previous owner from share-access — he/she has been fired
			if (is_array($dto->shareAccess))
			{
				$previousOwnerAccessCode = 'U' . $originalOwnerId;
				$dto->shareAccess = array_values(array_filter(
					$dto->shareAccess,
					static fn($code) => $code !== $previousOwnerAccessCode,
				));
			}
		}

		return $this->updateMailboxInternal(
			$mailboxId,
			$mailboxData,
			$senderFields,
			$isOAuth,
			$existingData,
			$dto->syncAfterConnection ?? false,
			$dto->shareAccess ?? [],
			$ownerChanged,
			$dto->skipConnectionValidation,
		);
	}

	/**
	 * @return array{
	 *     id: int,
	 *     email: string,
	 * }
	 */
	private function updateMailboxInternal(
		int $mailboxId,
		array $mailboxData,
		?array $senderFields,
		bool $isOAuth,
		array $existingData,
		bool $syncAfterConnection,
		array $shareAccess = [],
		bool $ownerChanged = false,
		bool $skipConnectionValidation = false,
	): array
	{
		unset($mailboxData['SYNC_LOCK']);

		$updateFields = [
			'EMAIL' => $mailboxData['EMAIL'],
			'NAME' => $mailboxData['NAME'],
			'USERNAME' => $mailboxData['USERNAME'],
			'LOGIN' => $mailboxData['LOGIN'],
			'PASSWORD' => $mailboxData['PASSWORD'],
			'SERVER' => $mailboxData['SERVER'],
			'PORT' => $mailboxData['PORT'],
			'USE_TLS' => $mailboxData['USE_TLS'],
			'LINK' => $mailboxData['LINK'],
			'OPTIONS' => $mailboxData['OPTIONS'],
			'PERIOD_CHECK' => $mailboxData['PERIOD_CHECK'],
			'USER_ID' => $mailboxData['USER_ID'],
		];

		$result = \CMailbox::update($mailboxId, $updateFields);
		if (!$result)
		{
			$this->addErrorWithMessage();

			return [];
		}

		if ($ownerChanged)
		{
			$originalOwnerId = $existingData['userId'];
			$newOwnerId = (int)$mailboxData['USER_ID'];
			Mail\MailboxTable::cleanOwnerCacheByUserId($originalOwnerId);
			Mail\MailboxTable::cleanOwnerCacheByUserId($newOwnerId);
			Mail\MailboxTable::cleanAllSharedCache();
			self::rebindMailboxSendersToOwner($mailboxId, $newOwnerId);
		}

		$senderRejected = !$skipConnectionValidation
			&& !$this->updateMailboxSender($mailboxId, $senderFields, $isOAuth);

		// The mailbox row is already updated, so these side effects must run even when the sender was rejected.
		$accessState = $this->updateMailboxShareAccess($mailboxId, $mailboxData, $shareAccess);
		$crmFilterResult = $this->syncCrmImapFilter(
			$mailboxId,
			$mailboxData['OPTIONS']['flags'] ?? [],
			CrmImapFilterContext::SettingsSave,
		);
		$this->addResultErrors($crmFilterResult);

		if ($accessState !== null)
		{
			Notification::dispatchAccessChangedNotifications(
				$mailboxId,
				(string)$mailboxData['EMAIL'],
				$accessState['previousAccess'],
				$accessState['currentAccess'],
				(int)Main\Engine\CurrentUser::get()->getId(),
			);
		}

		$mailboxInstance = Mailbox::createInstance($mailboxId);
		if ($mailboxInstance)
		{
			$mailboxInstance->cacheDirs();
		}

		MailboxSearchIndexHelper::saveSearchIndexForMailbox($mailboxId);

		if (Feature::isMailboxGridAvailable())
		{
			Mail\Integration\Im\Notification::sendEditMailboxNotifications(
				['ID' => $mailboxId] + $mailboxData,
				$existingData['userId'],
				(int)$mailboxData['USER_ID'],
			);
		}

		if ($senderRejected || $this->hasErrors())
		{
			return [];
		}

		$this->setSuccess();

		if ($syncAfterConnection)
		{
			$this->syncMailbox($mailboxId);
		}

		$senderName = UserSenderDataProvider::getAddressInEmailAngleFormat(
			email: trim((string)$mailboxData['EMAIL']),
			senderName: (string)($senderFields['NAME'] ?? $mailboxData['USERNAME'] ?? ''),
		);

		return [
			'id' => $mailboxId,
			'email' => trim((string)$mailboxData['EMAIL']),
			'senderName' => $senderName,
		];
	}

	private function updateMailboxSender(
		int $mailboxId,
		?array $senderFields,
		bool $isOAuth,
	): bool
	{
		if (empty($senderFields))
		{
			return true;
		}

		$result = [];
		if (!empty($senderFields['ID']))
		{
			$updateResult = Main\Mail\Sender::updateSender(
				$senderFields['ID'],
				$senderFields,
				checkSenderAccess: false,
			);

			if ($updateResult->isSuccess())
			{
				$result['confirmed'] = true;
			}
			else
			{
				$result['errors'] = $updateResult->getErrorCollection();
			}
		}
		else
		{
			$result = self::appendSender($senderFields, '', $mailboxId);
		}

		if (!empty($result['errors']) && $result['errors'] instanceof Main\ErrorCollection)
		{
			$this->addErrors($result['errors'], $isOAuth, true);

			return false;
		}

		if (!empty($result['error']))
		{
			$this->addError($result['error'], self::SMTP_SENDER_ERROR_KEY);

			return false;
		}

		if (empty($result['confirmed']))
		{
			$this->addError('MAIL_CLIENT_CONFIG_SMTP_CONFIRM', self::SMTP_SENDER_ERROR_KEY);

			return false;
		}

		return true;
	}

	/**
	 * @return array{previousAccess: string[], currentAccess: string[]}|null
	 */
	private function updateMailboxShareAccess(int $mailboxId, array $mailboxData, array $shareAccess): ?array
	{
		if (!MailboxAccess::hasCurrentUserAccessToEditMailboxAccess($mailboxId, (int)$mailboxData['USER_ID']))
		{
			return null;
		}

		return $this->applyMailboxShareAccess($shareAccess, (int)$mailboxData['USER_ID'], $mailboxId);
	}

	private function syncCrmImapFilter(int $mailboxId, array $flags, CrmImapFilterContext $context): Main\Result
	{
		return CrmImapFilter::sync($mailboxId, in_array(CrmFlag::Connect->value, $flags, true), $context);
	}

	private function hasErrors(): bool
	{
		return !empty($this->errorCollection);
	}

	private static function deleteMailboxSender(int $mailboxId, string $email): void
	{
		$sender = self::getMailboxSender($mailboxId, $email);

		if ($sender)
		{
			Main\Mail\Sender::delete([$sender['ID']]);
			Main\Mail\Sender::clearCustomSmtpCache($email);
		}
	}

	/**
	 * A client that may not edit CRM integration still submits its current state: the form renders the
	 * switch read-only, and a read-only checkbox is still sent. Repeating the stored state is not an
	 * attempt to change it, so only a different state must be refused.
	 *
	 * Compared by the integration switch alone: the read-only block sends its inner fields disabled,
	 * so they never match what is stored, and their values are dropped by preserveMailboxCrmSettings
	 * anyway.
	 */
	private function isCrmStateUnchanged(?CrmOptions $requestedCrmOptions, array $existingData): bool
	{
		$existingFlags = (array)($existingData['options']['flags'] ?? []);
		$storedEnabled = in_array(CrmFlag::Connect->value, $existingFlags, true);

		return ($requestedCrmOptions?->enabled ?? false) === $storedEnabled;
	}

	private function preserveMailboxCrmSettings(array &$mailboxData, array $existingData): void
	{
		$existingOptions = $existingData['options'] ?? [];
		$existingFlags = (array)($existingOptions['flags'] ?? []);

		foreach (CrmFlag::cases() as $flag)
		{
			if (in_array($flag->value, $existingFlags, true))
			{
				$mailboxData['OPTIONS']['flags'][] = $flag->value;
			}
		}

		foreach (CrmOption::values() as $key)
		{
			if (array_key_exists($key, $existingOptions))
			{
				$mailboxData['OPTIONS'][$key] = $existingOptions[$key];
			}
		}

		if (in_array(CrmFlag::PublicBind->value, $existingFlags, true))
		{
			$mailboxData['PERIOD_CHECK'] = $existingData['periodCheck'];
		}
	}

	/**
	 * Connect mailbox from mass connect with notifications and result saving
	 *
	 * @param MailboxConnectDTO $mailboxConnectDTO Mailbox connection data
	 * @param int $massConnectId ID of MailMassConnectTable entity
	 * @param int $currentUserId ID of user performing the connection
	 * @return array
	 * @throws Main\ArgumentException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 */
	public function connectMailboxFromMassconnect(
		MailboxConnectDTO $mailboxConnectDTO,
		int $massConnectId,
		int $currentUserId,
	): array
	{
		$this->setMailboxConnectDTO($mailboxConnectDTO);
		$result = $this->connectMailboxWithCustomCrm(useClassDto: true);
		$errors = $this->getErrors();

		$toUserId = $mailboxConnectDTO->userIdToConnect;
		if (
			empty($errors)
			&& $toUserId !== null
			&& $toUserId !== $currentUserId
		)
		{
			Mail\Integration\Im\Notification::sendAddMailboxNotification(
				$result['id'] ?? '',
				$result['email'] ?? '',
				$toUserId,
				$currentUserId,
			);
		}

		$mailMassConnectHelper = new MailMassConnect();
		$mailMassConnectHelper->addResult($massConnectId, $mailboxConnectDTO, $result, $errors);

		return $result;
	}

	public static function deleteMailbox(int $id): Main\Result
	{
		$result = new Main\Result();

		$mailbox = Mail\MailboxTable::getList(array(
			'filter' => array(
				'=ID' => $id,
				'=ACTIVE' => MailboxStatus::Active->value,
				'=SERVER_TYPE' => 'imap',
			),
		))->fetch();

		if (empty($mailbox))
		{
			$result->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTOR_REMOVE_DELETE_ERROR_NO_MAILBOX')));

			return $result;
		}

		$canManage = MailboxAccess::hasCurrentUserAccessToEditMailbox($mailbox['ID']);

		if (!$canManage)
		{
			$result->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTOR_REMOVE_DELETE_ERROR_DENIED')));

			return $result;
		}

		\CMailbox::update($mailbox['ID'], array('ACTIVE' => MailboxStatus::Inactive->value));

		self::deleteMailboxSender((int)$mailbox['ID'], $mailbox['EMAIL']);

		\CUserCounter::clear((int)$mailbox['USER_ID'], 'mail_unseen', $mailbox['LID']);

		$mailboxSyncManager = new MailboxSyncManager($mailbox['USER_ID']);

		$mailboxSyncManager->deleteSyncData($mailbox['ID']);

		\CAgent::addAgent(sprintf('Bitrix\Mail\Helper::deleteMailboxAgent(%u);', $mailbox['ID']), 'mail', 'N', 60);

		return $result;
	}

	public function updateCalendarOptions(int $mailboxId, bool $enabled): Main\Result
	{
		$result = new Main\Result();

		if (!MailboxAccess::hasCurrentUserAccessToEditMailbox($mailboxId))
		{
			return $result->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTOR_REMOVE_DELETE_ERROR_DENIED')));
		}

		$mailbox = Mail\MailboxTable::query()
			->setSelect(['OPTIONS'])
			->where('ID', $mailboxId)
			->whereIn('ACTIVE', [MailboxStatus::Active->value, MailboxStatus::Pending->value])
			->where('SERVER_TYPE', 'imap')
			->fetch()
		;

		if (empty($mailbox))
		{
			return $result->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTOR_REMOVE_DELETE_ERROR_NO_MAILBOX')));
		}

		$options = $mailbox['OPTIONS'] ?? [];
		$currentIcalAccess = (($options['ical_access'] ?? 'N') === 'Y') ? 'Y' : 'N';
		$targetIcalAccess = $enabled ? 'Y' : 'N';
		if ($currentIcalAccess === $targetIcalAccess)
		{
			return $result->setData([
				'skipped' => true,
				'reason' => $enabled ? 'Calendar integration already enabled' : 'Calendar integration already disabled',
			]);
		}

		if ($enabled && !Loader::includeModule('calendar'))
		{
			return $result->addError(new Error('Calendar module is not available'));
		}

		$options['ical_access'] = $targetIcalAccess;

		$updateResult = \CMailbox::update($mailboxId, ['OPTIONS' => $options]);
		if (!$updateResult)
		{
			return $result->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_FORM_ERROR')));
		}

		return $result;
	}

	public function updateCrmOptions(int $mailboxId, CrmOptions $crmOptions): Main\Result
	{
		$result = new Main\Result();

		if (!MailboxAccess::hasCurrentUserAccessToEditMailbox($mailboxId))
		{
			return $result->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTOR_REMOVE_DELETE_ERROR_DENIED')));
		}

		$mailbox = Mail\MailboxTable::query()
			->setSelect(['OPTIONS', 'PERIOD_CHECK', 'USER_ID'])
			->where('ID', $mailboxId)
			->whereIn('ACTIVE', [MailboxStatus::Active->value, MailboxStatus::Pending->value])
			->where('SERVER_TYPE', 'imap')
			->fetch()
		;

		if (empty($mailbox))
		{
			return $result->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTOR_REMOVE_DELETE_ERROR_NO_MAILBOX')));
		}

		$options = $mailbox['OPTIONS'] ?? [];

		$allCrmFlagValues = CrmFlag::values();
		$existingFlags = (array)($options['flags'] ?? []);
		$wasPublic = in_array(CrmFlag::PublicBind->value, $existingFlags, true);
		$nonCrmFlags = array_values(array_filter(
			$existingFlags,
			static fn(string $flag): bool => !in_array($flag, $allCrmFlagValues, true),
		));

		if (!$this->isCrmIntegrationAvailableForCurrentUser())
		{
			return $result->addError(new Error(
				(string)Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CRM_ACCESS_DENIED'),
				self::CRM_ACCESS_DENIED_ERROR_KEY,
			));
		}

		if ($crmOptions->enabled)
		{
			$crmFlags = [CrmFlag::Connect->value];

			if ($crmOptions->public)
			{
				$crmFlags[] = CrmFlag::PublicBind->value;
			}

			if (empty($crmOptions->newEntityIn))
			{
				$crmFlags[] = CrmFlag::DenyEntityIn->value;
			}

			if (empty($crmOptions->newEntityOut))
			{
				$crmFlags[] = CrmFlag::DenyEntityOut->value;
			}

			if (!$crmOptions->vcf)
			{
				$crmFlags[] = CrmFlag::DenyNewContact->value;
			}

			$options['flags'] = array_values(array_merge($nonCrmFlags, $crmFlags));

			$options[CrmOption::NewEntityIn->value] = $crmOptions->newEntityIn?->value;
			$options[CrmOption::NewEntityOut->value] = $crmOptions->newEntityOut?->value;
			$options[CrmOption::LeadSource->value] = $crmOptions->leadSource;
			$options[CrmOption::LeadResp->value] = !empty($crmOptions->leadResp)
				? $crmOptions->leadResp
				: [(int)$mailbox['USER_ID']];
			$options[CrmOption::Public->value] = $crmOptions->public;
			$options[CrmOption::Vcf->value] = $crmOptions->vcf;
			$options[CrmOption::SyncDays->value] = $crmOptions->syncDays;

			if (!empty($crmOptions->newLeadFor))
			{
				$validEmails = [];
				foreach ($crmOptions->newLeadFor as $item)
				{
					$address = new Address($item, ['checkingPunycode' => true]);
					if ($address->validate())
					{
						$validEmails[] = $address->getEmail();
					}
				}
				$options[CrmOption::NewLeadFor->value] = array_values(array_unique($validEmails));
			}
			else
			{
				$options[CrmOption::NewLeadFor->value] = [];
			}

			$syncDays = $crmOptions->syncDays ?? self::CRM_MAX_AGE;
			if ($syncDays < 0)
			{
				unset($options[CrmOption::SyncFrom->value]);
			}
			else
			{
				$options[CrmOption::SyncFrom->value] = strtotime(sprintf('-%u days', $syncDays));
			}
		}
		else
		{
			if (empty(array_intersect($existingFlags, $allCrmFlagValues)))
			{
				return $result->setData([
					'skipped' => true,
					'reason' => 'CRM already disabled',
				]);
			}

			$options['flags'] = $nonCrmFlags;
		}

		$periodCheck = (int)$mailbox['PERIOD_CHECK'];
		if ($crmOptions->enabled && $crmOptions->public)
		{
			$periodCheck = ((int)Option::get('mail', 'public_mailbox_sync_interval', 0)) ?: 10;
		}
		elseif ($wasPublic)
		{
			$periodCheck = self::DEFAULT_PERIOD_CHECK;
		}

		$updateFields = ['OPTIONS' => $options, 'PERIOD_CHECK' => $periodCheck];

		$updateResult = \CMailbox::update($mailboxId, $updateFields);
		if (!$updateResult)
		{
			return $result->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_FORM_ERROR')));
		}

		$crmFilterResult = $this->syncCrmImapFilter(
			$mailboxId,
			$options['flags'] ?? [],
			CrmImapFilterContext::SettingsSave,
		);

		return $result->addErrors($crmFilterResult->getErrors());
	}

	public function enableCrmWithDefaults(int $mailboxId): Main\Result
	{
		$result = new Main\Result();

		if (!MailboxAccess::hasCurrentUserAccessToEditMailbox($mailboxId))
		{
			return $result->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTOR_REMOVE_DELETE_ERROR_DENIED')));
		}

		$mailbox = Mail\MailboxTable::query()
			->setSelect(['OPTIONS', 'USER_ID'])
			->where('ID', $mailboxId)
			->whereIn('ACTIVE', [MailboxStatus::Active->value, MailboxStatus::Pending->value])
			->where('SERVER_TYPE', 'imap')
			->fetch()
		;

		if (empty($mailbox))
		{
			return $result->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTOR_REMOVE_DELETE_ERROR_NO_MAILBOX')));
		}

		if (!$this->isCrmIntegrationAvailableForCurrentUser())
		{
			return $result->addError(new Error(
				(string)Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CRM_ACCESS_DENIED'),
				self::CRM_ACCESS_DENIED_ERROR_KEY,
			));
		}

		$options = (array)($mailbox['OPTIONS'] ?? []);
		$existingFlags = (array)($options['flags'] ?? []);
		$isFlagSet = in_array(CrmFlag::Connect->value, $existingFlags, true);

		if ($isFlagSet && CrmImapFilter::isConnected($mailboxId))
		{
			return $result->setData(['skipped' => true, 'reason' => 'CRM already enabled']);
		}

		// Defaults belong to a mailbox that had no CRM at all; a mailbox with the flag only needs its gate back.
		if (!$isFlagSet)
		{
			$allCrmFlagValues = CrmFlag::values();
			$nonCrmFlags = array_values(array_filter(
				$existingFlags,
				static fn(string $flag): bool => !in_array($flag, $allCrmFlagValues, true),
			));
			$options['flags'] = array_values(array_merge($nonCrmFlags, [CrmFlag::Connect->value]));

			foreach ($this->defaultCrmOptionValues((int)$mailbox['USER_ID']) as $k => $v)
			{
				$options[$k] = $v;
			}

			if (!\CMailbox::update($mailboxId, ['OPTIONS' => $options]))
			{
				return $result->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_FORM_ERROR')));
			}
		}

		$crmFilterResult = $this->syncCrmImapFilter(
			$mailboxId,
			$options['flags'] ?? [],
			CrmImapFilterContext::SettingsSave,
		);

		return $result->addErrors($crmFilterResult->getErrors());
	}

	/**
	 * Pre-validate that applying $access codes to mailbox won't exceed the shared-mailbox license limit.
	 * Returns false and adds an error when the limit would be exceeded; true otherwise.
	 *
	 * @param string[] $access
	 */
	private function assertSharedMailboxLimit(array $access, int $userId, ?int $mailboxId): bool
	{
		$ownerAccessCode = 'U' . $userId;
		if (!in_array($ownerAccessCode, $access, true))
		{
			$access[] = $ownerAccessCode;
		}
		$uniqueAccess = array_values(array_unique($access));

		$sharedMailboxesLimit = LicenseManager::getSharedMailboxesLimit();
		if ($sharedMailboxesLimit < 0 || count($uniqueAccess) <= 1)
		{
			return true;
		}

		$alreadySharedMailboxesIds = Mail\Helper\Mailbox\SharedMailboxesManager::getSharedMailboxesIds();
		if (
			count($alreadySharedMailboxesIds) >= $sharedMailboxesLimit
			&& ($mailboxId === null || !in_array($mailboxId, $alreadySharedMailboxesIds, true))
		)
		{
			$this->addError(
				(string)Loc::getMessage('MAIL_MAILBOX_CONNECTOR_SHARED_LIMIT_EXCEEDED'),
				self::SHARED_LIMIT_EXCEEDED_ERROR_KEY,
			);

			return false;
		}

		return true;
	}

	/**
	 * @param string[] $access Access codes list (e.g., 'U1', 'DR5', 'D10')
	 * @return array{previousAccess: string[], currentAccess: string[]}
	 */
	private function applyMailboxShareAccess(
		array $access,
		int $userId,
		int $mailboxId,
	): ?array
	{
		$previousAccessCodes = Mail\Internals\MailboxAccessTable::getList([
			'select' => ['ACCESS_CODE'],
			'filter' => ['=MAILBOX_ID' => $mailboxId, 'TASK_ID' => 0],
		])->fetchAll();
		$previousAccessCodes = array_column($previousAccessCodes, 'ACCESS_CODE');

		$ownerAccessCode = 'U' . $userId;

		if (!in_array($ownerAccessCode, $access, true))
		{
			$access[] = $ownerAccessCode;
		}

		$uniqueAccess = array_values(array_unique($access));
		$sharedMailboxesLimit = LicenseManager::getSharedMailboxesLimit();
		if ($sharedMailboxesLimit >= 0 && count($uniqueAccess) > 1)
		{
			$alreadySharedMailboxesIds = Mail\Helper\Mailbox\SharedMailboxesManager::getSharedMailboxesIds();
			if (
				count($alreadySharedMailboxesIds) >= $sharedMailboxesLimit
				&& !in_array($mailboxId, $alreadySharedMailboxesIds, true)
			)
			{
				$this->addError(
					(string)Loc::getMessage('MAIL_MAILBOX_CONNECTOR_SHARED_LIMIT_EXCEEDED'),
					self::SHARED_LIMIT_EXCEEDED_ERROR_KEY,
				);

				return null;
			}
		}

		Mail\Internals\MailboxAccessTable::deleteByFilter(['=MAILBOX_ID' => $mailboxId]);

		$rowsToAdd = [];
		foreach ($uniqueAccess as $item)
		{
			$rowsToAdd[] = [
				'MAILBOX_ID' => $mailboxId,
				'TASK_ID' => 0,
				'ACCESS_CODE' => $item,
			];
		}

		Mail\Internals\MailboxAccessTable::addMulti($rowsToAdd, true);

		return [
			'previousAccess' => $previousAccessCodes,
			'currentAccess' => $uniqueAccess,
		];
	}
}
