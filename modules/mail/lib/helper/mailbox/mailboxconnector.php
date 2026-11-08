<?php

namespace Bitrix\Mail\Helper\Mailbox;

use Bitrix\Mail;
use Bitrix\Mail\Helper\Enum\MailboxStatus;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\Dto\MailboxConnect\MailboxConnectDTO;
use Bitrix\Mail\Helper\Dto\MailboxConnect\GeneralSettingsPatch;
use Bitrix\Mail\Helper\LicenseManager;
use Bitrix\Mail\Helper\Enum\CrmEntityType;
use Bitrix\Mail\Helper\Enum\CrmFlag;
use Bitrix\Mail\Helper\Enum\CrmImapFilterContext;
use Bitrix\Mail\Helper\Enum\CrmOption;
use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Helper\Dto\MailboxConnect\CrmOptions;
use Bitrix\Mail\Helper\MailboxSearchIndexHelper;
use Bitrix\Mail\Integration\Im\Notification;
use Bitrix\Mail\Internal\Service\Mailbox\MailboxEmailOccupancy;
use Bitrix\Mail\Internal\Service\Mailbox\SettingsWriteCoordinator;
use Bitrix\Mail\Internal\Service\Mailbox\GeneralSettingsService;
use Bitrix\Mail\Internal\Service\Mailbox\CrmImapGateReconciler;
use Bitrix\Mail\Internal\Service\Mailbox\Avatar\AvatarChange;
use Bitrix\Mail\Internal\Service\Mailbox\Avatar\AvatarService;
use Bitrix\Mail\Integration\MailService\MigrationStatusProvider;
use Bitrix\Mail\Internals\MailboxSourceGenerationTable;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationActionGuard;
use Bitrix\Mail\Internal\Service\SourceGeneration\FolderPairRules;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationMetrics;
use Bitrix\Mail\Internal\Service\SourceGeneration\TargetFolderCreator;
use Bitrix\Mail\Internal\Service\Mailbox\MailboxEmailOccupancyService;
use Bitrix\Mail\Internal\Service\SenderCacheCleaner;
use Bitrix\Mail\MailServicesTable;
use Bitrix\Main;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Mail\Address;
use Bitrix\Main\Mail\Sender\UserSenderDataProvider;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\SystemException;
use Psr\Log\LoggerInterface;

final class MailboxConnector
{
	/**
	 * Core option, 'N' once the SMTP records of mailboxes are bound to them, see LegacyMailboxSenderBindingStepper.
	 * Named by a literal so that the module keeps working with a core that does not know it.
	 */
	public const LEGACY_SENDER_LOOKUP_OPTION = 'sender_identity_address_fallback';
	public const NO_RIGHTS_TO_CONNECT_ERROR_KEY = 'NO_RIGHTS_TO_CONNECT';
	public const STANDARD_ERROR_KEY = 'STANDARD_ERROR';
	public const LIMIT_ERROR_KEY = 'LIMIT_ERROR';
	public const OAUTH_ERROR_KEY = 'OAUTH_ERROR';
	public const EXISTS_ERROR_KEY = 'EXISTS_ERROR';
	// The address is already used by an active mailbox of another owner
	public const EXISTS_ON_PORTAL_ERROR_KEY = 'EXISTS_ON_PORTAL_ERROR';
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
	// The save asks to change credentials the service of the mailbox gets from outside the portal
	public const EXTERNAL_CREDENTIALS_ERROR_KEY = 'EXTERNAL_CREDENTIALS';
	// The connection asks for a service whose credentials are issued outside the portal
	public const EXTERNAL_CREDENTIALS_SERVICE_ERROR_KEY = 'EXTERNAL_CREDENTIALS_SERVICE';

	public const DEFAULT_IMAP_PORT = '993';
	public const DEFAULT_SMTP_PORT = '587';
	public const DEFAULT_SERVICE_CONFIG = ['serviceType' => 'imap', 'name' => 'other'];
	public const CRM_MAX_AGE = 7;
	public const MESSAGE_MAX_AGE = 7;

	private const DEFAULT_PERIOD_CHECK = 60 * 24;
	private const LOGGER_ID = 'mail.mailbox.connector';
	private const MICROSOFT_OAUTH_SERVICE_NAMES = ['office365', 'exchangeOnline', 'outlook.com'];

	public const RESPONSE_ERROR_CODE_IMAP_CONNECTION = 'imap_connection';
	public const RESPONSE_ERROR_CODE_WRONG_AUTH = 'auth';
	public const RESPONSE_ERROR_CODE_SMTP_CONNECTION = 'smtp_connection';

	private bool $isSuccess = false;

	private array $errorCollection = [];

	private bool $isSMTPAvailable = false;

	private ?MailboxConnectDTO $mailboxConnectDTO = null;

	private ?LoggerInterface $logger = null;

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
			: null;

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

	private function addErrorWithMessage(string $code = self::STANDARD_ERROR_KEY, array $customData = []): void
	{
		$messages = [
			self::LIMIT_ERROR_KEY => Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_LIMIT_ERROR'),
			self::OAUTH_ERROR_KEY => Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_OAUTH_ERROR'),
			self::EXISTS_ERROR_KEY => Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_EMAIL_EXISTS_ERROR'),
			self::EXISTS_ON_PORTAL_ERROR_KEY => Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_EMAIL_EXISTS_ON_PORTAL_ERROR'),
			self::NO_MAIL_SERVICES_ERROR_KEY => Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_THERE_ARE_NO_MAIL_SERVICES'),
			self::EXTERNAL_CREDENTIALS_SERVICE_ERROR_KEY => Loc::getMessage('MAIL_MAILBOX_CONNECTOR_EXTERNAL_CREDENTIALS_SERVICE'),
			self::SMTP_PASS_BAD_SYMBOLS_ERROR_KEY => Loc::getMessage('MAIL_MAILBOX_CONNECTOR_SMTP_PASS_BAD_SYMBOLS'),
			self::STANDARD_ERROR_KEY => Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_FORM_ERROR'),
			self::NO_RIGHTS_TO_CONNECT_ERROR_KEY => Loc::getMessage('MAIL_MAILBOX_CONNECTOR_ACCESS_DENIED_ERROR'),
		];

		$message = $messages[$code] ?? $messages[self::STANDARD_ERROR_KEY];

		$this->addError($message, $code, $customData);
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
		return self::appendSenderWithCallback(
			$senderFields,
			$userPrincipalName,
			$mailboxId,
			static fn(array $fields): array => Main\Mail\Sender::add($fields),
		);
	}

	private static function appendSenderWithCallback(
		array $senderFields,
		string $userPrincipalName,
		int $mailboxId,
		callable $appendSender,
	): array
	{
		if ($mailboxId)
		{
			$senderFields['PARENT_ID'] = $mailboxId;
			$senderFields['PARENT_MODULE_ID'] = 'mail';
		}

		$result = $appendSender($senderFields);

		$isSmtpOauth = ($senderFields['OPTIONS']['smtp']['isOauth'] ?? false) === true;
		if (empty($result['confirmed']) && $userPrincipalName && $isSmtpOauth)
		{
			$address = new Address($userPrincipalName);
			$canonicalUserPrincipalName = $address->validate() ? $address->getEmail() : null;
			$currentSmtpLogin = $senderFields['OPTIONS']['smtp']['login'] ?? '';
			if (
				$currentSmtpLogin
				&& $canonicalUserPrincipalName === mb_strtolower($userPrincipalName)
				&& mb_strtolower($currentSmtpLogin) !== $canonicalUserPrincipalName
			)
			{
				// outlook workaround, sometimes SMTP auth only works with userPrincipalName
				$senderFields['OPTIONS']['smtp']['login'] = $canonicalUserPrincipalName;
				$result = $appendSender($senderFields);
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
			$this->resolveSmtpFallbackUserPrincipalName($mailboxConnectDTO, $isOAuth),
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
			$code = $e->getMessage();
			$customData = $code === self::EXISTS_ON_PORTAL_ERROR_KEY
				? ['email' => (new MailboxEmailOccupancyService())->normalizeEmail($mailboxConnectDTO->email ?? '')]
				: [];

			$this->addErrorWithMessage($code, $customData);

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

		/*
			A service whose credentials are issued outside the portal is not connected to by hand. A
			mailbox lands on it by moving to that hosting, and the kit of the move brings the addresses
			and the passwords with it; there is nothing for a person to type here, and what they typed
			would be locked for editing the moment the mailbox is saved. Leaving the tile of such a
			service out of the showcase only keeps the form from offering it - this is the ban itself,
			and it holds for every way in: the connect form, a mass connect and a passwordless request
			all come through here.
		*/
		if (Mail\MailServicesTable::hasExternalCredentials($service))
		{
			throw new \Exception(self::EXTERNAL_CREDENTIALS_SERVICE_ERROR_KEY);
		}

		$address = new Address($email);
		if (!$address->validate())
		{
			throw new \Exception(self::OAUTH_ERROR_KEY);
		}

		$occupancy = (new MailboxEmailOccupancyService())->checkOccupancy($email, $userId);
		if ($occupancy === MailboxEmailOccupancy::OccupiedByRequester)
		{
			throw new \Exception(self::EXISTS_ERROR_KEY);
		}

		if ($occupancy === MailboxEmailOccupancy::OccupiedByOther)
		{
			throw new \Exception(self::EXISTS_ON_PORTAL_ERROR_KEY);
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
			$service
				= Mail\MailServicesTable::query()
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
			'EMAIL'       => $this->normalizeMailboxEmail($mailboxConnectDTO->email),
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

	/**
	 * The stored address must be the value the occupancy check compares against, otherwise an address
	 * given in display-name form stays invisible to that check. An address that cannot be normalized
	 * is left as is: Address::validate() has already rejected it in the prerequisites.
	 */
	private function normalizeMailboxEmail(?string $email): ?string
	{
		$normalized = (new MailboxEmailOccupancyService())->normalizeEmail((string)$email);

		return $normalized === '' ? $email : $normalized;
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
			&& Feature::isCrmAvailable();
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

	private function prepareSmtpSender(
		array $mailboxData,
		MailboxConnectDTO $mailboxConnectDTO,
	): ?array
	{
		$isSmtpOauthEnabled
			= !empty(MailServicesTable::getOAuthHelper($mailboxConnectDTO->service))
			&& self::isOauthSmtpEnabled($mailboxConnectDTO->service['NAME']);

		$service = $mailboxConnectDTO->service;
		$isMicrosoftOauthService = in_array(
			$service['NAME'] ?? '',
			self::MICROSOFT_OAUTH_SERVICE_NAMES,
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

	private function resolveSmtpFallbackUserPrincipalName(
		MailboxConnectDTO $mailboxConnectDTO,
		bool $isOAuth,
	): string
	{
		if (
			!$isOAuth
			|| !in_array(
				$mailboxConnectDTO->service['NAME'] ?? '',
				self::MICROSOFT_OAUTH_SERVICE_NAMES,
				true,
			)
		)
		{
			return '';
		}

		return $mailboxConnectDTO->userPrincipalName ?? '';
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
		string $userPrincipalName = '',
	): array
	{
		$connection = Main\Application::getConnection();
		$transactionStarted = false;
		try
		{
			$connection->startTransaction();
			$transactionStarted = true;
			$mailboxId = \CMailbox::add($mailboxData);
			if (!($mailboxId > 0))
			{
				$connection->rollbackTransaction();
				$transactionStarted = false;
				addEventToStatFile('mail', 'add_mailbox', $mailboxData['SERVICE_NAME'], 'failed');

				$this->addErrorWithMessage();

				return [];
			}

			$locked = (new SettingsWriteCoordinator())->lockWithinTransaction((int)$mailboxId);
			if (!$locked->isSuccess() || !$this->syncNewMailboxCrmImapFilter(
				(int)$mailboxId,
				$mailboxData['OPTIONS']['flags'] ?? [],
			))
			{
				$connection->rollbackTransaction();
				$transactionStarted = false;
				$this->addResultErrors($locked);
				addEventToStatFile('mail', 'add_mailbox', $mailboxData['SERVICE_NAME'], 'failed');

				return [];
			}
			$connection->commitTransaction();
			$transactionStarted = false;
		}
		catch (\Throwable $exception)
		{
			if ($transactionStarted)
			{
				$connection->rollbackTransaction();
			}

			throw $exception;
		}

		if (!$this->assertNewMailboxWinsEmailRace((int)$mailboxId, (string)$mailboxData['EMAIL']))
		{
			addEventToStatFile('mail', 'add_mailbox', $mailboxData['SERVICE_NAME'], 'failed');

			return [];
		}

		addEventToStatFile('mail', 'add_mailbox', $mailboxData['SERVICE_NAME'], 'success');

		// From here on the record stays and holds the address, so the passwordless request its owner
		// may still have for it is a dead end and is withdrawn.
		$this->withdrawPasswordlessRequestsForConnectedAddress(
			(int)$mailboxData['USER_ID'],
			(string)$mailboxData['EMAIL'],
		);

		if (!empty($senderFields))
		{
			$this->createMailboxSender(
				(int)$mailboxId,
				$senderFields,
				$isOAuth,
				$userPrincipalName,
			);
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

	/**
	 * An administrator can ask an employee to connect a mailbox without knowing its password, and the
	 * request waits until the employee enters it. Connecting the same address the ordinary way leaves
	 * that request unfinishable, so it is withdrawn here, by the same path a person withdraws it.
	 *
	 * A failure here does not undo the connection: the mailbox works, only an obsolete request outlives
	 * it, and the log carries everything needed to withdraw it by hand.
	 */
	private function withdrawPasswordlessRequestsForConnectedAddress(int $userId, string $email): void
	{
		try
		{
			$cancelResult = (new PasswordlessConnectHelper())->cancelPendingRequestsForAddress($userId, $email);
			if ($cancelResult->isSuccess())
			{
				return;
			}

			$reason = implode('; ', $cancelResult->getErrorMessages());
		}
		catch (\Throwable $exception)
		{
			$reason = $exception->getMessage();
		}

		$this->getLogger()->error('Failed to withdraw the passwordless request for a connected address.', [
			'userId' => $userId,
			'email' => $email,
			'reason' => $reason,
		]);
	}

	/**
	 * Resolves a race between simultaneous connections of the same address: the oldest record wins,
	 * the losing one is rolled back before any side effect of the connection takes place.
	 */
	private function assertNewMailboxWinsEmailRace(int $mailboxId, string $email): bool
	{
		$occupancyService = new MailboxEmailOccupancyService();

		$rivalMailboxId = $occupancyService->findEarliestActiveMailboxId($email, $mailboxId);
		if ($rivalMailboxId === null || $mailboxId < $rivalMailboxId)
		{
			return true;
		}

		$this->rollBackMailboxThatLostEmailRace($mailboxId, $rivalMailboxId);
		$this->addErrorWithMessage(
			self::EXISTS_ON_PORTAL_ERROR_KEY,
			['email' => $occupancyService->normalizeEmail($email)],
		);

		return false;
	}

	/**
	 * The losing mailbox must leave service immediately, while its destructive cleanup may be retried.
	 */
	private function rollBackMailboxThatLostEmailRace(int $mailboxId, int $rivalMailboxId): void
	{
		$deletionRequested = (new Mail\Internal\Service\Mailbox\MailboxDeletionRequestService())->request($mailboxId);

		if (!$deletionRequested)
		{
			$this->getLogger()->error('Failed to request deletion of the mailbox that lost the address race.', [
				'mailboxId' => $mailboxId,
				'rivalMailboxId' => $rivalMailboxId,
			]);
		}
	}

	/**
	 * The registry check is skipped on purpose: what is logged here is an incident, not diagnostics
	 * someone has to switch on beforehand. Without a logger of its own in .settings.php the record
	 * goes to the common portal log, and to nowhere at all when the portal defines no log file.
	 */
	private function getLogger(): LoggerInterface
	{
		$this->logger ??= (new LoggerFactory())->createById(
			self::LOGGER_ID,
			[],
			false,
		);

		return $this->logger;
	}

	private function syncNewMailboxCrmImapFilter(int $mailboxId, array $flags): bool
	{
		$crmEnabled = in_array(CrmFlag::Connect->value, $flags, true);
		$crmFilterResult = $this->syncCrmImapFilter(
			$mailboxId,
			$flags,
			CrmImapFilterContext::MailboxConnect,
		);
		if (!$crmFilterResult->isSuccess())
		{
			$this->addResultErrors($crmFilterResult);

			return false;
		}

		$markerResult = (new CrmImapGateReconciler())->writeSatisfiedMarkerWithinTransaction(
			$mailboxId,
			$crmEnabled,
		);
		if (!$markerResult->isSuccess())
		{
			$this->addResultErrors($markerResult);

			return false;
		}

		return true;
	}

	private function createMailboxSender(
		int $mailboxId,
		array $senderFields,
		bool $isOAuth,
		string $userPrincipalName = '',
	): void
	{
		$result = self::appendSender($senderFields, $userPrincipalName, $mailboxId);

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
		// The service of a mailbox is not re-chosen by a save, the same way its address and its login are
		// not. The stored number already wins over a name the request sends, but a mailbox left without
		// a number - one whose service row was deleted, or one that moved here before the row existed -
		// would otherwise be re-hung onto whatever service the request names, its own hosting included.
		$dto->serviceConfig = [
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

	private function hasMigrationConnectionSettingsChanged(MailboxConnectDTO $dto, array $existingData): bool
	{
		$imap = $existingData['imap'];
		$smtp = $existingData['smtp'];
		$stringChanged = static fn (?string $value, mixed $stored): bool => $value !== null
			&& trim($value) !== trim((string)$stored);
		$hostChanged = static fn (?string $value, mixed $stored): bool => $value !== null
			&& mb_strtolower(trim($value)) !== mb_strtolower(trim((string)$stored));
		$flagChanged = static fn (?bool $value, mixed $stored): bool => $value !== null
			&& $value !== ((string)$stored === 'Y');

		return ($dto->email !== null
				&& mb_strtolower(trim($dto->email)) !== mb_strtolower(trim((string)$imap['email'])))
			|| $stringChanged($dto->login, $imap['login'])
			|| $dto->password !== null
			|| ($dto->serviceId !== null && $dto->serviceId !== (int)$imap['serviceId'])
			|| ($dto->serviceConfig !== null && (int)$imap['serviceId'] === 0
				&& array_diff_assoc($dto->serviceConfig, [
					'serviceType' => $existingData['service']['type'] ?? 'imap',
					'name' => $existingData['service']['name'] ?? 'other',
				]) !== [])
			|| $hostChanged($dto->server, $imap['server'])
			|| $stringChanged($dto->port, $imap['port'])
			|| $flagChanged($dto->ssl, $imap['ssl'] ?? 'N')
			|| $stringChanged($dto->storageOauthUid, $imap['oauthUid'] ?? '')
			|| $flagChanged($dto->useSmtp, $smtp['enabled'] ?? 'N')
			|| $hostChanged($dto->serverSmtp, $smtp['server'] ?? '')
			|| $stringChanged($dto->portSmtp, $smtp['port'] ?? '')
			|| $flagChanged($dto->sslSmtp, $smtp['ssl'] ?? 'N')
			|| $stringChanged($dto->loginSmtp, $smtp['login'] ?? '')
			|| $stringChanged($dto->userPrincipalName, $smtp['login'] ?? '')
			|| $dto->passwordSMTP !== null;
	}

	/**
	 * Whether the save asks to change the connection credentials of the mailbox.
	 *
	 * Asked for a mailbox whose service gets its credentials from outside the portal: they are
	 * issued together with the mailbox, the user does not own them, and a value changed here
	 * only stops the mail from being delivered. Everything else about such a mailbox - its name,
	 * the sender name, the CRM settings, the sending limits - stays editable.
	 *
	 * The comparison goes against the stored values, not against "the field came at all": the
	 * form submits the whole set on every save, so a rename of the mailbox carries the untouched
	 * server address along with it and must not be refused because of it.
	 *
	 * The SMTP half is only compared while the save leaves the outgoing mail on. With it off no
	 * sender survives the save, the submitted SMTP fields reach nothing, and the form fills the
	 * SMTP login from the address of the mailbox whether or not anybody asked for one.
	 */
	private function hasExternalCredentialsChanged(MailboxConnectDTO $dto, array $existingData): bool
	{
		$imap = $existingData['imap'];
		$smtp = $existingData['smtp'];
		$stringChanged = static fn (?string $value, mixed $stored): bool => $value !== null
			&& trim($value) !== trim((string)$stored);
		$hostChanged = static fn (?string $value, mixed $stored): bool => $value !== null
			&& mb_strtolower(trim($value)) !== mb_strtolower(trim((string)$stored));
		$flagChanged = static fn (?bool $value, mixed $stored): bool => $value !== null
			&& $value !== ((string)$stored === 'Y');
		// The stored encryption of the incoming mail has a third state that no flag of a request can
		// mean, so asking for either flag over it is asking for something else than what is stored.
		$encryptionChanged = static fn (?bool $value, mixed $stored): bool => $value !== null
			&& ($value ? 'Y' : 'N') !== mb_strtoupper(trim((string)$stored));

		if (
			$dto->password !== null
			|| $hostChanged($dto->server, $imap['server'])
			|| $stringChanged($dto->port, $imap['port'])
			|| $encryptionChanged($dto->ssl, $imap['ssl'] ?? 'N')
		)
		{
			return true;
		}

		if (!($dto->useSmtp ?? (($smtp['enabled'] ?? 'N') === 'Y')))
		{
			return false;
		}

		return $dto->passwordSMTP !== null
			|| $hostChanged($dto->serverSmtp, $smtp['server'] ?? '')
			|| $stringChanged($dto->portSmtp, $smtp['port'] ?? '')
			|| $flagChanged($dto->sslSmtp, $smtp['ssl'] ?? 'N')
			|| $stringChanged($dto->loginSmtp, $smtp['login'] ?? '');
	}

	/**
	 * The outgoing credentials the mailbox was handed together with its address, ready to be put back.
	 *
	 * Switching the outgoing mail off deletes the sender of the mailbox, credentials and all. For a
	 * mailbox that does not own its credentials that would be a one-way door: the fields to type them
	 * back in are not drawn, nothing is left to repeat, and the ban would read an empty set as a
	 * change. They are read back from the snapshot of the generation the mailbox serves instead - the
	 * stored copy of the very kit that issued them - so switching the outgoing mail on again restores
	 * what the hosting gave and changes nothing.
	 *
	 * Answered only while the save switches the outgoing mail on and the mailbox has no sender left.
	 * A generation carrying no SMTP of its own - a mailbox that never moved anywhere - has nothing to
	 * restore, and the ban keeps standing as it did.
	 *
	 * @return array{server: string, port: string, ssl: string, login: string, password: string}|null
	 */
	private function readIssuedSmtpCredentials(int $mailboxId, MailboxConnectDTO $dto, array $existingData): ?array
	{
		if ($dto->useSmtp !== true || ($existingData['smtp']['enabled'] ?? 'N') === 'Y')
		{
			return null;
		}

		$generation = MailboxSourceGenerationTable::getList([
			'select' => ['SMTP_SERVER', 'SMTP_PORT', 'SMTP_PROTOCOL', 'SMTP_LOGIN', 'SMTP_PASSWORD'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'=STATUS' => MailboxSourceGenerationTable::STATUS_ACTIVE,
			],
			// A mailbox serves one generation at a time, and the order spells out which one is meant
			// anyway: the credentials to restore are the ones of the latest hand-over.
			'order' => ['ID' => 'DESC'],
			'limit' => 1,
		])->fetch();

		if (!$generation || trim((string)$generation['SMTP_SERVER']) === '' || (int)$generation['SMTP_PORT'] <= 0)
		{
			return null;
		}

		return [
			'server' => trim((string)$generation['SMTP_SERVER']),
			'port' => (string)(int)$generation['SMTP_PORT'],
			'ssl' => (string)$generation['SMTP_PROTOCOL'] === 'smtps' ? 'Y' : 'N',
			'login' => (string)$generation['SMTP_LOGIN'],
			'password' => (string)$generation['SMTP_PASSWORD'],
		];
	}

	/**
	 * Checks that a set of IMAP credentials really answers, without creating or changing
	 * a mailbox. The connection errors are mapped exactly as the connect form maps them,
	 * so a caller preparing a new physical source of an existing mailbox reports the same
	 * reasons the user already knows.
	 *
	 * @param array $connection SERVER, PORT, USE_TLS, LOGIN, PASSWORD.
	 */
	public function validateConnectionSnapshot(array $connection): Main\Result
	{
		$result = new Main\Result();

		$this->clearErrors();

		if (!$this->validateImapConnection($connection, false))
		{
			foreach ($this->getErrors() as $error)
			{
				$result->addError($error);
			}

			if ($result->isSuccess())
			{
				$result->addError(new Error('IMAP connection failed', self::STANDARD_ERROR_KEY));
			}
		}

		return $result;
	}

	/**
	 * Checks the connection and lists its complete folder tree over the same IMAP session.
	 *
	 * @return Main\Result data on success: ['directories' => array]
	 */
	public function inspectMigrationConnectionSnapshot(
		array $connection,
		?Mail\Imap $client = null,
	): Main\Result
	{
		$result = new Main\Result();
		$this->clearErrors();

		$client ??= new Mail\Imap(
			$connection['SERVER'],
			$connection['PORT'],
			$connection['USE_TLS'] === 'Y' || $connection['USE_TLS'] === 'S',
			$connection['USE_TLS'] === 'Y',
			$connection['LOGIN'],
			$connection['PASSWORD'],
		);

		if ($client->getUnseen('inbox', $error) === false)
		{
			$this->addErrors($client->getErrors());
			foreach ($this->getErrors() as $connectionError)
			{
				$result->addError($connectionError);
			}

			return $result;
		}

		$directories = $client->listMailboxes('*', $error, true);
		if ($directories === false)
		{
			$this->addErrors($client->getErrors());
			foreach ($this->getErrors() as $connectionError)
			{
				$result->addError($connectionError);
			}

			return $result;
		}

		return $result->setData(['directories' => $directories]);
	}

	/**
	 * Checks only the finite folder topology already connected to the mailbox.
	 *
	 * With a creator, a known folder of the user without a pair on the target server is brought there
	 * {@see TargetFolderCreator}, and the result reports it under "provisioned": the pairs found or
	 * created that way and the folders that could not be created. Nothing is created before the whole
	 * topology has been checked. A folder of the inbox or of a system role is never created; without a
	 * pair it fails the inspection with its role in "missingRole".
	 *
	 * @param array<int, array<string, mixed>> $knownDirectories
	 * @param string[] $notCreatedSources Paths of the known folders not to be tried again.
	 * @param bool $createMissing Without it a folder of the user without a pair is only let through,
	 *        neither created nor reported: it is left to the hand over {@see \Bitrix\Mail\Internal\Service\SourceGeneration\LateFolderReconciler}.
	 *        So is every folder still waiting when the time of the creator is out {@see TargetFolderCreator::hasTimeLeft()}.
	 */
	public function inspectKnownMigrationConnectionSnapshot(
		array $connection,
		array $knownDirectories,
		?Mail\Imap $client = null,
		?TargetFolderCreator $creator = null,
		array $notCreatedSources = [],
		bool $createMissing = true,
	): Main\Result
	{
		$result = new Main\Result();
		$this->clearErrors();

		$client ??= new Mail\Imap(
			$connection['SERVER'],
			$connection['PORT'],
			$connection['USE_TLS'] === 'Y' || $connection['USE_TLS'] === 'S',
			$connection['USE_TLS'] === 'Y',
			$connection['LOGIN'],
			$connection['PASSWORD'],
		);

		if ($client->getUnseen('inbox', $error) === false)
		{
			return $this->imapInspectionFailure($client, $result, $error);
		}
		if ($knownDirectories === [])
		{
			return $result->addError(new Error(
				'The mailbox has no saved folder topology',
				self::STANDARD_ERROR_KEY,
			));
		}

		$roots = $client->listex('', '%', $error);
		if ($roots === false)
		{
			return $this->imapInspectionFailure($client, $result, $error);
		}

		usort($knownDirectories, static fn (array $left, array $right): int => [
			(int)($left['LEVEL'] ?? 0),
			(int)($left['ID'] ?? 0),
		] <=> [
			(int)($right['LEVEL'] ?? 0),
			(int)($right['ID'] ?? 0),
		]);

		$notCreatedSources = array_fill_keys(array_map('strval', $notCreatedSources), true);
		$resolved = [];
		/** @var array<int, true> $pending Known folders without a pair, left for the creator */
		$pending = [];
		$planned = [];
		$directories = [];
		$takenNames = [];
		$notCreated = [];
		foreach ($knownDirectories as $known)
		{
			$id = (int)($known['ID'] ?? 0);
			$name = (string)($known['NAME'] ?? '');
			$sourcePath = (string)($known['PATH'] ?? $name);
			$parentId = (int)($known['PARENT_ID'] ?? 0);
			$parentPending = $parentId > 0 && isset($pending[$parentId]);
			if ($id <= 0 || $name === '' || ($parentId > 0 && !isset($resolved[$parentId]) && !$parentPending))
			{
				return $result->addError(new Error(
					'The saved IMAP folder topology is incomplete',
					self::STANDARD_ERROR_KEY,
				));
			}

			$knownRole = FolderPairRules::systemRole((array)($known['FLAGS'] ?? []));
			$folderRole = FolderPairRules::knownFolderRole($known, $knownRole);
			if ($parentPending && $folderRole === null)
			{
				$pending[$id] = true;
				$planned[] = $known;

				continue;
			}

			$expectedName = $parentId > 0 && !$parentPending
				? $resolved[$parentId]['name'] . $resolved[$parentId]['delim'] . $name
				: null;
			if ($expectedName !== null)
			{
				$candidates = $client->listex('', $expectedName, $error);
				if ($candidates !== false)
				{
					$candidates = FolderPairRules::matchingDirectories($expectedName, array_values(array_filter(
						$candidates,
						static fn (array $item): bool => !isset($takenNames[(string)($item['name'] ?? '')]),
					)), false);
				}

				$canonicalExpected
					= $resolved[$parentId]['name']
					. $resolved[$parentId]['delim']
					. FolderPairRules::canonicalSegment($name);
				if ($candidates === [] && $canonicalExpected !== $expectedName)
				{
					$candidates = $client->listex('', $canonicalExpected, $error);
					if ($candidates !== false)
					{
						$candidates = FolderPairRules::matchingDirectories($expectedName, array_values(array_filter(
							$candidates,
							static fn (array $item): bool => !isset($takenNames[(string)($item['name'] ?? '')]),
						)), false);
					}
				}
			}
			elseif ($parentPending)
			{
				$candidates = [];
			}
			else
			{
				$availableRoots = array_values(array_filter(
					$roots,
					static fn (array $item): bool => !isset($takenNames[(string)($item['name'] ?? '')]),
				));
				$candidates = $knownRole === null
					? FolderPairRules::matchingDirectories($name, $availableRoots, true)
					: array_values(array_filter(
						$availableRoots,
						static fn (array $item): bool => FolderPairRules::rootMatches($knownRole, $name, $item),
					));
			}

			if ($candidates === false)
			{
				return $this->imapInspectionFailure($client, $result, $error);
			}
			if ($candidates === [] && $knownRole !== null && ($expectedName !== null || $parentPending))
			{
				$expectedName = null;
				$candidates = array_values(array_filter(
					$roots,
					static fn (array $item): bool => FolderPairRules::systemRole((array)($item['flags'] ?? [])) === $knownRole
						&& !isset($takenNames[(string)($item['name'] ?? '')]),
				));
			}

			if ($folderRole === null && count($candidates) > 1)
			{
				$pending[$id] = true;
				$notCreated[$id] = self::notCreatedFolder($known, TargetFolderCreator::REASON_NAME_COLLISION);

				continue;
			}

			if ($creator !== null && $folderRole === null)
			{
				$collides = false;
				if ($parentId <= 0 && $candidates !== [])
				{
					$candidates = array_values(array_filter(
						$candidates,
						static fn (array $item): bool => !isset($takenNames[(string)($item['name'] ?? '')]),
					));
					$collides = $candidates === [];
				}

				if ($collides)
				{
					$pending[$id] = true;
					$notCreated[$id] = self::notCreatedFolder($known, TargetFolderCreator::REASON_NAME_COLLISION);

					continue;
				}
				if ($candidates === [])
				{
					$pending[$id] = true;
					$planned[] = $known;

					continue;
				}
			}

			if (count($candidates) !== 1)
			{
				return $result->addError(new Error(
					$candidates === [] && $folderRole !== null
						? sprintf('The known IMAP folder %s is missing or ambiguous (role %s)', $sourcePath, $folderRole)
						: sprintf('The known IMAP folder %s is missing or ambiguous', $sourcePath),
					self::STANDARD_ERROR_KEY,
					$candidates === [] && $folderRole !== null ? ['missingRole' => $folderRole] : null,
				));
			}

			$directory = self::knownDirectoryOnTarget(
				reset($candidates),
				$known,
				$expectedName !== null ? $resolved[$parentId]['path'] : [],
			);
			$takenNames[(string)$directory['name']] = true;
			$resolved[$id] = $directory;
			$directories[] = $directory;
		}

		$resolvedRoles = array_filter(array_map(
			static fn (array $directory): ?string => FolderPairRules::systemRole((array)$directory['flags']),
			$directories,
		));
		foreach (['sent', 'trash', 'spam'] as $role)
		{
			if (in_array($role, $resolvedRoles, true))
			{
				continue;
			}

			$candidates = array_values(array_filter(
				$roots,
				static fn (array $item): bool => FolderPairRules::systemRole((array)($item['flags'] ?? [])) === $role,
			));
			if (count($candidates) !== 1)
			{
				return $result->addError(new Error(
					sprintf('The target IMAP %s folder is missing or ambiguous', $role),
					self::STANDARD_ERROR_KEY,
					$candidates === [] ? ['missingRole' => $role] : null,
				));
			}

			$directory = reset($candidates);
			$directory['path'] = [FolderPairRules::lastPathPart(
				(string)$directory['name'],
				(string)($directory['delim'] ?? ''),
			)];
			$directory['is_sync'] = 0;
			$directories[] = $directory;
			$takenNames[(string)$directory['name']] = true;
		}

		if ($creator === null || !$createMissing)
		{
			$data = ['directories' => $directories];
			if ($notCreated !== [])
			{
				ksort($notCreated);
				$data['provisioned'] = ['pairs' => [], 'notCreated' => array_values($notCreated)];
			}

			return $result->setData($data);
		}

		$pairs = [];
		$rootDelimiter = self::rootDelimiter($roots);
		foreach ($planned as $known)
		{
			$id = (int)$known['ID'];
			$parentId = (int)($known['PARENT_ID'] ?? 0);
			$name = (string)$known['NAME'];
			$parent = $parentId > 0 ? ($resolved[$parentId] ?? null) : null;

			$reason = match (true)
			{
				$parentId > 0 && $parent === null => TargetFolderCreator::REASON_PARENT_NOT_CREATED,
				isset($notCreatedSources[(string)($known['PATH'] ?? $name)]) => '',
				$parentId <= 0 && self::isNameTaken($name, $takenNames) => TargetFolderCreator::REASON_NAME_COLLISION,
				default => null,
			};
			if ($reason === '')
			{
				continue;
			}
			if ($reason !== null)
			{
				$notCreated[$id] = self::notCreatedFolder($known, $reason);

				continue;
			}

			if (!$creator->hasTimeLeft())
			{
				// The rest is neither created nor reported: the hand over brings it in
				break;
			}

			$outcome = $creator->ensure(
				$client,
				$parent === null ? null : ['name' => (string)$parent['name'], 'delim' => (string)($parent['delim'] ?? '')],
				$name,
				$parent === null ? $rootDelimiter : null,
				// The inspection above has just looked the nested path up
				$parent !== null,
				(string)($known['PATH'] ?? $name),
			);
			if (in_array(
				$outcome['outcome'],
				[TargetFolderCreator::OUTCOME_RETRY, TargetFolderCreator::OUTCOME_CREATE_FAILED],
				true,
			))
			{
				MigrationMetrics::journalOperation(
					'acceptance_folder_creation_stopped',
					0,
					'',
					0,
					null,
					$outcome['outcome'] === TargetFolderCreator::OUTCOME_RETRY
						? 'lookup_failed'
						: (string)$outcome['reason'],
				);

				break;
			}

			$found = $outcome['directory'];
			if ($found === null || self::isNameTaken((string)($found['name'] ?? ''), $takenNames))
			{
				$notCreated[$id] = self::notCreatedFolder(
					$known,
					$found === null ? (string)$outcome['reason'] : TargetFolderCreator::REASON_NAME_COLLISION,
				);

				continue;
			}

			$directory = self::knownDirectoryOnTarget($found, $known, $parent['path'] ?? []);
			$takenNames[(string)$directory['name']] = true;
			$resolved[$id] = $directory;
			$directories[] = $directory;
			$pair = [
				'source' => (string)($known['PATH'] ?? $name),
				'target' => (string)$directory['name'],
				'created' => (bool)$outcome['created'],
			];
			$pairs[] = $pair;
		}

		ksort($notCreated);

		return $result->setData([
			'directories' => $directories,
			'provisioned' => ['pairs' => $pairs, 'notCreated' => array_values($notCreated)],
		]);
	}

	/**
	 * @param string[] $parentPath The segments of the parent on the target server; empty for a root.
	 */
	private static function knownDirectoryOnTarget(array $directory, array $known, array $parentPath): array
	{
		$directory['flags'] = array_values((array)($known['FLAGS'] ?? []));
		$directory['path'] = [
			...$parentPath,
			FolderPairRules::lastPathPart((string)$directory['name'], (string)($directory['delim'] ?? '')),
		];
		$directory['is_sync'] = (int)($known['IS_SYNC'] ?? 1);

		return $directory;
	}

	private static function notCreatedFolder(array $known, string $reason): array
	{
		return [
			'source' => (string)($known['PATH'] ?? $known['NAME'] ?? ''),
			'reason' => $reason,
			'directoryId' => (int)$known['ID'],
		];
	}

	/**
	 * @param array<string, true> $takenNames
	 */
	private static function isNameTaken(string $name, array $takenNames): bool
	{
		foreach (array_keys($takenNames) as $taken)
		{
			if (
				mb_strtoupper(FolderPairRules::canonicalSegment((string)$taken))
				=== mb_strtoupper(FolderPairRules::canonicalSegment($name))
			)
			{
				return true;
			}
		}

		return false;
	}

	private static function rootDelimiter(array $roots): ?string
	{
		foreach ($roots as $root)
		{
			$delimiter = (string)($root['delim'] ?? '');
			if ($delimiter !== '' && mb_strtoupper($delimiter) !== 'NIL')
			{
				return $delimiter;
			}
		}

		return null;
	}

	private function imapInspectionFailure(Mail\Imap $client, Main\Result $result, mixed $error): Main\Result
	{
		$this->addErrors($client->getErrors());
		foreach ($this->getErrors() as $connectionError)
		{
			$result->addError($connectionError);
		}

		return $result->isSuccess()
			? $result->addError(new Error('IMAP connection failed', $error ?: self::STANDARD_ERROR_KEY))
			: $result;
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
	 *     service: array{name: string|null, type: string|null, link: string|null, isOAuth: bool, oauthSmtpEnabled: bool, smtpServer: string, smtpLoginAsImap: bool, smtpPasswordAsImap: bool, externalCredentials: bool},
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
	 *     migrationActive: bool,
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
		$mailboxData['migrationActive'] = (new MigrationActionGuard())->isBlocked($mailboxId);

		return $this->appendMailboxSettingsConfig($mailboxData);
	}

	/**
	 * @param bool $withCrmFilterState Include the actual gate state as crmOptions.filterActive
	 * @return array{
	 *     imap: array{email: string, login: string, password: string, serviceId: int, server: string, port: string, ssl: string, isOAuth: bool, oauthUid: string|null},
	 *     smtp: array{enabled: 'Y'|'N', server: string, port: string, ssl: 'Y'|'N', login: string, useLimit: bool, limit: int|null},
	 *     service: array{name: string|null, type: string|null, link: string|null, isOAuth: bool, oauthSmtpEnabled: bool, smtpServer: string, smtpLoginAsImap: bool, smtpPasswordAsImap: bool, externalCredentials: bool},
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

		$mailbox = Main\Application::getConnection()->query(sprintf(
			'SELECT * FROM %s WHERE ID = %u',
			Mail\MailboxTable::getTableName(),
			$mailboxId,
		))->fetch();
		if (!$mailbox || $mailbox['SERVER_TYPE'] !== 'imap' || !in_array($mailbox['ACTIVE'], [
			MailboxStatus::Active->value,
			MailboxStatus::Pending->value,
			MailboxStatus::Canceled->value,
		], true))
		{
			return null;
		}
		foreach (['OPTIONS', 'PASSWORD'] as $field)
		{
			$mailbox[$field] = SettingsWriteCoordinator::decodeStoredField(
				Mail\MailboxTable::class, $field, $mailbox[$field] ?? '',
			);
		}
		$mailbox['OPTIONS'] = is_array($mailbox['OPTIONS']) ? $mailbox['OPTIONS'] : [];

		$service = Mail\Internal\Service\SourceGeneration\ActiveMailboxService::resolve($mailbox);

		$options = $mailbox['OPTIONS'] ?? [];
		$shareAccess = $this->getShareAccessData($mailboxId);

		$passwordlessSmtp = is_array($options['passwordless_smtp'] ?? null) ? $options['passwordless_smtp'] : [];
		$senderName = !empty($passwordlessSmtp['senderName'])
			? (string)$passwordlessSmtp['senderName']
			: (string)($mailbox['USERNAME'] ?? '');

		$serviceName = $service['NAME'] ?? null;
		$providerRestriction = (
			ProviderAccessRestriction::isFeatureEnabled()
			&& ProviderAccessRestriction::isRestricted($serviceName)
		)
			? ProviderAccessRestriction::resolveProviderName($serviceName)
			: null;

		return [
			'imap' => $this->getImapData($mailbox),
			'sourceGenerationId' => array_key_exists('ACTIVE_GENERATION_ID', $mailbox)
				? (int)$mailbox['ACTIVE_GENERATION_ID']
				: null,
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
				'externalCredentials' => $service !== null && Mail\MailServicesTable::hasExternalCredentials($service),
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
			'isSuccess' => $syncManager->getStoredConnectionStatus($mailboxId),
		];
	}

	private function isMailboxOAuth(array $mailbox): bool
	{
		return (bool)Mail\Helper\OAuth::getInstanceByMeta($mailbox['PASSWORD'] ?? '');
	}

	public static function getMailboxSender(
		int $mailboxId,
		?string $email = null,
		?int $userId = null,
		bool $readOnly = false,
		?array $lockedSenders = null,
	): ?array
	{
		$mailboxSender = null;
		$senders = $lockedSenders === null
			? Main\Mail\Sender::getByParentId($mailboxId)
			: array_filter($lockedSenders, static fn (array $sender): bool => $sender['PARENT_MODULE_ID'] === 'mail' && (int)$sender['PARENT_ID'] === $mailboxId);
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

		if ($mailboxSender || empty($email) || empty($userId) || !self::isLegacySenderLookupEnabled())
		{
			return $mailboxSender;
		}

		$senders = $lockedSenders === null
			? Main\Mail\Sender::getByEmail($email, $userId)
			: array_filter($lockedSenders, static fn (array $sender): bool => $sender['EMAIL'] === $email && (int)$sender['USER_ID'] === $userId);
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

	/**
	 * Whether the SMTP record of a mailbox connected before the records carried the mailbox reference
	 * is still looked up by the owner and the address.
	 */
	public static function isLegacySenderLookupEnabled(): bool
	{
		return Option::get('main', self::LEGACY_SENDER_LOOKUP_OPTION, 'Y') !== 'N';
	}

	private function resolveUseSenderName(array $mailbox, int $mailboxId): bool
	{
		if (array_key_exists('useSenderName', $mailbox['OPTIONS'] ?? []))
		{
			return (bool)$mailbox['OPTIONS']['useSenderName'];
		}

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
	 * }
	 */
	private function getImapData(array $mailbox): array
	{
		// The mark is only unpacked, never resolved into a profile: resolving it goes to the OAuth
		// provider, and this payload answers the settings screen. The profile is asked for by
		// {@see self::getOAuthUserProfile()} after the form is drawn.
		$oauthMeta = Mail\Helper\OAuth::parseMeta($mailbox['PASSWORD'] ?? '');

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
		];
	}

	/**
	 * Profile of the OAuth account behind the mailbox, read from the provider. This is the one path of
	 * the edit flow that goes to the network for it, and it is asked for separately from the payload of
	 * the screen: the request may renew the token and then walk several provider endpoints, so the
	 * settings must never wait for it.
	 *
	 * @return array{email: string, first_name?: string, last_name?: string, full_name?: string, image?: string, userPrincipalName?: string}|null
	 */
	public function getOAuthUserProfile(int $mailboxId): ?array
	{
		if (!MailboxAccess::hasCurrentUserAnyAccessToMailbox($mailboxId))
		{
			return null;
		}

		$mailbox = Mail\MailboxTable::query()
			->setSelect(['EMAIL', 'PASSWORD'])
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

		$profile = Mail\Helper\OAuth::getUserDataByMeta($mailbox['PASSWORD'] ?? '');

		if (!is_array($profile))
		{
			return null;
		}

		// The address of the mailbox is the one the user connected; the provider may answer with another.
		$profile['email'] = (string)($mailbox['EMAIL'] ?? '');

		return $profile;
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
	private function getSmtpData(int $mailboxId, array $mailbox, ?array $lockedSenders = null): array
	{
		$sender = self::getMailboxSender(
			$mailboxId,
			$mailbox['EMAIL'],
			(int)($mailbox['USER_ID'] ?? 0),
			readOnly: true,
			lockedSenders: $lockedSenders,
		);

		$smtpOptions = $sender['OPTIONS']['smtp'] ?? [];
		$hasSmtp = !empty($smtpOptions['server']);
		$storedSmtpServer = (string)($mailbox['OPTIONS']['last_smtp_server'] ?? '');

		return [
			'enabled' => $hasSmtp ? 'Y' : 'N',
			'server' => $hasSmtp ? $smtpOptions['server'] : $storedSmtpServer,
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
		?int $expectedSourceGenerationId = null,
		bool $hasExpectedSourceGenerationId = false,
		?array $requestedFields = null,
		?AvatarChange $avatar = null,
	): array
	{
		if (!MailboxAccess::hasCurrentUserAccessToEditMailbox($mailboxId))
		{
			$this->addErrorWithMessage();

			return [];
		}
		$hasExpectedSourceGenerationId = $hasExpectedSourceGenerationId || $expectedSourceGenerationId !== null;
		$requested = $this->retainRequestedSettings($dto, $requestedFields);
		$this->normalizeEmptyPorts($requested);
		$patch = $this->getGeneralSettingsPatch($requested, $requestedFields);
		$service = new GeneralSettingsService(allowCanceled: true);
		$validation = $service->validatePatch($mailboxId, $patch);
		if (!$validation->isSuccess())
		{
			$this->addResultErrors($validation);

			return [];
		}
		if (!$this->hasProtectedSettings($requested))
		{
			$result = $service->update($mailboxId, $patch, $avatar);
			$this->addResultErrors($result);

			return $result->isSuccess() ? $this->settingsResponse($mailboxId) + $result->getData() : [];
		}
		$existingData = $this->getMailboxData($mailboxId, withCrmFilterState: false);
		if ($existingData === null)
		{
			$this->addErrorWithMessage();

			return [];
		}
		$issuedSmtp = null;
		if (!empty($existingData['service']['externalCredentials']))
		{
			$issuedSmtp = $this->readIssuedSmtpCredentials($mailboxId, $requested, $existingData);
			if ($issuedSmtp !== null)
			{
				// The save is compared against what the hosting issued and not against the empty set
				// the deleted sender left behind, so a value of somebody else is still a change and
				// is still refused.
				$existingData['smtp'] = array_merge($existingData['smtp'], [
					'server' => $issuedSmtp['server'],
					'port' => $issuedSmtp['port'],
					'ssl' => $issuedSmtp['ssl'],
					'login' => $issuedSmtp['login'],
				]);
			}

			if ($this->hasExternalCredentialsChanged($requested, $existingData))
			{
				$this->addError(
					Loc::getMessage('MAIL_MAILBOX_CONNECTOR_EXTERNAL_CREDENTIALS_LOCKED') ?? '',
					self::EXTERNAL_CREDENTIALS_ERROR_KEY,
				);

				return [];
			}
		}
		if ($issuedSmtp !== null)
		{
			// Past the ban the save carries the issued set or nothing at all, so what it leaves out
			// is taken from the kit - that is the restore itself. The sender is then built with the
			// credentials the hosting gave, down to the password no form ever showed.
			$requested->serverSmtp ??= $issuedSmtp['server'];
			$requested->portSmtp ??= $issuedSmtp['port'];
			$requested->sslSmtp ??= $issuedSmtp['ssl'] === 'Y';
			$requested->loginSmtp ??= $issuedSmtp['login'];
			$requested->passwordSMTP ??= $issuedSmtp['password'];
		}
		$crmDenied = $patch->hasCrmChanges() && !$this->isCrmIntegrationAvailableForCurrentUser();
		if ($crmDenied)
		{
			// Legacy read-only CRM controls submit their disabled inner fields along with the switch.
			if (!$this->isCrmStateUnchanged($requested->crmOptions, $existingData))
			{
				$this->addError(
					(string)Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CRM_ACCESS_DENIED'),
					self::CRM_ACCESS_DENIED_ERROR_KEY,
				);

				return [];
			}
			$values = $patch->toArray();
			unset($values['crmOptions']);
			$patch = GeneralSettingsPatch::fromArray($values);
		}
		$guard = (new MigrationActionGuard())->check($mailboxId);
		if (!$guard->isSuccess() && (!$hasExpectedSourceGenerationId || $this->hasProtectedChanges($requested, $existingData)))
		{
			$this->addResultErrors($guard->getErrorCollection()->getErrorByCode(MigrationActionGuard::ERROR_ACTION_BLOCKED)
				? $this->settingsConflict(MigrationActionGuard::ERROR_ACTION_BLOCKED)
				: $guard);

			return [];
		}
		if (!$this->validateOwnerChange($mailboxId, $requested, $existingData))
		{
			return [];
		}

		$save = function (?int $resourceId = null) use (
			$mailboxId,
			$requested,
			$patch,
			$existingData,
			$expectedSourceGenerationId,
			$hasExpectedSourceGenerationId,
			$crmDenied,
			$service,
			$avatar,
		): Main\Result {
			$existingSender = self::getMailboxSender(
				$mailboxId,
				$existingData['imap']['email'],
				$existingData['userId'],
				readOnly: true,
			);
			$prepared = $this->prepareSettingsConnection($mailboxId, $requested, $existingData, $existingSender);
			if ($prepared === null)
			{
				if ($this->getErrors() === [])
				{
					$this->addErrorWithMessage();
				}
				$errors = $this->getErrors();
				$this->clearErrors();

				return (new Main\Result())->addErrors($errors);
			}

			$coordinator = new SettingsWriteCoordinator();

			return $coordinator->execute(
				$mailboxId,
				function (array $state, array &$afterCommit) use (
					$mailboxId,
					$requested,
					$patch,
					$existingData,
					$existingSender,
					$prepared,
					$expectedSourceGenerationId,
					$hasExpectedSourceGenerationId,
					$crmDenied,
					$service,
					$coordinator,
					$avatar,
					$resourceId,
				): Main\Result {
					$mailbox = $state['mailbox'];
					if ($avatar !== null)
					{
						$checked = (new AvatarService())->checkChange($mailbox, $avatar);
						if (!$checked->isSuccess())
						{
							return $checked;
						}
					}
					$access = $service->checkAccess($mailbox, $patch);
					if (!$access->isSuccess())
					{
						return $access;
					}
					$senderRows = $coordinator->lockSendersWithinTransaction($mailboxId, $mailbox);
					$sender = self::getMailboxSender(
						$mailboxId,
						(string)$mailbox['EMAIL'],
						(int)$mailbox['USER_ID'],
						readOnly: true,
						lockedSenders: $senderRows,
					);
					$fresh = $existingData;
					$decodedMailbox = $mailbox;
					$decodedMailbox['PASSWORD'] = SettingsWriteCoordinator::decodeStoredField(
						Mail\MailboxTable::class, 'PASSWORD', $mailbox['PASSWORD'],
					);
					$fresh['imap'] = $this->getImapData($decodedMailbox);
					$fresh['smtp'] = $this->getSmtpData($mailboxId, $mailbox, $senderRows);
					$fresh['userId'] = (int)$mailbox['USER_ID'];
					$fresh['options'] = $mailbox['OPTIONS'];
					$connectionChanged = $this->hasMigrationConnectionSettingsChanged($requested, $fresh);
					$protectedChanged = $this->hasProtectedChanges($requested, $fresh);
					$operations = $state['operations'];
					$latest = $operations === [] ? null : $operations[array_key_last($operations)];
					$active = (new MigrationStatusProvider())->fromRow($latest)?->visibility === 'active';
					if ($active && (!$hasExpectedSourceGenerationId || $protectedChanged))
					{
						return $this->settingsConflict(MigrationActionGuard::ERROR_ACTION_BLOCKED);
					}
					if (
						($connectionChanged && $hasExpectedSourceGenerationId
							&& $expectedSourceGenerationId !== $state['sourceGenerationId'])
						|| ($connectionChanged && !$hasExpectedSourceGenerationId && $state['hasSwitchedSource'])
						|| $existingData['sourceGenerationId'] !== $state['sourceGenerationId']
						|| $existingData['imap'] !== $fresh['imap']
						|| (int)$existingData['userId'] !== $fresh['userId']
						|| $this->senderConnectionState($existingSender) !== $this->senderConnectionState($sender)
					)
					{
						return $this->settingsConflict('MAIL_SOURCE_GENERATION_CHANGED');
					}
					if ($crmDenied && !$this->isCrmStateUnchanged($requested->crmOptions, $fresh))
					{
						return (new Main\Result())->addError(new Error(
							(string)Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CRM_ACCESS_DENIED'),
							self::CRM_ACCESS_DENIED_ERROR_KEY,
						));
					}
					if (!$this->validateOwnerChange($mailboxId, $requested, $fresh))
					{
						return (new Main\Result())->addErrors($this->getErrors());
					}

					$ownerChanged = $requested->userIdToConnect !== null
						&& $requested->userIdToConnect !== (int)$mailbox['USER_ID'];
					$generalPatch = $patch;
					if ($ownerChanged)
					{
						$values = $patch->toArray();
						unset($values['shareAccess']);
						$generalPatch = GeneralSettingsPatch::fromArray($values);
					}
					$general = $service->applyWithinTransaction($mailbox, $generalPatch, $afterCommit);
					if (!$general->isSuccess())
					{
						return $general;
					}
					$refreshed = $coordinator->lockWithinTransaction($mailboxId);
					if (!$refreshed->isSuccess())
					{
						return $refreshed;
					}
					$current = $refreshed->getData()['mailbox'];
					$written = $this->writeProtectedSettings(
						$current,
						$requested,
						$prepared,
						$sender,
						$senderRows,
						$patch,
						$afterCommit,
					);
					if (!$written->isSuccess())
					{
						return $written;
					}
					$afterCommit[] = static function () use ($mailboxId): void {
						Mail\MailboxTable::cleanCachesByMailboxId($mailboxId);
						Mail\MailboxTable::cleanEntityCache();
						MailboxSearchIndexHelper::saveSearchIndexForMailbox($mailboxId);
					};
					$afterCommit[] = static function () use ($mailboxId, $mailbox): void {
						if (Feature::isMailboxGridAvailable())
						{
							$current = Mail\MailboxTable::getById($mailboxId)->fetch();
							if ($current)
							{
								Notification::sendEditMailboxNotifications($current, (int)$mailbox['USER_ID'], (int)$current['USER_ID']);
							}
						}
					};
					if ($requested->syncAfterConnection)
					{
						$afterCommit[] = fn () => $this->syncMailbox($mailboxId);
					}

					$result = (new Main\Result())->setData(['id' => $mailboxId]);
					if ($avatar !== null)
					{
						$applied = (new AvatarService())->applyWithinTransaction(
							$mailbox, $avatar, $resourceId, $afterCommit,
						);
						if (!$applied->isSuccess())
						{
							return $applied;
						}
						$result->setData($result->getData() + $applied->getData());
					}

					return $result;
				},
				withMigrationState: true,
				checkSwitchedSource: true,
			);
		};
		$result = $avatar === null ? $save() : (new AvatarService())->save($mailboxId, $avatar, $save);
		$this->addResultErrors($result);

		return $result->isSuccess() ? $this->settingsResponse($mailboxId) + $result->getData() : [];
	}

	private function retainRequestedSettings(MailboxConnectDTO $dto, ?array $fields): MailboxConnectDTO
	{
		$requested = clone $dto;
		if ($fields === null)
		{
			return $requested;
		}
		$aliases = [
			'email' => ['email'], 'login' => ['login_imap'], 'password' => ['pass_imap'],
			'serviceId' => ['service_id'], 'server' => ['server_imap'], 'port' => ['port_imap'],
			'ssl' => ['ssl_imap'], 'storageOauthUid' => ['oauth_uid'], 'useSmtp' => ['use_smtp'],
			'serverSmtp' => ['server_smtp'], 'portSmtp' => ['port_smtp'], 'sslSmtp' => ['ssl_smtp'],
			'loginSmtp' => ['login_smtp'], 'passwordSMTP' => ['pass_smtp'],
			'useLimitSmtp' => ['use_limit_smtp'], 'limitSmtp' => ['limit_smtp'],
			'mailboxName' => ['name'], 'senderName' => ['sender'], 'iCalAccess' => ['ical_access'],
			'userIdToConnect' => ['owner_id'], 'messageMaxAge' => ['msg_max_age'],
			'uploadOutgoing' => ['upload_outgoing'], 'shareAccess' => ['share_access', 'access'],
			'useSenderName' => ['use_sender_name'], 'userPrincipalName' => ['user_principal_name'],
			'link' => [], 'serviceConfig' => [], 'syncAfterConnection' => [],
		];
		foreach ($aliases as $key => $legacyKeys)
		{
			if (array_intersect(array_keys($fields), [$key, ...$legacyKeys]) === [])
			{
				$requested->$key = null;
			}
		}
		if (array_key_exists('pass_placeholder', $fields))
		{
			foreach (['password', 'passwordSMTP'] as $key)
			{
				if ($fields['pass_placeholder'] === $requested->$key)
				{
					$requested->$key = null;
				}
			}
		}

		return $requested;
	}

	private function getGeneralSettingsPatch(MailboxConnectDTO $dto, ?array $requestedFields): GeneralSettingsPatch
	{
		$values = [];
		foreach (['mailboxName', 'link', 'senderName', 'useSenderName', 'iCalAccess', 'shareAccess'] as $key)
		{
			if ($requestedFields !== null && array_key_exists($key, $requestedFields))
			{
				$value = $requestedFields[$key];
				if (in_array($key, ['useSenderName', 'iCalAccess'], true) && $value !== null)
				{
					$value = $this->settingsBoolean($value);
				}
				$values[$key] = $value;
			}
			elseif ($dto->$key !== null)
			{
				$values[$key] = $dto->$key;
			}
		}
		if (is_array($requestedFields['crmOptions'] ?? null))
		{
			$crm = $requestedFields['crmOptions'];
			if (array_key_exists('enabled', $crm))
			{
				$crm['enabled'] = $this->settingsBoolean($crm['enabled']);
			}
			foreach (['crm_public', 'crm_vcf'] as $key)
			{
				if (is_array($crm['config'] ?? null) && array_key_exists($key, $crm['config']))
				{
					$crm['config'][$key] = $this->settingsBoolean($crm['config'][$key]);
				}
			}
			if (is_string($crm['config']['crm_new_lead_for'] ?? null))
			{
				$crm['config']['crm_new_lead_for'] = array_values(array_filter(preg_split(
					'/[\r\n,;]+/', $crm['config']['crm_new_lead_for'],
				)));
			}
			if (is_array($crm['config']['crm_lead_resp'] ?? null))
			{
				$crm['config']['crm_lead_resp'] = array_map(
					static fn ($id) => is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id) ? (int)$id : $id,
					$crm['config']['crm_lead_resp'],
				);
			}
			if (is_string($crm['config']['crm_sync_days'] ?? null)
				&& preg_match('/^-?[0-9]+$/D', $crm['config']['crm_sync_days']))
			{
				$crm['config']['crm_sync_days'] = (int)$crm['config']['crm_sync_days'];
			}
			$values['crmOptions'] = $crm;
		}
		elseif ($dto->crmOptions !== null)
		{
			$values['crmOptions'] = $dto->crmOptions->toArray();
			if ($requestedFields !== null && !$this->hasProtectedSettings($dto))
			{
				$crm = clone $dto->crmOptions;
				$crm->enabled = true;
				$config = $crm->toArray()['config'];
				$values['crmOptions'] = [];
				if (array_key_exists('use_crm', $requestedFields))
				{
					$values['crmOptions']['enabled'] = $dto->crmOptions->enabled;
				}
				foreach ([
					'crm_public' => 'crm_public', 'crm_vcf' => 'crm_vcf',
					'crm_lead_source' => 'crm_lead_source', 'crm_new_lead_for' => 'crm_new_lead_for',
					'crm_queue' => 'crm_lead_resp', 'crm_entity_in' => 'crm_new_entity_in',
					'crm_allow_entity_in' => 'crm_new_entity_in', 'crm_entity_out' => 'crm_new_entity_out',
					'crm_allow_entity_out' => 'crm_new_entity_out', 'crm_sync_old' => 'crm_sync_days',
					'crm_max_age' => 'crm_sync_days',
				] as $field => $key)
				{
					if (array_key_exists($field, $requestedFields))
					{
						$values['crmOptions']['config'][$key] = $config[$key];
					}
				}
			}
		}

		return GeneralSettingsPatch::fromArray($values);
	}

	private function settingsBoolean(mixed $value): mixed
	{
		return match ($value)
		{
			'Y', 'true', '1', 1 => true,
			'N', 'false', '0', 0 => false,
			default => $value,
		};
	}

	private function hasProtectedSettings(MailboxConnectDTO $dto): bool
	{
		foreach ([
			'email', 'login', 'password', 'serviceId', 'server', 'port', 'ssl', 'storageOauthUid',
			'useSmtp', 'serverSmtp', 'portSmtp', 'sslSmtp', 'loginSmtp', 'passwordSMTP',
			'useLimitSmtp', 'limitSmtp', 'userIdToConnect', 'messageMaxAge', 'serviceConfig',
			'uploadOutgoing', 'userPrincipalName',
		] as $key)
		{
			if ($dto->$key !== null)
			{
				return true;
			}
		}

		return false;
	}

	private function hasProtectedChanges(MailboxConnectDTO $dto, array $existing): bool
	{
		return $this->hasMigrationConnectionSettingsChanged($dto, $existing)
			|| ($dto->userIdToConnect !== null && $dto->userIdToConnect !== $existing['userId'])
			|| ($dto->useLimitSmtp !== null && $dto->useLimitSmtp !== $existing['smtp']['useLimit'])
			|| ($dto->limitSmtp !== null && ($dto->useLimitSmtp ?? $existing['smtp']['useLimit'])
				&& $dto->limitSmtp !== (int)$existing['smtp']['limit'])
			|| ($dto->uploadOutgoing !== null
				&& $dto->uploadOutgoing === in_array('deny_upload', $existing['options']['flags'] ?? [], true))
			|| $dto->messageMaxAge !== null;
	}

	private function settingsConflict(string $code): Main\Result
	{
		$message = $code === MigrationActionGuard::ERROR_ACTION_BLOCKED
			? (string)Loc::getMessage('MAIL_MIGRATION_CREDENTIALS_LOCKED')
			: 'The mailbox source generation has changed. Reload the mailbox settings.';

		return (new Main\Result())->addError(new Error($message, $code));
	}

	private function senderConnectionState(?array $sender): array
	{
		return [
			'id' => (int)($sender['ID'] ?? 0),
			'smtp' => $sender['OPTIONS']['smtp'] ?? [],
		];
	}

	private function validateOwnerChange(int $mailboxId, MailboxConnectDTO $dto, array $existing): bool
	{
		$newOwnerId = $dto->userIdToConnect;
		if ($newOwnerId === null || $newOwnerId === $existing['userId'])
		{
			return true;
		}
		if ($newOwnerId <= 0 || !MailboxAccess::hasCurrentUserAccessToChangeMailboxOwner($mailboxId)
			|| !self::checkConnectionLimits($newOwnerId))
		{
			$this->addErrorWithMessage();

			return false;
		}
		if ((new MailboxEmailOccupancyService())->isAddressHeldByUser($existing['imap']['email'], $newOwnerId, $mailboxId))
		{
			$this->addError(Loc::getMessage('MAIL_MAILBOX_CONNECTOR_EMAIL_EXISTS_NEW_OWNER'), self::EXISTS_ERROR_KEY);

			return false;
		}

		return true;
	}

	private function prepareSettingsConnection(
		int $mailboxId,
		MailboxConnectDTO $requested,
		array $existing,
		?array $existingSender,
	): ?array
	{
		$dto = clone $requested;
		$this->mergeWithExistingData($dto, $existing);
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
		catch (\Exception)
		{
			$this->addErrorWithMessage();

			return null;
		}
		$dto->service = $connectionData['service'];
		$dto->site = $connectionData['site'];
		$dto->login = $connectionData['login'];
		$dto->password = $connectionData['password'];
		$mailboxData = $this->buildMailboxData($dto);
		$mailboxData['EMAIL'] = $existing['imap']['email'];
		$isOAuth = $connectionData['isOAuth'];
		$imapChanged = $requested->password !== null
			|| ($requested->server !== null && trim($requested->server) !== $existing['imap']['server'])
			|| ($requested->port !== null && $requested->port !== $existing['imap']['port'])
			|| ($requested->ssl !== null && $requested->ssl !== ($existing['imap']['ssl'] === 'Y'))
			|| ($requested->storageOauthUid !== null
				&& $requested->storageOauthUid !== ($existing['imap']['oauthUid'] ?? ''));
		if ($imapChanged && !$dto->skipConnectionValidation && !$this->validateImapConnection($mailboxData, $isOAuth))
		{
			return null;
		}
		if ($imapChanged && $dto->skipConnectionValidation && !self::isValidMailHost($mailboxData['SERVER']))
		{
			$this->addError(Loc::getMessage('MAIL_PASSWORDLESS_ERROR_INVALID_HOST') ?? '', self::SERVER_RESPONSE_ERROR_KEY);

			return null;
		}
		$this->setIsSmtpAvailable();
		$senderFields = $this->hasSmtpSettings($requested) ? $this->prepareSmtpSender($mailboxData, $dto) : null;
		if ($this->hasErrors())
		{
			return null;
		}
		if ($senderFields !== null && !$dto->skipConnectionValidation)
		{
			$smtp = $senderFields['OPTIONS']['smtp'];
			if (empty($smtp['password']))
			{
				$smtp['password'] = $existingSender['OPTIONS']['smtp']['password'] ?? '';
			}
			if ($dto->useLimitSmtp === false)
			{
				unset($smtp['limit']);
			}
			$existingSmtp = $existingSender['OPTIONS']['smtp'] ?? [];
			$smtp['isOauth'] = (bool)($smtp['isOauth'] ?? false);
			$existingSmtp['isOauth'] = (bool)($existingSmtp['isOauth'] ?? false);
			$smtp['port'] = (int)($smtp['port'] ?? 0);
			$existingSmtp['port'] = (int)($existingSmtp['port'] ?? 0);
			$transportKeys = array_flip(['server', 'port', 'protocol', 'login', 'password', 'isOauth']);
			$transportDefaults = array_fill_keys(array_keys($transportKeys), null);
			if (array_replace($transportDefaults, array_intersect_key($smtp, $transportKeys))
				!== array_replace($transportDefaults, array_intersect_key($existingSmtp, $transportKeys))
				|| (int)($existingSender['IS_CONFIRMED'] ?? 0) !== 1)
			{
				$senderFields['OPTIONS']['smtp'] = $smtp;
				$checked = self::appendSenderWithCallback(
					$senderFields,
					$this->resolveSmtpFallbackUserPrincipalName($dto, $isOAuth),
					$mailboxId,
					static function (array $fields): array {
						$smtp = $fields['OPTIONS']['smtp'];
						$result = Main\Mail\Sender::prepareSmtpConfigForSender($smtp);
						$fields['OPTIONS']['smtp'] = $smtp;

						return $result->isSuccess()
							? ['confirmed' => true, 'fields' => $fields]
							: ['confirmed' => false, 'errors' => $result->getErrorCollection()];
					},
				);
				if (empty($checked['confirmed']))
				{
					$this->addErrors($checked['errors'], $isOAuth, true);

					return null;
				}
				$smtp = $checked['fields']['OPTIONS']['smtp'];
			}
			$senderFields['OPTIONS']['smtp'] = $smtp;
		}

		return ['mailbox' => $mailboxData, 'sender' => $senderFields, 'dto' => $dto];
	}

	private function writeProtectedSettings(
		array $mailbox,
		MailboxConnectDTO $requested,
		array $prepared,
		?array $sender,
		array $senderRows,
		GeneralSettingsPatch $patch,
		array &$afterCommit,
	): Main\Result
	{
		$mailboxId = (int)$mailbox['ID'];
		$connection = Main\Application::getConnection();
		$fields = [];
		$imapFields = ['server' => 'SERVER', 'port' => 'PORT', 'ssl' => 'USE_TLS', 'password' => 'PASSWORD'];
		foreach ($imapFields as $key => $field)
		{
			if ($requested->$key !== null)
			{
				$fields[$field] = $prepared['mailbox'][$field];
			}
		}
		if ($requested->storageOauthUid !== null && $requested->storageOauthUid !== '')
		{
			$fields['PASSWORD'] = $prepared['mailbox']['PASSWORD'];
		}
		$options = $mailbox['OPTIONS'];
		if ($requested->uploadOutgoing !== null)
		{
			$flags = array_values(array_diff($options['flags'] ?? [], ['deny_upload']));
			if (in_array('deny_upload', $prepared['mailbox']['OPTIONS']['flags'] ?? [], true))
			{
				$flags[] = 'deny_upload';
			}
			$options['flags'] = $flags;
		}
		if ($requested->messageMaxAge !== null)
		{
			$updated = $this->applySyncFromOnUpdate(['OPTIONS' => $options], $options, $requested->messageMaxAge);
			$options = $updated['OPTIONS'];
		}
		$ownerId = (int)$mailbox['USER_ID'];
		$newOwnerId = $requested->userIdToConnect ?? $ownerId;
		if ($newOwnerId !== $ownerId)
		{
			$fields['USER_ID'] = $newOwnerId;
			$options['crm_lead_resp'] = array_values(array_unique(array_map(
				static fn ($id): int => (int)$id === $ownerId ? $newOwnerId : (int)$id,
				$options['crm_lead_resp'] ?? [],
			)));
			unset($options['fired_owner_at'], $options['fired_owner_notified_7d_at']);
			$accessRows = $connection->query(sprintf(
				'SELECT ACCESS_CODE FROM %s WHERE MAILBOX_ID = %u AND TASK_ID = 0 ORDER BY ID FOR UPDATE',
				Mail\Internals\MailboxAccessTable::getTableName(),
				$mailboxId,
			))->fetchAll();
			$accessCodes = $patch->has('shareAccess') ? $patch->get('shareAccess') : array_column($accessRows, 'ACCESS_CODE');
			$accessCodes = array_values(array_diff($accessCodes, ['U' . $ownerId]));
			$access = $this->applyMailboxShareAccess($accessCodes, $newOwnerId, $mailboxId, deferEffects: true);
			if ($access === null)
			{
				return (new Main\Result())->addErrors($this->getErrors());
			}
			$afterCommit[] = function () use ($mailboxId, $access, $ownerId, $newOwnerId): void {
				Mail\MailboxTable::cleanOwnerCacheByUserId($ownerId);
				Mail\MailboxTable::cleanOwnerCacheByUserId($newOwnerId);
				Mail\MailboxTable::cleanAllSharedCache();
				$this->detachUsersLosingShareAccess(
					$mailboxId,
					array_unique([...$access['previousAccess'], 'U' . $ownerId]),
					$access['currentAccess'],
					refreshAccess: true,
				);
			};
			$userId = (int)Main\Engine\CurrentUser::get()->getId();
			$afterCommit[] = static fn () => Notification::dispatchAccessChangedNotifications(
				$mailboxId,
				(string)$mailbox['EMAIL'],
				$access['previousAccess'],
				$access['currentAccess'],
				$userId,
			);
		}
		$senderFields = $prepared['sender'];
		$smtpRequested = $this->hasSmtpSettings($requested);
		if ($smtpRequested)
		{
			$this->syncStoredSmtpServer($options, $senderFields, $sender);
		}
		if ($smtpRequested && $requested->skipConnectionValidation)
		{
			if ($senderFields === null)
			{
				unset($options['passwordless_smtp']);
			}
			else
			{
				$smtp = $senderFields['OPTIONS']['smtp'];
				$options['passwordless_smtp'] = [
					'server' => $smtp['server'], 'port' => $smtp['port'], 'login' => $smtp['login'],
					'protocol' => $smtp['protocol'], 'limit' => $smtp['limit'] ?? 0,
					'senderName' => (string)$mailbox['USERNAME'],
					'useSenderName' => (bool)($options['useSenderName'] ?? false),
				];
			}
		}
		elseif ($smtpRequested)
		{
			if ($senderFields === null && !$prepared['dto']->useSmtp)
			{
				foreach ($senderRows as $row)
				{
					if (($row['PARENT_MODULE_ID'] === 'mail' && (int)$row['PARENT_ID'] === $mailboxId)
						|| (int)$row['ID'] === (int)($sender['ID'] ?? 0))
					{
						$connection->queryExecute(sprintf(
							'DELETE FROM %s WHERE ID = %u',
							Main\Mail\Internal\SenderTable::getTableName(),
							$row['ID'],
						));
						$this->scheduleSenderCache($row, $mailboxId, $afterCommit);
					}
				}
			}
			elseif ($senderFields !== null)
			{
				$senderOptions = $sender['OPTIONS'] ?? ['source' => 'mail.client.config'];
				$senderOptions['smtp'] = $senderFields['OPTIONS']['smtp'];
				if (array_key_exists('useSenderName', $options))
				{
					$senderOptions['useSenderName'] = $options['useSenderName'];
				}
				$senderFields = [
					'EMAIL' => $mailbox['EMAIL'],
					'NAME' => $mailbox['USERNAME'] ?? '',
					'USER_ID' => $newOwnerId,
					'IS_CONFIRMED' => 1,
					'IS_PUBLIC' => (int)($sender['IS_PUBLIC'] ?? 0),
					'PARENT_MODULE_ID' => 'mail',
					'PARENT_ID' => $mailboxId,
					'OPTIONS' => $senderOptions,
				];
				if ($sender !== null)
				{
					$this->writeSettingsFields(Main\Mail\Internal\SenderTable::class, (int)$sender['ID'], $senderFields);
				}
				else
				{
					$senderFields['OPTIONS'] = Main\Mail\Internal\SenderTable::getEntity()->getField('OPTIONS')
						->modifyValueBeforeSave($senderOptions, ['OPTIONS' => $senderOptions])
					;
					$senderId = (int)$connection->add(Main\Mail\Internal\SenderTable::getTableName(), $senderFields);
					if ($senderId <= 0)
					{
						return (new Main\Result())->addError(new Error('The SMTP sender was not saved', self::SMTP_SENDER_ERROR_KEY));
					}
					$sender = ['ID' => $senderId, 'EMAIL' => $mailbox['EMAIL']];
				}
				$this->scheduleSenderCache($sender, $mailboxId, $afterCommit);
			}
		}
		if ($newOwnerId !== $ownerId)
		{
			foreach ($senderRows as $row)
			{
				if ($row['PARENT_MODULE_ID'] === 'mail' && (int)$row['PARENT_ID'] === $mailboxId)
				{
					$this->writeSettingsFields(Main\Mail\Internal\SenderTable::class, (int)$row['ID'], ['USER_ID' => $newOwnerId]);
					$this->scheduleSenderCache($row, $mailboxId, $afterCommit);
				}
			}
		}
		if ($options !== $mailbox['OPTIONS'])
		{
			$fields['OPTIONS'] = $options;
		}
		$this->writeSettingsFields(Mail\MailboxTable::class, $mailboxId, $fields);
		if ($newOwnerId !== $ownerId)
		{
			if ($connection->isTableExists(Mail\Internals\VacationAutoReplyTable::getTableName()))
			{
				(new Mail\Service\VacationAutoReply\VacationAutoReplyService())->handOverMailbox($mailboxId, $newOwnerId);
			}
			$afterCommit[] = static function () use ($mailboxId, $ownerId, $newOwnerId, $mailbox): void {
				(new MailboxSyncManager($ownerId))->deleteSyncData($mailboxId);
				(new MailboxSyncManager($newOwnerId))->setDefaultSyncData($mailboxId);
				\CUserCounter::Clear($ownerId, 'mail_unseen', $mailbox['LID']);
				\CUserCounter::Clear($newOwnerId, 'mail_unseen', $mailbox['LID']);
			};
		}

		return new Main\Result();
	}

	private function syncStoredSmtpServer(
		array &$options,
		?array $senderFields,
		?array $sender,
	): void
	{
		$smtpServer = $this->normalizeStoredSmtpServer($senderFields['OPTIONS']['smtp']['server'] ?? null);
		if ($smtpServer === '')
		{
			$smtpServer = $this->normalizeStoredSmtpServer($sender['OPTIONS']['smtp']['server'] ?? null);
		}
		if ($smtpServer !== '')
		{
			$options['last_smtp_server'] = $smtpServer;
		}
	}

	private function normalizeStoredSmtpServer(?string $server): string
	{
		$hostname = $this->extractHostnameFromServerString(trim((string)$server));

		return $hostname !== null && self::isValidMailHost($hostname) ? $hostname : '';
	}

	private function hasSmtpSettings(MailboxConnectDTO $dto): bool
	{
		foreach (['useSmtp', 'serverSmtp', 'portSmtp', 'sslSmtp', 'loginSmtp', 'passwordSMTP',
			'useLimitSmtp', 'limitSmtp', 'storageOauthUid', 'password', 'userPrincipalName'] as $key)
		{
			if ($dto->$key !== null)
			{
				return true;
			}
		}

		return false;
	}

	private function writeSettingsFields(string $entity, int $id, array $fields): void
	{
		if ($fields === [])
		{
			return;
		}
		foreach ($fields as $key => &$value)
		{
			$value = $entity::getEntity()->getField($key)->modifyValueBeforeSave($value, $fields);
		}
		unset($value);
		$connection = Main\Application::getConnection();
		[$update, $binds] = $connection->getSqlHelper()->prepareUpdate($entity::getTableName(), $fields);
		$connection->queryExecute(sprintf('UPDATE %s SET %s WHERE ID = %u', $entity::getTableName(), $update, $id), $binds);
	}

	private function scheduleSenderCache(array $sender, int $mailboxId, array &$afterCommit): void
	{
		$afterCommit[] = static function () use ($sender, $mailboxId): void {
			$identities = [['parentModuleId' => 'mail', 'parentId' => $mailboxId]];
			if (isset($sender['PARENT_MODULE_ID']))
			{
				$identities[] = [
					'parentModuleId' => (string)$sender['PARENT_MODULE_ID'],
					'parentId' => (int)($sender['PARENT_ID'] ?? 0),
				];
			}
			(new SenderCacheCleaner())->clearForSender(
				(int)$sender['ID'],
				[(string)$sender['EMAIL']],
				$identities,
			);
		};
	}

	private function settingsResponse(int $mailboxId): array
	{
		$mailbox = Main\Application::getConnection()->query(sprintf(
			'SELECT EMAIL, USERNAME FROM %s WHERE ID = %u', Mail\MailboxTable::getTableName(), $mailboxId,
		))->fetch();
		$this->setSuccess();

		return [
			'id' => $mailboxId,
			'email' => trim((string)($mailbox['EMAIL'] ?? '')),
			'senderName' => UserSenderDataProvider::getAddressInEmailAngleFormat(
				email: trim((string)($mailbox['EMAIL'] ?? '')),
				senderName: (string)($mailbox['USERNAME'] ?? ''),
			),
		];
	}

	private function syncCrmImapFilter(int $mailboxId, array $flags, CrmImapFilterContext $context): Main\Result
	{
		return CrmImapFilter::sync(
			$mailboxId,
			in_array(CrmFlag::Connect->value, $flags, true),
			$context,
			lockFilters: true,
		);
	}

	private function hasErrors(): bool
	{
		return !empty($this->errorCollection);
	}

	private static function deleteMailboxSender(int $mailboxId): void
	{
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

	/**
	 * A client that may not edit CRM integration still submits its current state: the form renders the
	 * switch read-only, and a read-only checkbox is still sent. Repeating the stored state is not an
	 * attempt to change it, so only a different state must be refused.
	 *
	 * Compared by the integration switch alone: the read-only block sends its inner fields disabled,
	 * so they never match what is stored, and their values are not applied.
	 */
	private function isCrmStateUnchanged(?CrmOptions $requestedCrmOptions, array $existingData): bool
	{
		$existingFlags = (array)($existingData['options']['flags'] ?? []);
		$storedEnabled = in_array(CrmFlag::Connect->value, $existingFlags, true);

		return ($requestedCrmOptions?->enabled ?? false) === $storedEnabled;
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
		$coordinator = new SettingsWriteCoordinator();

		$result = $coordinator->execute(
			$id,
			static function (array $state, array &$afterCommit) use ($id): Main\Result {
				$mailbox = $state['mailbox'];
				if ($mailbox['ACTIVE'] !== MailboxStatus::Active->value || $mailbox['SERVER_TYPE'] !== 'imap')
				{
					return (new Main\Result())->addError(new Error(
						(string)Loc::getMessage('MAIL_MAILBOX_CONNECTOR_REMOVE_DELETE_ERROR_NO_MAILBOX'),
					));
				}

				$access = (new GeneralSettingsService())->checkAccess($mailbox, GeneralSettingsPatch::fromArray([]));
				if (!$access->isSuccess())
				{
					return $access;
				}

				$blocked = self::migrationBlock($state);
				if ($blocked !== null)
				{
					return $blocked;
				}

				(new self())->writeSettingsFields(Mail\MailboxTable::class, $id, [
					'ACTIVE' => MailboxStatus::Inactive->value,
				]);
				$afterCommit[] = static function () use ($mailbox, $id): void {
					Mail\MailboxTable::cleanCachesByMailboxId($id, (int)$mailbox['USER_ID']);
					Mail\MailboxTable::cleanEntityCache();
					self::deleteMailboxSender($id);
					\CUserCounter::clear((int)$mailbox['USER_ID'], 'mail_unseen', $mailbox['LID']);
					(new MailboxSyncManager((int)$mailbox['USER_ID']))->deleteSyncData($id);
					\CAgent::removeAgent(sprintf('CMailbox::CheckMailAgent(%u);', $id), 'mail');
					\CAgent::removeAgent(sprintf('Bitrix\Mail\Helper::syncMailboxAgent(%u);', $id), 'mail');
					\CAgent::removeAgent(sprintf('Bitrix\Mail\Helper::cleanupMailboxAgent(%u);', $id), 'mail');
					\CAgent::addAgent(
						sprintf('Bitrix\Mail\Helper::syncMailboxAgent(%u);', $id),
						'mail', 'N', (int)$mailbox['PERIOD_CHECK'] * 60,
					);
					\CAgent::addAgent(
						sprintf('Bitrix\Mail\Helper::cleanupMailboxAgent(%u);', $id), 'mail', 'N', 3600 * 24,
					);
					\CAgent::addAgent(sprintf('Bitrix\Mail\Helper::deleteMailboxAgent(%u);', $id), 'mail', 'N', 60);
					\CMailbox::SMTPReload();
				};

				return new Main\Result();
			},
			withMigrationState: true,
		);
		if ($result->getErrorCollection()->getErrorByCode(SettingsWriteCoordinator::ERROR_MAILBOX_NOT_FOUND))
		{
			return (new Main\Result())->addError(new Error(
				(string)Loc::getMessage('MAIL_MAILBOX_CONNECTOR_REMOVE_DELETE_ERROR_NO_MAILBOX'),
			));
		}

		return $result;
	}

	public function updateCalendarOptions(int $mailboxId, bool $enabled): Main\Result
	{
		return $this->updateIntegrationSettings($mailboxId, GeneralSettingsPatch::fromArray(['iCalAccess' => $enabled]));
	}

	public function updateCrmOptions(int $mailboxId, CrmOptions $crmOptions): Main\Result
	{
		return $this->updateIntegrationSettings(
			$mailboxId,
			GeneralSettingsPatch::fromArray(['crmOptions' => $crmOptions->toArray()]),
		);
	}

	public function enableCrmWithDefaults(int $mailboxId): Main\Result
	{
		return $this->updateIntegrationSettings(
			$mailboxId,
			GeneralSettingsPatch::fromArray(['crmOptions' => ['enabled' => true]]),
			crmDefaults: true,
		);
	}

	private function updateIntegrationSettings(
		int $mailboxId,
		GeneralSettingsPatch $patch,
		bool $crmDefaults = false,
	): Main\Result
	{
		$service = new GeneralSettingsService();

		return (new SettingsWriteCoordinator())->execute(
			$mailboxId,
			function (array $state, array &$afterCommit) use ($mailboxId, $patch, $crmDefaults, $service): Main\Result {
				$mailbox = $state['mailbox'];
				$access = $service->checkAccess($mailbox, $patch);
				if (!$access->isSuccess())
				{
					return $access;
				}
				$blocked = self::migrationBlock($state);
				if ($blocked !== null)
				{
					return $blocked;
				}
				$options = $mailbox['OPTIONS'];
				if ($patch->has('iCalAccess') && $patch->get('iCalAccess') === (($options['ical_access'] ?? 'N') === 'Y'))
				{
					return (new Main\Result())->setData([
						'skipped' => true,
						'reason' => $patch->get('iCalAccess')
							? 'Calendar integration already enabled' : 'Calendar integration already disabled',
					]);
				}
				if ($patch->hasCrmChanges())
				{
					$isEnabled = in_array(CrmFlag::Connect->value, $options['flags'] ?? [], true);
					$filters = CrmImapFilter::fetchFilters($mailboxId, lockFilters: true);
					$hasActiveFilter = false;
					foreach ($filters as $filter)
					{
						$hasActiveFilter = $hasActiveFilter
							|| ($filter['ACTIVE'] === 'Y' && $filter['WHEN_MAIL_RECEIVED'] === 'Y');
					}
					if ($crmDefaults && $isEnabled && $hasActiveFilter)
					{
						$marker = (new CrmImapGateReconciler())->writeSatisfiedMarkerWithinTransaction(
							$mailboxId,
							true,
						);
						if (!$marker->isSuccess())
						{
							return $marker;
						}

						return (new Main\Result())->setData(['skipped' => true, 'reason' => 'CRM already enabled']);
					}
					if (!$crmDefaults && $patch->get('crmOptions.enabled') === false
						&& !$isEnabled && $filters === []
						&& array_intersect($options['flags'] ?? [], [CrmFlag::Preconnect->value, CrmFlag::PublicBind->value]) === [])
					{
						$marker = (new CrmImapGateReconciler())->writeSatisfiedMarkerWithinTransaction(
							$mailboxId,
							false,
						);
						if (!$marker->isSuccess())
						{
							return $marker;
						}

						return (new Main\Result())->setData(['skipped' => true, 'reason' => 'CRM already disabled']);
					}
					if ($crmDefaults && !$isEnabled)
					{
						$config = $this->defaultCrmOptionValues((int)$mailbox['USER_ID']);
						unset($config[CrmOption::SyncFrom->value]);
						$config += [
							'crm_public' => false,
							'crm_vcf' => true,
							'crm_sync_days' => self::CRM_MAX_AGE,
						];
						$patch = GeneralSettingsPatch::fromArray(['crmOptions' => ['enabled' => true, 'config' => $config]]);
					}
				}

				return $service->applyWithinTransaction($mailbox, $patch, $afterCommit);
			},
			withMigrationState: true,
		);
	}

	/**
	 * Every mailbox action of the grid answers the visible active migration alike, and it answers it
	 * from the state locked by the write, not from a status read of its own. Kept in one place: a
	 * second condition of its own would let the actions drift apart.
	 */
	private static function migrationBlock(array $state): ?Main\Result
	{
		$operations = $state['operations'];
		$latest = $operations === [] ? null : $operations[array_key_last($operations)];
		if ((new MigrationStatusProvider())->fromRow($latest)?->visibility !== 'active')
		{
			return null;
		}

		return (new Main\Result())->addError(new Error(
			(string)Loc::getMessage('MAIL_MIGRATION_ACTION_UNAVAILABLE'),
			MigrationActionGuard::ERROR_ACTION_BLOCKED,
		));
	}

	/**
	 * Pre-validate that applying $access codes to mailbox won't exceed the shared-mailbox license limit.
	 * Returns false and adds an error when the limit would be exceeded; true otherwise.
	 *
	 * @param string[] $access
	 */
	public function assertSharedMailboxLimit(array $access, int $userId, ?int $mailboxId): bool
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
	 * @param bool $deferEffects Caller owns the mailbox lock and cache/label effects after commit.
	 * @return array{previousAccess: string[], currentAccess: string[]}
	 */
	public function applyMailboxShareAccess(
		array $access,
		int $userId,
		int $mailboxId,
		bool $deferEffects = false,
	): ?array
	{
		$previousAccessCodes = $deferEffects
			? Main\Application::getConnection()->query(sprintf(
				'SELECT ACCESS_CODE FROM %s WHERE MAILBOX_ID = %u AND TASK_ID = 0 ORDER BY ID FOR UPDATE',
				Mail\Internals\MailboxAccessTable::getTableName(),
				$mailboxId,
			))->fetchAll()
			: Mail\Internals\MailboxAccessTable::getList([
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

		$rowsToAdd = [];
		foreach ($uniqueAccess as $item)
		{
			$rowsToAdd[] = [
				'MAILBOX_ID' => $mailboxId,
				'TASK_ID' => 0,
				'ACCESS_CODE' => $item,
			];
		}

		if ($deferEffects)
		{
			$connection = Main\Application::getConnection();
			$table = Mail\Internals\MailboxAccessTable::getTableName();
			$connection->queryExecute(sprintf('DELETE FROM %s WHERE MAILBOX_ID = %u', $table, $mailboxId));
			foreach (array_chunk($rowsToAdd, 100) as $rows)
			{
				$connection->addMulti($table, $rows);
			}

			return [
				'previousAccess' => $previousAccessCodes,
				'currentAccess' => $uniqueAccess,
			];
		}

		Mail\Internals\MailboxAccessTable::deleteByFilter(['=MAILBOX_ID' => $mailboxId]);
		Mail\Internals\MailboxAccessTable::addMulti($rowsToAdd, true);

		try
		{
			$this->detachUsersLosingShareAccess($mailboxId, $previousAccessCodes, $uniqueAccess);
		}
		catch (\Throwable $exception)
		{
			Main\Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}

		return [
			'previousAccess' => $previousAccessCodes,
			'currentAccess' => $uniqueAccess,
		];
	}

	/**
	 * Only personal U-codes are enumerated; department codes are not expanded into members.
	 *
	 * @param string[] $previousAccess
	 * @param string[] $currentAccess
	 */
	public function detachUsersLosingShareAccess(
		int $mailboxId,
		array $previousAccess,
		array $currentAccess,
		bool $refreshAccess = false,
		?int $currentOwnerId = null,
	): void
	{
		if (!Mail\Helper\Label\LabelsFeature::isEnabled())
		{
			return;
		}

		if ($refreshAccess)
		{
			$result = (new SettingsWriteCoordinator())->execute(
				$mailboxId,
				function (array $state) use ($mailboxId, $previousAccess, $currentAccess): Main\Result {
					$access = Main\Application::getConnection()->query(sprintf(
						'SELECT ACCESS_CODE FROM %s WHERE MAILBOX_ID = %u ORDER BY ID FOR UPDATE',
						Mail\Internals\MailboxAccessTable::getTableName(),
						$mailboxId,
					))->fetchAll();
					$this->detachUsersLosingShareAccess(
						$mailboxId,
						array_values(array_diff($previousAccess, $currentAccess)),
						array_column($access, 'ACCESS_CODE'),
						currentOwnerId: (int)$state['mailbox']['USER_ID'],
					);

					return new Main\Result();
				},
			);
			if (!$result->isSuccess())
			{
				throw new Main\SystemException('The mailbox label access could not be refreshed');
			}

			return;
		}

		$lostAccessCodes = array_diff($previousAccess, $currentAccess);
		if ($lostAccessCodes === [] || !$this->mailboxHasLabelData($mailboxId))
		{
			return;
		}

		// With department codes around access is re-checked per user; otherwise the only remaining
		// route to the mailbox is owning it.
		$hasDepartmentCodes = $this->hasNonPersonalAccessCode($currentAccess);
		$ownerId = $currentOwnerId ?? Mail\MailboxTable::getOwnerId($mailboxId);

		$labelService = new Mail\Internal\Service\Label\LabelService();

		foreach ($lostAccessCodes as $accessCode)
		{
			if (!preg_match('/^U(\d+)$/', (string)$accessCode, $matches))
			{
				continue;
			}

			$userId = (int)$matches[1];
			$keepsAccess = $userId === $ownerId || in_array($accessCode, $currentAccess, true)
				|| ($hasDepartmentCodes && array_intersect(\CAccess::GetUserCodesArray($userId) ?? [], $currentAccess) !== []);

			if ($keepsAccess)
			{
				continue;
			}

			$labelService->detachUserFromMailbox($userId, $mailboxId);
		}
	}

	/**
	 * @param string[] $accessCodes
	 */
	private function hasNonPersonalAccessCode(array $accessCodes): bool
	{
		foreach ($accessCodes as $accessCode)
		{
			if (!preg_match('/^U\d+$/', (string)$accessCode))
			{
				return true;
			}
		}

		return false;
	}

	private function mailboxHasLabelData(int $mailboxId): bool
	{
		$hasBindings = Mail\Internals\MessageLabelTable::getRow([
			'select' => ['LABEL_ID'],
			'filter' => ['=MAILBOX_ID' => $mailboxId],
		]) !== null;

		if ($hasBindings)
		{
			return true;
		}

		return Mail\Internals\UserLabelTable::getRow([
			'select' => ['ID'],
			'filter' => ['=MAILBOX_ID' => $mailboxId],
		]) !== null;
	}
}
