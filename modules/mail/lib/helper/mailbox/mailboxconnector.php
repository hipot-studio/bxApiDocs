<?php

namespace Bitrix\Mail\Helper\Mailbox;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Helper\Dto\MailboxConnect\AbstractMailboxConnectDTO;
use Bitrix\Mail\Helper\Dto\MailboxConnect\MailboxMassconnectDTO;
use Bitrix\Mail\Helper\MailboxSearchIndexHelper;
use Bitrix\Main;
use Bitrix\Mail;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mail\Helper\LicenseManager;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Mail\Address;
use Bitrix\Mail\MailServicesTable;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Mail\Internal\SenderTable;

final class MailboxConnector
{
	private const STANDARD_ERROR_KEY = 1;
	private const LIMIT_ERROR_KEY = 2;
	private const OAUTH_ERROR_KEY = 3;
	private const EXISTS_ERROR_KEY = 4;
	private const NO_MAIL_SERVICES_ERROR_KEY = 5;
	private const SMTP_PASS_BAD_SYMBOLS_ERROR_KEY = 6;
	public const CRM_MAX_AGE = 7;
	public const MESSAGE_MAX_AGE = 7;

	private bool $isSuccess = false;

	private array $errorCollection = [];

	private bool $isSMTPAvailable = false;

	private ?AbstractMailboxConnectDTO $mailboxConnectDTO = null;

	public function setMailboxConnectDTO(AbstractMailboxConnectDTO $mailboxConnectDTO): void
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

	private function addError(string $error, int $code = 0, array|string|null $customData = null): void
	{
		if (!$customData)
		{
			$customData = $this->prepareErrorCustomData();
		}

		$this->errorCollection[] = new Main\Error($error, $code, $customData);
	}

	private function prepareErrorCustomData(): array
	{
		if ($this->mailboxConnectDTO === null)
		{
			return [];
		}

		return [
			'userIdToConnect' => $this->mailboxConnectDTO->userIdToConnect,
		];
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

		if (count($messages) == 1 && reset($messages)->getCode() == Mail\Imap::ERR_AUTH)
		{
			$authError = Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_IMAP_AUTH_ERR_EXT');
			if ($isOAuth && Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_ERR_OAUTH'))
			{
				$authError = Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_ERR_OAUTH');
			}
			if ($isOAuth && $isSender && Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_ERR_OAUTH_SMTP'))
			{
				$authError = Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_ERR_OAUTH_SMTP');
			}

			$messages = [
				new Main\Error($authError, Mail\Imap::ERR_AUTH),
			];

			$moreDetailsSection = false;
		}
		else
		{
			$moreDetailsSection = true;
		}

		$reduce = (static fn($error) => $error->getMessage());

		$message = implode(': ', array_map($reduce, $messages));
		$code = 0;
		$moreDetails = $moreDetailsSection
			? implode(': ', array_map($reduce, $details))
			: null
		;

		$this->addError($message, $code, $moreDetails);
	}

	private function setError(int $code = self::STANDARD_ERROR_KEY): void
	{
		$message = match ($code) {
			self::LIMIT_ERROR_KEY => Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_LIMIT_ERROR'),
			self::OAUTH_ERROR_KEY => Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_OAUTH_ERROR'),
			self::EXISTS_ERROR_KEY => Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_EMAIL_EXISTS_ERROR'),
			self::NO_MAIL_SERVICES_ERROR_KEY => Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_THERE_ARE_NO_MAIL_SERVICES'),
			self::SMTP_PASS_BAD_SYMBOLS_ERROR_KEY => Loc::getMessage('MAIL_MAILBOX_CONNECTOR_SMTP_PASS_BAD_SYMBOLS'),
			default => Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_FORM_ERROR'),
		};

		$this->addError($message);
	}

	private static function getUserOwnedMailboxCount(int $userId)
	{
		$res = Mail\MailboxTable::getList([
			'select' => [
				new ExpressionField('OWNED', 'COUNT(%s)', 'ID'),
			],
			'filter' => [
				'=ACTIVE' => 'Y',
				'=USER_ID' => $userId,
				'=SERVER_TYPE' => 'imap',
			],
		])->fetch();

		return $res['OWNED'];
	}

	public static function canConnectNewMailbox(?int $userId = null): bool
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

	public function connectMailboxWithDefaultCrm(AbstractMailboxConnectDTO $mailboxConnectDTO): array
	{
		return $this->connectMailboxAndSaveIndex($mailboxConnectDTO, defaultCrm: true);
	}

	public function connectMailboxWithCustomCrm(?AbstractMailboxConnectDTO $mailboxConnectDTO = null, bool $useClassDto = false): array
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
		AbstractMailboxConnectDTO $mailboxConnectDTO,
		?bool $defaultCrm = false,
	): array
	{
		global $USER;
		$mailboxConnectDTO->userIdToConnect ??= $USER->getId();
		$mailboxConnectDTO->email = trim($mailboxConnectDTO->email);
		$mailboxConnectDTO->login = trim($mailboxConnectDTO->login);
		$mailboxConnectDTO->password = trim($mailboxConnectDTO->password);

		$validatedMailboxConnectionData = $this->getMailboxConnectionData($mailboxConnectDTO);
		if ($validatedMailboxConnectionData === null)
		{
			return [];
		}

		$mailboxConnectDTO->service = $validatedMailboxConnectionData['service'];
		$mailboxConnectDTO->site = $validatedMailboxConnectionData['site'];
		$mailboxConnectDTO->login = $validatedMailboxConnectionData['login'];
		$mailboxConnectDTO->password = $validatedMailboxConnectionData['password'];
		$isOAuth = $validatedMailboxConnectionData['isOAuth'];

		$mailboxData = $this->buildMailboxData($mailboxConnectDTO);

		$unseen = Mail\Helper::getImapUnseen($mailboxData, 'inbox', $error, $errors);
		if ($unseen === false)
		{
			if ($errors instanceof Main\ErrorCollection)
			{
				$this->addErrors($errors, $isOAuth);
			}
			else
			{
				$this->setError();
			}

			return [];
		}

		$mailboxConnectDTO->messageMaxAge ??= self::MESSAGE_MAX_AGE;
		$mailboxData['OPTIONS']['sync_from'] = strtotime('today UTC 00:00' . sprintf('-%u days', $mailboxConnectDTO->messageMaxAge));

		if (!$defaultCrm)
		{
			if ($mailboxConnectDTO->crmOptions['enabled'] === 'Y')
			{
				$mailboxData = $this->applyCrmOptions($mailboxData, $mailboxConnectDTO->crmOptions['config']);
			}
		}
		else
		{
			$mailboxData = $this->setDefaultCrmOptions($mailboxData);
		}

		if (Loader::includeModule('calendar'))
		{
			$mailboxData['OPTIONS']['ical_access'] = $mailboxConnectDTO->iCalAccess;
		}

		if ($mailboxConnectDTO->useSmtp !== 'Y')
		{
			$this->resetExistingSmtp($mailboxData['EMAIL']);
		}

		$senderFields = $this->prepareSmtpSender($mailboxData, $mailboxConnectDTO);

		if ($this->hasErrors())
		{
			return [];
		}

		return $this->createMailboxInternal($mailboxData, $senderFields, $isOAuth, $mailboxConnectDTO->syncAfterConnection);
	}

	public function getMailboxConnectionData(AbstractMailboxConnectDTO $mailboxConnectDTO): ?array
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
			$this->setError($e->getMessage());

			return null;
		}
	}

	private function connectMailboxAndSaveIndex(AbstractMailboxConnectDTO $mailboxConnectDTO, bool $defaultCrm = false): array
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
		if (!self::canConnectNewMailbox($userId))
		{
			throw new \Exception(self::LIMIT_ERROR_KEY);
		}

		$service = $this->getMailService($serviceId, $serviceConfig);

		if ($service['ACTIVE'] !== 'Y')
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

	private function buildMailboxData(AbstractMailboxConnectDTO $mailboxConnectDTO): array
	{
		$useTls = $mailboxConnectDTO->ssl;

		$mailboxData = [
			'LID'         => $mailboxConnectDTO->site['LID'],
			'ACTIVE'      => 'Y',
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
			'SERVER'      => $mailboxConnectDTO->service['SERVER'] ?: trim($mailboxConnectDTO->server),
			'PORT'        => $mailboxConnectDTO->service['PORT'] ?: $mailboxConnectDTO->port,
			'USE_TLS'     => $mailboxConnectDTO->service['ENCRYPTION'] ?: $useTls,
			'LINK'        => $mailboxConnectDTO->service['LINK'],
			'PERIOD_CHECK' => 60 * 24,
			'OPTIONS'     => [
				'flags'     => [],
				'sync_from' => time(),
				'crm_sync_from' => time(),
				'activateSync' => false,
				'version'   => 6,
			],
			'SERVICE_NAME' => $mailboxConnectDTO->service['NAME'],
		];

		if (($mailboxConnectDTO->service['UPLOAD_OUTGOING'] ?? 'Y') === 'N')
		{
			$mailboxData['OPTIONS']['flags'][] = 'deny_upload';
		}

		return $mailboxData;
	}

	private function applyCrmOptions(array $mailboxData, array $crmOptions): array
	{
			if ($this->isCrmIntegrationAvailableForCurrentUser())
			{
				$mailboxData['OPTIONS']['flags'][] = 'crm_connect';

				if (($crmOptions['crm_public'] ?? 'N') === 'Y')
				{
					$interval = (int)Option::get('mail', 'public_mailbox_sync_interval', 0);
					$mailboxData['PERIOD_CHECK'] = $interval > 0 ? $interval : 10;
					$mailboxData['OPTIONS']['flags'][] = 'crm_public_bind';
				}

				$syncDays = $crmOptions['crm_sync_days'] ?? self::CRM_MAX_AGE;
				$mailboxData['OPTIONS']['crm_sync_from'] = strtotime(sprintf('-%u days', $syncDays));

				foreach ($crmOptions as $optionName => $optionValue)
				{
					$mailboxData['OPTIONS'][$optionName] = $optionValue;
				}

				$mailboxData['OPTIONS']['crm_new_lead_for'] = [];
				if (!empty($crmOptions['crm_new_lead_for']))
				{
					$newLeadFor = preg_split('/[\r\n,;]+/', (string)$crmOptions['crm_new_lead_for']);
					foreach ($newLeadFor as $i => $item)
					{
						$address = new Address($item, ['checkingPunycode' => true]);

						$newLeadFor[$i] = $address->validate() ? $address->getEmail() : null;
					}

					$mailboxData['OPTIONS']['crm_new_lead_for'] = array_values(array_unique(array_filter($newLeadFor)));
				}
			}

		return $mailboxData;
	}

	private function setDefaultCrmOptions(array $mailboxData): array
	{
		global $USER;

		if ($this->isCrmIntegrationAvailableForCurrentUser())
		{
				$maxAge = self::CRM_MAX_AGE;
				$mailboxData['OPTIONS']['flags'][] = 'crm_connect';
				$mailboxData['OPTIONS']['crm_sync_from'] = strtotime(sprintf('-%u days', $maxAge));
				$mailboxData['OPTIONS']['crm_new_entity_in'] = \CCrmOwnerType::LeadName;
				$mailboxData['OPTIONS']['crm_new_entity_out'] = \CCrmOwnerType::ContactName;
				$mailboxData['OPTIONS']['crm_lead_source'] = 'EMAIL';
				$mailboxData['OPTIONS']['crm_lead_resp'] = [empty($mailboxData) ? $USER->getId() : $mailboxData['USER_ID']];
		}

		return $mailboxData;
	}

	private function isCrmIntegrationAvailableForCurrentUser(): bool
	{
		if (!Loader::includeModule('crm'))
		{
			return false;
		}

		if (!MailboxAccess::hasCurrentUserAccessToEditMailboxIntegrationCrm())
		{
			return false;
		}

		return Feature::isCrmAvailable();
	}

	private function resetExistingSmtp(string $email): void
	{
		if (!$this->isSMTPAvailable)
		{
			return;
		}

		$res = Main\Mail\Internal\SenderTable::getList([
			'filter' => [
				'IS_CONFIRMED' => true,
				'=EMAIL' => $email,
			],
		]);

		while ($item = $res->fetch())
		{
			if (!empty($item['OPTIONS']['smtp']['server']))
			{
				unset($item['OPTIONS']['smtp']);

				Main\Mail\Internal\SenderTable::update(
					$item['ID'],
					['OPTIONS' => $item['OPTIONS']],
				);
			}
		}

		Main\Mail\Sender::clearCustomSmtpCache($email);
	}

	private function prepareSmtpSender(
		array $mailboxData,
		AbstractMailboxConnectDTO $mailboxConnectDTO,
	): ?array
	{
		$this->setIsSmtpAvailable();
		$isSmtpOauthEnabled =
			!empty(MailServicesTable::getOAuthHelper($mailboxConnectDTO->service))
			&& self::isOauthSmtpEnabled($mailboxConnectDTO->service['NAME'])
		;

		$service = $mailboxConnectDTO->service;

		$mailboxConnectDTO->useSmtp = ($mailboxConnectDTO->useSmtp === 'Y' || $isSmtpOauthEnabled) ? 'Y' : 'N';

		if (!$this->isSMTPAvailable || $mailboxConnectDTO->useSmtp !== 'Y')
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

		$mailboxSenders = Main\Mail\Internal\SenderTable::query()
			->setSelect(['ID', 'OPTIONS'])
			->where('IS_CONFIRMED', true)
			->where('EMAIL', $senderFields['EMAIL'])
			->where('USER_ID', $senderFields['USER_ID'])
			->fetchAll()
		;

		$smtpConfirmed = $this->findReusableSmtpConfig($mailboxSenders);

		$useSsl = $mailboxConnectDTO->sslSmtp;
		$smtpConfig = [
			'server'   => $service['SMTP_SERVER'] ?: trim($mailboxConnectDTO->serverSmtp),
			'port'     => $service['SMTP_PORT'] ?: $mailboxConnectDTO->portSmtp,
			'protocol' => ($service['SMTP_ENCRYPTION'] ?: $useSsl) === 'Y' ? 'smtps' : 'smtp',
			'login'    => $service['SMTP_LOGIN_AS_IMAP'] === 'Y' ? $mailboxData['LOGIN'] : $mailboxConnectDTO->loginSmtp,
			'password' => '',
		];

		if (!empty($smtpConfirmed) && is_array($smtpConfirmed))
		{
			$smtpConfig = array_filter($smtpConfig) + $smtpConfirmed;
		}

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
		elseif ($mailboxConnectDTO->passwordSMTP !== '')
		{
			if ($this->hasBadSymbolsInPassword($mailboxConnectDTO->passwordSMTP))
			{
				$this->setError(self::SMTP_PASS_BAD_SYMBOLS_ERROR_KEY);

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
				$this->setError(self::OAUTH_ERROR_KEY);

				return null;
			}

			$smtpConfig['server'] = $hostname;

			if (!self::isValidMailHost($smtpConfig['server']))
			{
				$this->setError(self::OAUTH_ERROR_KEY);

				return null;
			}
		}

		if (empty($service['SMTP_PORT']))
		{
			if ($smtpConfig['port'] <= 0 || $smtpConfig['port'] > 65535)
			{
				$this->setError(self::OAUTH_ERROR_KEY);

				return null;
			}
		}

		$senderFields['OPTIONS']['smtp'] = $smtpConfig;

		return $senderFields;
	}

	private function findReusableSmtpConfig(array $mailboxSenders): ?array
	{
		foreach ($mailboxSenders as $sender)
		{
			$smtpOptions = $sender['OPTIONS']['smtp'] ?? null;

			if (!empty($smtpOptions['server']) && empty($smtpOptions['encrypted']))
			{
				return $smtpOptions;
			}
		}

		return null;
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
		string $syncAfterConnection,
	): array
	{
		$mailboxId = \CMailbox::add($mailboxData);
		if (!($mailboxId > 0))
		{
			addEventToStatFile('mail', 'add_mailbox', $mailboxData['SERVICE_NAME'], 'failed');

			$this->setError();

			return [];
		}

		addEventToStatFile('mail', 'add_mailbox', $mailboxData['SERVICE_NAME'], 'success');

		if (!empty($senderFields) && empty($senderFields['IS_CONFIRMED']))
		{
			$result = self::appendSender($senderFields, '', (int)$mailboxId);

			if (!empty($result['errors']) && $result['errors'] instanceof Main\ErrorCollection)
			{
				$this->addErrors($result['errors'], $isOAuth, true);

				return [];
			}

			if (!empty($result['error']))
			{
				$this->addError($result['error']);

				return [];
			}

			if (empty($result['confirmed']))
			{
				$this->addError('MAIL_CLIENT_CONFIG_SMTP_CONFIRM');

				return [];
			}
		}

		Mail\Internals\MailboxAccessTable::add([
			'MAILBOX_ID' => $mailboxId,
			'TASK_ID' => 0,
			'ACCESS_CODE' => 'U' . $mailboxData['USER_ID'],
		]);

		if (in_array('crm_connect', $mailboxData['OPTIONS']['flags'] ?? [], true))
		{
			\CMailFilter::add([
				'MAILBOX_ID' => $mailboxId,
				'NAME' => sprintf('CRM IMAP %u', $mailboxId),
				'ACTION_TYPE' => 'crm_imap',
				'WHEN_MAIL_RECEIVED' => 'Y',
				'WHEN_MANUALLY_RUN' => 'Y',
			]);
		}

		Mailbox::createInstance($mailboxId)->cacheDirs();

		$this->setSuccess();

		if ($syncAfterConnection === 'Y')
		{
			$this->syncMailbox($mailboxId);
		}

		return [
			'id' => $mailboxId,
			'email' => trim((string)$mailboxData['EMAIL']),
		];
	}

	private function hasErrors(): bool
	{
		return !empty($this->errorCollection);
	}

	private static function deleteMailboxSender(int $mailboxId, string $email): void
	{
		$sender = SenderTable::query()
			->setSelect(['ID'])
			->where('IS_CONFIRMED', true)
			->where('PARENT_MODULE_ID', 'mail')
			->where('EMAIL', $email)
			->where('PARENT_ID', $mailboxId)
			->setLimit(1)
			->fetchObject()
		;

		if ($sender)
		{
			Main\Mail\Sender::delete([$sender['ID']]);
			Main\Mail\Sender::clearCustomSmtpCache($email);
		}
	}

	/**
	 * Connect mailbox from mass connect with notifications and result saving
	 *
	 * @param MailboxMassconnectDTO $mailboxConnectDTO Mailbox connection data
	 * @param int $massConnectId ID of MailMassConnectTable entity
	 * @param int $currentUserId ID of user performing the connection
	 * @return array
	 * @throws Main\ArgumentException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 */
	public function connectMailboxFromMassconnect(
		MailboxMassconnectDTO $mailboxConnectDTO,
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

		global $USER;

		$mailbox = Mail\MailboxTable::getList(array(
			'filter' => array(
				'=ID' => $id,
				'=ACTIVE' => 'Y',
				'=SERVER_TYPE' => 'imap',
			),
		))->fetch();

		if (empty($mailbox))
		{
			$result->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTOR_REMOVE_DELETE_ERROR_NO_MAILBOX')));

			return $result;
		}

		$canManage = Mail\Helper\MailboxAccess::hasCurrentUserAccessToEditMailbox($mailbox['ID']);

		if (!$canManage)
		{
			$result->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTOR_REMOVE_DELETE_ERROR_DENIED')));

			return $result;
		}

		\CMailbox::update($mailbox['ID'], array('ACTIVE' => 'N'));

		self::deleteMailboxSender((int)$mailbox['ID'], $mailbox['EMAIL']);

		\CUserCounter::clear($USER->getId(), 'mail_unseen', $mailbox['LID']);

		$mailboxSyncManager = new \Bitrix\Mail\Helper\Mailbox\MailboxSyncManager($mailbox['USER_ID']);

		$mailboxSyncManager->deleteSyncData($mailbox['ID']);

		\CAgent::addAgent(sprintf('Bitrix\Mail\Helper::deleteMailboxAgent(%u);', $mailbox['ID']), 'mail', 'N', 60);

		return $result;
	}
}
