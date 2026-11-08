<?php

namespace Bitrix\Main\Mail;

use Bitrix\Main;
use Bitrix\Main\Error;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Mail\Internal\SenderTable;
use Bitrix\Main\Event;
use Bitrix\Main\Mail\Sender\UserSenderDataProvider;

class Sender
{
	public const MAIN_SENDER_SMTP_LIMIT_DECREASE = 'MainSenderSmtpLimitDecrease';
	public const ON_MAIL_SENDER_LIST_BUILD = 'OnMailSenderListBuild';
	public const ADDRESS_MISMATCH_ERROR = 'MAIL_SENDER_ADDRESS_MISMATCH';
	public const SENDER_UNAVAILABLE_ERROR = 'MAIL_SENDER_UNAVAILABLE';
	/**
	 * While not 'N', an identity without a usable record keeps the selection by the address of the message.
	 * The mail module sets 'N' once the records of its mailboxes are bound to them.
	 */
	public const IDENTITY_ADDRESS_FALLBACK_OPTION = 'sender_identity_address_fallback';
	private const MAIN_SENDER_SMTP_SERVER_PATTERN = '/^([a-z0-9-]+\.)+[a-z0-9-]{2,20}$/i';
	private const AMBIGUOUS_SENDER_CACHE_DIR = '/main/mail/sender_ambiguous';
	private const AMBIGUOUS_SENDER_AUDIT_TYPE = 'MAIL_SENDER_AMBIGUOUS';
	private const SMTP_CACHE_DIR = '/main/mail/smtp';
	private const SMTP_CACHE_TTL = 30 * 24 * 3600;
	private const LIMIT_CACHE_DIR = '/main/mail/limit';
	private const LIMIT_CACHE_TTL = 3600;
	private const FALLBACK_SENDER_CACHE_DIR = '/main/mail/sender_fallback';
	private const IDENTITY_SENDER_CACHE_DIR = '/main/mail/sender_identity';
	private const IDENTITY_SENDER_CACHE_TTL = 60;

	/** Short on purpose: a fallback choice must not outlive the duplicate records it was made on. */
	private const FALLBACK_SENDER_CACHE_TTL = 300;

	private static array $senderCache = [];
	private static array $smtpConfigCache = [];
	private static array $emailLimitCache = [];
	private static array $addressLimitCache = [];
	private static array $resolvedSenderIdCache = [];

	/**
	 * Per-request cache keyed by the resolved user id: consumers ask for the list several times per request.
	 * Known limitation: a signature choice saved within the same request is not reflected here,
	 * because the cached answer is returned as is. Today saving the choice and reading the
	 * signatures are separate requests; merging them would require dropping this entry on save.
	 */
	private static array $sendersWithSignaturesCache = [];

	public static function add(array $fields)
	{
		$fields['NAME'] = $fields['NAME'] ?? '';

		if (empty($fields['OPTIONS']) || !is_array($fields['OPTIONS']))
		{
			$fields['OPTIONS'] = [];
		}

		$ownerId = (int)($fields['USER_ID'] ?? 0) ?: null;
		$ownerName = Sender\UserSenderDataProvider::getUserFormattedName($ownerId);
		if (isset($fields['OPTIONS']['useSenderName']))
		{
			if ($fields['OPTIONS']['useSenderName'] && $fields['NAME'] === '')
			{
				$fields['NAME'] = $ownerName;
			}

			if (!$fields['OPTIONS']['useSenderName'])
			{
				$fields['NAME'] = '';
			}
		}

		if ($fields['NAME'] !== '')
		{
			$checkResult = self::checkSenderNameCharacters($fields['NAME']);
			if (!$checkResult->isSuccess())
			return [
				'errors' => $checkResult->getErrorCollection(),
			];
		}


		self::checkEmail($fields, $error, $errors);
		if ($error || $errors)
		{
			return array('error' => $error, 'errors' => $errors);
		}

		if (empty($fields['IS_CONFIRMED']))
		{
			$fields['OPTIONS']['confirm_code'] = \Bitrix\Main\Security\Random::getStringByCharsets(5, '0123456789abcdefghjklmnpqrstuvwxyz');
			$fields['OPTIONS']['confirm_time'] = time();
		}

		$senderId = 0;
		$result = Internal\SenderTable::add($fields);
		if ($result->isSuccess())
		{
			$senderId = $result->getId();
		}

		if (empty($fields['IS_CONFIRMED']))
		{
			$mailEventFields = array(
				'DEFAULT_EMAIL_FROM' => $fields['EMAIL'],
				'EMAIL_TO' => $fields['EMAIL'],
				'MESSAGE_SUBJECT' => Loc::getMessage('MAIN_MAIL_CONFIRM_MESSAGE_SUBJECT'),
				'CONFIRM_CODE' => mb_strtoupper($fields['OPTIONS']['confirm_code']),
			);

			$context = (new Context())->setAutomaticCustomSmtpEnabled(false);
			\CEvent::sendImmediate(
				'MAIN_MAIL_CONFIRM_CODE',
				SITE_ID,
				$mailEventFields,
				senderIdentity: null,
				context: $context,
			);
		}
		else
		{
			if (isset($fields['OPTIONS']['__replaces']) && $fields['OPTIONS']['__replaces'] > 0)
			{
				Internal\SenderTable::delete(
					(int) $fields['OPTIONS']['__replaces']
				);
			}
		}

		return ['senderId' => $senderId, 'confirmed' => !empty($fields['IS_CONFIRMED'])];
	}

	public static function updateSender(int $senderId, array $fields, bool $checkSenderAccess = true): UpdateResult
	{
		$updateFields = [];
		$result = new UpdateResult();

		$sender = self::getById($senderId);
		if (!$sender)
		{
			$result->addError(new Error(Loc::getMessage('MAIN_MAIL_SENDER_UNKNOWN_SENDER_ERROR')));

			return $result;
		}

		if ($checkSenderAccess)
		{
			$checkResult = self::canEditSender($senderId);
			if (!$checkResult->isSuccess())
			{
				$result->addErrors($checkResult->getErrors());

				return $result;
			}
		}

		if (is_string($fields['EMAIL']) && $fields['EMAIL'] !== $sender['EMAIL'])
		{
			$updateFields['EMAIL'] = $fields['EMAIL'];
		}

		if (isset($fields['IS_PUBLIC']) && (int)$fields['IS_PUBLIC'] !== (int)$sender['IS_PUBLIC'])
		{
			$updateFields['IS_PUBLIC'] = (int)$fields['IS_PUBLIC'] === 1 ? 1 : 0;
		}

		if (isset($fields['OPTIONS']['smtp']) && empty($fields['OPTIONS']['smtp']['password']))
		{
			$fields['OPTIONS']['smtp']['password'] = $sender['OPTIONS']['smtp']['password'];
		}
		if (
			!empty($fields['OPTIONS']['smtp'])
			&& $fields['OPTIONS']['smtp'] !== $sender['OPTIONS']['smtp']
		)
		{
			$smtp = $fields['OPTIONS']['smtp'];
			$checkResult = self::prepareSmtpConfigForSender($smtp);
			if (!$checkResult->isSuccess())
			{
				$result->addErrors($checkResult->getErrors());

				return $result;
			}
			$sender['OPTIONS']['smtp'] = $smtp;
			$updateFields['OPTIONS'] = $sender['OPTIONS'];
		}

		if (isset($fields['OPTIONS']['useSenderName']))
		{
			$sender['OPTIONS']['useSenderName'] = (bool)$fields['OPTIONS']['useSenderName'];
			$updateFields['OPTIONS'] = $sender['OPTIONS'];

			if (
				$sender['OPTIONS']['useSenderName']
				&& (!is_string($fields['NAME']) || $fields['NAME'] === '')
				&& ($sender['NAME'] ?? '') === ''
			)
			{
				$fields['NAME'] = Sender\UserSenderDataProvider::getUserFormattedName((int)$sender['USER_ID']);
			}

			if (!$sender['OPTIONS']['useSenderName'])
			{
				$fields['NAME'] = '';
			}
		}

		if (is_string($fields['NAME']) && $fields['NAME'] !== $sender['NAME'])
		{
			$name = $fields['NAME'];
			$checkResult = self::checkSenderNameCharacters($name);
			if (!$checkResult->isSuccess())
			{
				$result->addErrors($checkResult->getErrors());

				return $result;
			}

			if ($sender['PARENT_MODULE_ID'] === 'mail' && Main\Loader::includeModule('mail'))
			{
				$result = \Bitrix\Mail\MailboxTable::update($sender['PARENT_ID'], ['USERNAME' => $name]);
				if (!$result->isSuccess())
				{
					return $result;
				}
			}
			$updateFields['NAME'] = $name;
		}

		if (!empty($updateFields))
		{
			// the limit travels inside OPTIONS and stays within this record
			$result = Internal\SenderTable::update($senderId, $updateFields);
		}

		return $result;
	}

	/**
	 * Check smtp connection
	 * @param $fields
	 * @param null $error
	 * @param Main\ErrorCollection|null $errors
	 */
	public static function checkEmail(&$fields, &$error = null, ?Main\ErrorCollection &$errors = null)
	{

		if (empty($fields['IS_CONFIRMED']) && !empty($fields['OPTIONS']['smtp']))
		{
			$smtpConfig = $fields['OPTIONS']['smtp'];
			$smtpConfig = new Smtp\Config(array(
				'from' => $fields['EMAIL'],
				'host' => $smtpConfig['server'],
				'port' => $smtpConfig['port'],
				'protocol' => $smtpConfig['protocol'],
				'login' => $smtpConfig['login'],
				'password' => $smtpConfig['password'],
				'isOauth' => $smtpConfig['isOauth'] ?? false,
			));

			if ($smtpConfig->canCheck())
			{
				if ($smtpConfig->check($error, $errors))
				{
					$fields['IS_CONFIRMED'] = true;
				}
			}
		}
	}

	public static function confirm($ids)
	{
		if (!empty($ids))
		{
			$res = Internal\SenderTable::getList(array(
				'filter' => array(
					'@ID' => (array) $ids,
				),
			));

			while ($item = $res->fetch())
			{
				Internal\SenderTable::update(
					(int) $item['ID'],
					array(
						'IS_CONFIRMED' => true,
					)
				);

				if (isset($item['OPTIONS']['__replaces']) && $item['OPTIONS']['__replaces'] > 0)
				{
					Internal\SenderTable::delete(
						(int) $item['OPTIONS']['__replaces']
					);
				}
			}
		}
	}

	public static function deleteSenderByMailboxId(int $mailboxId): void
	{
		if(!Main\Loader::includeModule('mail'))
		{
			return;
		}

		$sender = Internal\SenderTable::getList([
			'filter' => [
				'=PARENT_MODULE_ID' => 'mail',
				'=PARENT_ID' => $mailboxId,
			],
		])->fetch();

		if ($sender)
		{
			self::delete([(int)$sender['ID']]);
		}
	}

	public static function delete(array $senderIds): void
	{
		foreach ($senderIds as $senderId)
		{
			$id = (int)$senderId;
			$currentSender = Internal\SenderTable::getById($id)->fetch();

			if (!$currentSender)
			{
				continue;
			}

			$result = Internal\SenderTable::delete($id);
			if (!$result->isSuccess())
			{
				continue;
			}

			$aliasesForPossibleDeletion = [];
			if (!empty($currentSender['OPTIONS']['smtp']['server']) && empty(self::getPublicSmtpSenderByEmail($currentSender['EMAIL'], $id)) && $currentSender['USER_ID'])
			{
				$res = \Bitrix\Main\Mail\Internal\SenderTable::getList([
					'filter' => [
						'=EMAIL' => $currentSender['EMAIL'],
						'=USER_ID' => $currentSender['USER_ID'],
					],
				]);

				while ($sender = $res->fetch())
				{
					$aliasesForPossibleDeletion[$sender['USER_ID']][] = $sender;
				}
			}

			if (!$aliasesForPossibleDeletion)
			{
				continue;
			}

			foreach ($aliasesForPossibleDeletion as $userId => $aliases)
			{
				if (self::hasUserAvailableSmtpSenderByEmail($currentSender['EMAIL'], $userId, true))
				{
					continue;
				}

				foreach ($aliases as $alias)
				{
					SenderTable::delete($alias['ID']);
				}
			}
		}
	}

	/**
	 * Drops every cache built around the address: the address fallback plus the caches of all sender
	 * records the address currently has.
	 */
	public static function clearCustomSmtpCache($email)
	{
		$email = self::normalizeEmail(is_string($email) ? $email : null);
		if ($email === null)
		{
			return;
		}

		self::clearAddressCache($email);

		foreach (self::getByEmail($email) as $sender)
		{
			self::clearSenderCache((int)$sender['ID']);
		}
	}

	/**
	 * Drops the caches of a single sender record. Called by the data layer on every change of the
	 * record, so that neither the configuration nor the limit outlives it.
	 */
	public static function clearSenderCache(int $senderId, ?string $email = null): void
	{
		$cache = new \CPHPCache();
		$cacheKey = self::getSenderCacheKey($senderId);

		$cache->clean($cacheKey, self::SMTP_CACHE_DIR);
		$cache->clean($cacheKey, self::LIMIT_CACHE_DIR);

		unset(
			self::$senderCache[$senderId],
			self::$smtpConfigCache[$senderId],
			self::$emailLimitCache[$senderId]
		);

		self::$resolvedSenderIdCache = [];

		$email = self::normalizeEmail($email);
		if ($email !== null)
		{
			self::clearAddressCache($email);
		}
	}

	public static function clearIdentitySenderCache(
		int $senderId,
		?string $parentModuleId = null,
		?int $parentId = null,
	): void
	{
		$cache = new \CPHPCache();
		if ($senderId > 0)
		{
			self::cleanIdentitySenderCacheKey($cache, self::getIdentitySenderCacheKey($senderId));
		}

		if ($parentModuleId !== null && $parentModuleId !== '' && $parentId !== null && $parentId > 0)
		{
			self::cleanIdentitySenderCacheKey(
				$cache,
				self::getIdentityMailboxCacheKey($parentModuleId, $parentId),
			);
		}

		self::$resolvedSenderIdCache = [];
	}

	/**
	 * Drops the caches built around the address itself: the fallback choice and the limit of the
	 * address, which is derived from all its records at once.
	 */
	private static function clearAddressCache(string $email): void
	{
		$cache = new \CPHPCache();

		$cache->clean($email, self::FALLBACK_SENDER_CACHE_DIR);
		$cache->clean(self::getAddressCacheKey($email), self::LIMIT_CACHE_DIR);

		self::$resolvedSenderIdCache = [];
		unset(self::$addressLimitCache[$email]);
	}

	private static function getSenderCacheKey(int $senderId): string
	{
		return 'sender_' . $senderId;
	}

	private static function getAddressCacheKey(string $email): string
	{
		return 'address_' . $email;
	}

	public static function getById(int $senderId): ?array
	{
		if (!$senderId)
		{
			return null;
		}

		if (array_key_exists($senderId, self::$senderCache))
		{
			return self::$senderCache[$senderId];
		}

		$row = Internal\SenderTable::getById($senderId)->fetch();
		self::$senderCache[$senderId] = $row ?: null;

		return self::$senderCache[$senderId];
	}

	public static function getByParentId(int $parentId, string $parentModuleId = 'mail'): array
	{
		return Internal\SenderTable::query()
			->setSelect(['*'])
			->where('PARENT_MODULE_ID', $parentModuleId)
			->where('PARENT_ID', $parentId)
			->fetchAll()
		;
	}

	public static function getByEmail(string $email, ?int $userId = null): array
	{
		$query = SenderTable::query()
			->setSelect(['*'])
			->where('EMAIL', Address::normalizeEmail($email))
		;

		if ($userId)
		{
			$query->where('USER_ID', $userId);
		}

		return $query->fetchAll();
	}

	/**
	 * Returns the stable identity of the first usable sender owned by the user for the exact address.
	 */
	public static function resolveIdentityForOwner(int $ownerId, string $email): ?Sender\Identity
	{
		$sender = (new Sender\IdentityResolver())->resolveForOwner($ownerId, $email);
		if ($sender === null)
		{
			return null;
		}

		if ($sender['PARENT_MODULE_ID'] === 'mail' && (int)$sender['PARENT_ID'] > 0)
		{
			return Sender\Identity::fromMailbox((int)$sender['PARENT_ID']);
		}

		return Sender\Identity::fromSender((int)$sender['ID']);
	}

	public static function getCustomSmtp($email, ?Sender\Identity $identity = null)
	{
		$senderId = self::resolveSenderId($identity, is_string($email) ? $email : null);
		if ($senderId <= 0)
		{
			return false;
		}

		if (!array_key_exists($senderId, self::$smtpConfigCache))
		{
			$options = self::getSenderSmtpOptions($senderId);

			self::$smtpConfigCache[$senderId] = $options
				? self::createSmtpConfig($options['EMAIL'], $options['smtp'])
				: false
			;
		}

		return self::$smtpConfigCache[$senderId];
	}

	/**
	 * Resolves the identity into the identifier of the sender record the message is sent through.
	 * Without an identity the address fallback is used, and its result is cached separately.
	 *
	 * @return int Sender record identifier, zero when nothing matched.
	 */
	public static function resolveSenderId(?Sender\Identity $identity, ?string $email): int
	{
		$email = self::normalizeEmail($email);
		$memoKey = self::getResolveMemoKey($identity, $email);

		if (!array_key_exists($memoKey, self::$resolvedSenderIdCache))
		{
			self::$resolvedSenderIdCache[$memoKey] = $identity === null
				? self::resolveFallbackSenderId($email)
				: self::resolveIdentitySenderId($identity)
			;
		}

		return self::$resolvedSenderIdCache[$memoKey];
	}

	private static function resolveIdentitySenderId(Sender\Identity $identity): int
	{
		if ($identity->hasMailboxRef())
		{
			$senderId = self::resolveIdentityCoordinate(
				self::getIdentityMailboxCacheKey($identity->mailboxModuleId, $identity->mailboxParentId),
				Sender\Identity::fromMailbox($identity->mailboxParentId, $identity->mailboxModuleId),
			);
			if ($senderId > 0)
			{
				return $senderId;
			}
		}

		if ($identity->hasSenderRef())
		{
			return self::resolveIdentityCoordinate(
				self::getIdentitySenderCacheKey($identity->senderId),
				Sender\Identity::fromSender($identity->senderId),
			);
		}

		return 0;
	}

	private static function resolveIdentityCoordinate(string $cacheKey, Sender\Identity $identity): int
	{
		$cache = new \CPHPCache();
		if ($cache->initCache(self::IDENTITY_SENDER_CACHE_TTL, $cacheKey, self::IDENTITY_SENDER_CACHE_DIR))
		{
			return (int)$cache->getVars();
		}

		$senderId = (int)(self::resolveSender($identity, null)['ID'] ?? 0);
		if ($senderId > 0 && $cache->startDataCache())
		{
			$cache->endDataCache($senderId);
		}

		return $senderId;
	}

	private static function cleanIdentitySenderCacheKey(\CPHPCache $cache, string $cacheKey): void
	{
		$cache->clean($cacheKey, self::IDENTITY_SENDER_CACHE_DIR);
	}

	private static function getIdentitySenderCacheKey(int $senderId): string
	{
		return 'sender_' . $senderId;
	}

	private static function getIdentityMailboxCacheKey(string $parentModuleId, int $parentId): string
	{
		return 'mailbox_' . md5($parentModuleId) . '_' . $parentId;
	}

	private static function resolveFallbackSenderId(?string $email): int
	{
		if ($email === null)
		{
			return 0;
		}

		$cache = new \CPHPCache();
		if ($cache->initCache(self::FALLBACK_SENDER_CACHE_TTL, $email, self::FALLBACK_SENDER_CACHE_DIR))
		{
			return (int)$cache->getVars();
		}

		$senderId = (int)(self::resolveSender(null, $email)['ID'] ?? 0);

		$cache->startDataCache();
		$cache->endDataCache($senderId);

		return $senderId;
	}

	private static function getResolveMemoKey(?Sender\Identity $identity, ?string $email): string
	{
		return implode('|', [
			$identity?->mailboxModuleId ?? '',
			$identity?->mailboxParentId ?? 0,
			$identity?->senderId ?? 0,
			$email ?? '',
		]);
	}

	/**
	 * @return array|null Address and smtp options of the record, or null when it has no configuration.
	 */
	private static function getSenderSmtpOptions(int $senderId): ?array
	{
		$cache = new \CPHPCache();
		if ($cache->initCache(self::SMTP_CACHE_TTL, self::getSenderCacheKey($senderId), self::SMTP_CACHE_DIR))
		{
			return $cache->getVars() ?: null;
		}

		$sender = self::getById($senderId);
		$options = empty($sender['OPTIONS']['smtp']['server'])
			? null
			: [
				'EMAIL' => $sender['EMAIL'],
				'smtp' => $sender['OPTIONS']['smtp'],
			]
		;

		$cache->startDataCache();
		$cache->endDataCache($options ?? false);

		return $options;
	}

	private static function normalizeEmail(?string $email): ?string
	{
		if ($email === null || $email === '')
		{
			return null;
		}

		$address = new Address($email);

		return $address->validate() ? $address->getEmail() : null;
	}

	/**
	 * Resolves the sender record the message is sent through and reports ambiguity to the event log.
	 */
	private static function resolveSender(?Sender\Identity $identity, ?string $email): ?array
	{
		$isAmbiguous = false;
		$sender = (new Sender\IdentityResolver())->resolve($identity, $email, $isAmbiguous);

		if ($sender && $isAmbiguous)
		{
			self::logAmbiguousSender($sender['EMAIL'], (int)$sender['ID']);
		}

		return $sender;
	}

	/**
	 * One record a day per address is enough: without deduplication every message would produce an entry.
	 */
	private static function logAmbiguousSender(string $email, int $senderId): void
	{
		$cache = new \CPHPCache();
		if ($cache->initCache(86400, $email, self::AMBIGUOUS_SENDER_CACHE_DIR))
		{
			return;
		}

		$cache->startDataCache();
		$cache->endDataCache(true);

		(new Main\Diag\EventLogger('main', self::AMBIGUOUS_SENDER_AUDIT_TYPE))->warning(
			'Address {email} belongs to several senders, sender #{senderId} is used for outgoing mail.',
			[
				'email' => $email,
				'senderId' => $senderId,
			]
		);
	}

	private static function createSmtpConfig(string $email, array $smtp)
	{
		$config = new Smtp\Config(array(
			'from' => $email,
			'host' => $smtp['server'],
			'port' => $smtp['port'],
			'protocol' => $smtp['protocol'],
			'login' => $smtp['login'],
			'password' => $smtp['password'],
			'isOauth' => $smtp['isOauth'],
		));

		// config will be replaced with null value due errors
		return (new Main\Mail\Smtp\OAuthConfigPreparer())->prepareBeforeSendIfNeed($config);
	}

	/**
	 * get sending limit of the sender the message is sent through, returns null if no limit.
	 * A named sender is limited by its own record only. Without an identity the record is picked by
	 * the address, so the address as a whole is limited by the strictest of its records: a limit set
	 * by the administrator must not disappear because the fallback landed on a neighbour record.
	 * @param $email
	 * @param Sender\Identity|null $identity Sender identity, when the caller knows it.
	 * @return int|null
	 * @throws Main\ArgumentException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 */
	public static function getEmailLimit($email, ?Sender\Identity $identity = null): ?int
	{
		$email = self::normalizeEmail(is_string($email) ? $email : null);

		$limit = $identity === null
			? self::getAddressLimit($email)
			: self::getSenderLimit(self::resolveSenderId($identity, $email))
		;

		return $limit !== null && $limit < 0 ? 0 : $limit;
	}

	private static function getSenderLimit(int $senderId): ?int
	{
		if ($senderId <= 0)
		{
			return null;
		}

		if (!array_key_exists($senderId, self::$emailLimitCache))
		{
			self::$emailLimitCache[$senderId] = self::readSenderLimit($senderId);
		}

		return self::$emailLimitCache[$senderId];
	}

	private static function getAddressLimit(?string $email): ?int
	{
		if ($email === null)
		{
			return null;
		}

		if (!array_key_exists($email, self::$addressLimitCache))
		{
			self::$addressLimitCache[$email] = self::readAddressLimit($email);
		}

		return self::$addressLimitCache[$email];
	}

	/**
	 * The smallest positive limit among the confirmed records of the address. A record participates whatever
	 * the state of its smtp configuration: the limit is a restriction of the address, not a property
	 * of a working transport.
	 */
	private static function readAddressLimit(string $email): ?int
	{
		$cache = new \CPHPCache();
		if ($cache->initCache(self::LIMIT_CACHE_TTL, self::getAddressCacheKey($email), self::LIMIT_CACHE_DIR))
		{
			$cached = $cache->getVars();

			return $cached === null || $cached === false ? null : (int)$cached;
		}

		$limit = null;
		$res = Internal\SenderTable::getList([
			'select' => ['OPTIONS'],
			'filter' => [
				'=IS_CONFIRMED' => true,
				'=EMAIL' => $email,
			],
		]);

		while ($item = $res->fetch())
		{
			if (!isset($item['OPTIONS']['smtp']['limit']))
			{
				continue;
			}

			$senderLimit = (int)$item['OPTIONS']['smtp']['limit'];
			if ($senderLimit <= 0)
			{
				continue;
			}

			$limit = $limit === null ? $senderLimit : min($limit, $senderLimit);
		}

		$cache->startDataCache();
		$cache->endDataCache($limit ?? false);

		return $limit;
	}

	private static function readSenderLimit(int $senderId): ?int
	{
		$cache = new \CPHPCache();
		if ($cache->initCache(self::LIMIT_CACHE_TTL, self::getSenderCacheKey($senderId), self::LIMIT_CACHE_DIR))
		{
			$cached = $cache->getVars();

			return $cached === null || $cached === false ? null : (int)$cached;
		}

		$sender = self::getById($senderId);
		$limit = isset($sender['OPTIONS']['smtp']['limit'])
			? (int)$sender['OPTIONS']['smtp']['limit']
			: null
		;

		$cache->startDataCache();
		$cache->endDataCache($limit ?? false);

		return $limit;
	}

	/**
	 * Set the limit of a single sender record, leaving the records of other owners with the same
	 * address untouched.
	 * Returns true if the limit was changed.
	 * @param int $senderId
	 * @param int $limit
	 * @return bool
	 */
	public static function setSenderLimit(int $senderId, int $limit): bool
	{
		$sender = self::getById($senderId);
		if (!$sender || empty($sender['OPTIONS']['smtp']))
		{
			return false;
		}

		$limit = max($limit, 0);
		if ((int)($sender['OPTIONS']['smtp']['limit'] ?? 0) === $limit)
		{
			return false;
		}

		$options = $sender['OPTIONS'];
		$options['smtp']['limit'] = $limit;

		return Internal\SenderTable::update($senderId, ['OPTIONS' => $options])->isSuccess();
	}

	/**
	 * Set sender limit by email. Finding all senders with same email and set up limit from option
	 * Returns true if change some email limit.
	 * Returns false if has no changes.
	 * The caches of the affected records are dropped by the data layer.
	 * @param string $email
	 * @param int $limit
	 * @return bool
	 * @throws Main\ArgumentException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 */
	public static function setEmailLimit(string $email, int $limit, bool $quite = true): bool
	{
		$address = new \Bitrix\Main\Mail\Address($email);

		if (!$address->validate())
		{
			return false;
		}

		$email = $address->getEmail();

		$res = Internal\SenderTable::getList(array(
			'filter' => array(
				'IS_CONFIRMED' => true,
				'=EMAIL' => $email,
			),
			'order' => array(
				'ID' => 'DESC',
			),
		));

		if ($limit < 0)
		{
			$limit = 0;
		}

		$hasChanges = false;
		while ($item = $res->fetch())
		{
			$oldLimit = (int)($item['OPTIONS']['smtp']['limit'] ?? 0);
			if ($item['OPTIONS']['smtp'] && $limit !== $oldLimit)
			{
				$item['OPTIONS']['smtp']['limit'] = $limit;
				$updateResult = Internal\SenderTable::update($item['ID'], ['OPTIONS' => $item['OPTIONS']]);
				$hasChanges = true;
				if (!$quite && ($limit < $oldLimit || $oldLimit <= 0) && $updateResult->isSuccess())
				{
					$event = new Event('main', self::MAIN_SENDER_SMTP_LIMIT_DECREASE, ['EMAIL'=>$email]);
					$event->send();
				}
			}
		}

		return $hasChanges;
	}

	/**
	 * Remove limit from all connected senders.
	 * The caches of the affected records are dropped by the data layer.
	 * @param string $email
	 * @return bool
	 * @throws Main\ArgumentException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 */
	public static function removeEmailLimit(string $email): bool
	{
		$address = new \Bitrix\Main\Mail\Address($email);

		if (!$address->validate())
		{
			return false;
		}

		$email = $address->getEmail();

		$res = Internal\SenderTable::getList(array(
			'filter' => array(
				'IS_CONFIRMED' => true,
				'=EMAIL' => $email,
			),
			'order' => array(
				'ID' => 'DESC',
			),
		));

		while ($item = $res->fetch())
		{
			if (isset($item['OPTIONS']['smtp']['limit']))
			{
				unset($item['OPTIONS']['smtp']['limit']);
				Internal\SenderTable::update($item['ID'], ['OPTIONS' => $item['OPTIONS']]);
			}
		}

		return true;
	}

	public static function applyCustomSmtp($event)
	{
		$arguments = $event->getParameter('arguments');
		$headers = $arguments->additional_headers;
		$context = $arguments->context;

		if (empty($context) || !($context instanceof Context))
		{
			return;
		}

		if ($context->getAutomaticCustomSmtpEnabled() === false)
		{
			return;
		}

		$identity = $context->getSenderIdentity();
		$address = self::extractFromAddress($headers);

		$customSmtp = $identity !== null
			? static::getCustomSmtp($address?->getEmail(), $identity)
			: false
		;
		if (!$customSmtp && $identity !== null && !self::isIdentityAddressFallbackEnabled())
		{
			// the address may belong to another owner's record, so the portal transport is used
			return;
		}

		if ($customSmtp)
		{
			$resolvedEmail = $customSmtp->getFrom();
			if (
				$address === null
				|| !$resolvedEmail
				|| strcasecmp($address->getEmail(), $resolvedEmail) !== 0
			)
			{
				return self::rejectSending(
					$context,
					self::ADDRESS_MISMATCH_ERROR,
					Loc::getMessage('MAIN_MAIL_SENDER_ADDRESS_MISMATCH_ERROR'),
					[
						'messageAddress' => $address?->getEmail(),
						'senderAddress' => $resolvedEmail,
					],
				);
			}

			if (
				($context->getSmtp() && $context->getSmtp()->getHost())
				|| preg_match('/X-Bitrix-Mail-SMTP-Host:/i', $headers)
			)
			{
				return;
			}

			$context->setSmtp($customSmtp);

			return;
		}

		if (
			$address === null
			|| ($context->getSmtp() && $context->getSmtp()->getHost())
			|| preg_match('/X-Bitrix-Mail-SMTP-Host:/i', $headers)
		)
		{
			return;
		}

		$customSmtp = static::getCustomSmtp($address->getEmail());
		if ($customSmtp)
		{
			$context->setSmtp($customSmtp);
		}
	}

	private static function isIdentityAddressFallbackEnabled(): bool
	{
		return Main\Config\Option::get('main', self::IDENTITY_ADDRESS_FALLBACK_OPTION, 'Y') !== 'N';
	}

	private static function rejectSending(
		Context $context,
		string $code,
		string $message,
		array $customData = [],
	): Main\EventResult
	{
		$error = new Error($message, $code, $customData);
		$context->setSendingError($error);

		return new Main\EventResult(Main\EventResult::ERROR, ['error' => $error]);
	}

	private static function extractFromAddress($headers): ?Address
	{
		$value = self::extractHeaderValue($headers, 'From');
		if ($value === null)
		{
			return null;
		}

		$address = new Address($value);

		return $address->validate() ? $address : null;
	}

	private static function extractHeaderValue(string $headers, string $name): ?string
	{
		if (!preg_match(self::getHeaderPattern($name), $headers, $matches))
		{
			return null;
		}

		return preg_replace(sprintf('/%s\s+/', self::getEolPattern()), '', $matches[2]);
	}

	private static function getHeaderPattern(string $name): string
	{
		return sprintf(
			'/(^|%1$s)%2$s:(.+?)(%1$s([^\s]|$)|$)/is',
			self::getEolPattern(),
			preg_quote($name, '/')
		);
	}

	private static function getEolPattern(): string
	{
		return preg_replace('/([a-f0-9]{2})/i', '\x\1', bin2hex(Mail::getMailEol()));
	}

	public static function prepareUserMailboxes($userId = null)
	{
		global $USER;

		static $mailboxes = array();

		if (!($userId > 0))
		{
			if (is_object($USER) && $USER->isAuthorized())
			{
				$userId = $USER->getId();
			}
		}

		if (!($userId > 0))
		{
			return array();
		}

		return UserSenderDataProvider::getUserAvailableSenders($userId);
	}

	/**
	 * Returns the sender list enriched with signatures provided by subscribers of ON_MAIL_SENDER_LIST_BUILD.
	 * The sender key is opaque for the handlers: it is taken from the `formated` field as is.
	 *
	 * @return array{
	 *     senders: array<int, array<string, mixed>>,
	 *     signatures: array<int, array{id: int, signature: string, scope: string, senderTexts: array<string, string>}>,
	 *     signaturesBySender: array<string, array{availableSignatureIds: int[], selectedSignatureId: int|null}>
	 * }
	 */
	public static function prepareUserMailboxesWithSignatures(?int $userId = null): array
	{
		$signatureUserId = self::resolveSignatureRequestUserId($userId);

		if ($signatureUserId <= 0)
		{
			return self::attachSignaturesToSenders(self::prepareUserMailboxes($userId), $signatureUserId);
		}

		return self::$sendersWithSignaturesCache[$signatureUserId] ??= self::attachSignaturesToSenders(
			self::prepareUserMailboxes($signatureUserId),
			$signatureUserId
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $senders
	 */
	private static function attachSignaturesToSenders(array $senders, int $userId): array
	{
		$result = [
			'senders' => $senders,
			'signatures' => [],
			'signaturesBySender' => [],
		];

		if ($userId <= 0 || empty($senders))
		{
			return $result;
		}

		$eventSenders = self::prepareSignatureEventSenders($senders);
		if (empty($eventSenders))
		{
			return $result;
		}

		try
		{
			$event = new Event(
				'main',
				self::ON_MAIL_SENDER_LIST_BUILD,
				[
					'userId' => $userId,
					'senders' => array_values($eventSenders),
				]
			);
			$event->send();

			$merged = self::mergeSignatureEventResults($event->getResults(), array_keys($eventSenders));
		}
		catch (\Throwable)
		{
			return $result;
		}

		$result['signatures'] = $merged['signatures'];
		$result['signaturesBySender'] = $merged['signaturesBySender'];

		return $result;
	}

	private static function resolveSignatureRequestUserId(?int $userId): int
	{
		global $USER;

		if ($userId > 0)
		{
			return $userId;
		}

		if (is_object($USER) && $USER->isAuthorized())
		{
			return (int)$USER->getId();
		}

		return 0;
	}

	/**
	 * @param array<int, array<string, mixed>> $senders
	 * @return array<string, array<string, mixed>> sender key => event payload item
	 */
	private static function prepareSignatureEventSenders(array $senders): array
	{
		$eventSenders = [];

		foreach ($senders as $sender)
		{
			if (!is_array($sender))
			{
				continue;
			}

			$key = (string)($sender['formated'] ?? '');
			if ($key === '' || isset($eventSenders[$key]))
			{
				continue;
			}

			$eventSenders[$key] = [
				'key' => $key,
				'email' => (string)($sender['email'] ?? ''),
				'name' => (string)($sender['name'] ?? ''),
				'mailboxId' => (int)($sender['mailboxId'] ?? 0),
			];
		}

		return $eventSenders;
	}

	/**
	 * @param \Bitrix\Main\EventResult[] $results
	 * @param string[] $knownSenderKeys
	 * @return array{signatures: array<int, array<string, mixed>>, signaturesBySender: array<string, array<string, mixed>>}
	 */
	private static function mergeSignatureEventResults(array $results, array $knownSenderKeys): array
	{
		$knownSenderKeys = array_flip($knownSenderKeys);
		$signatures = [];
		$signaturesBySender = [];

		foreach ($results as $eventResult)
		{
			if (!($eventResult instanceof Main\EventResult) || $eventResult->getType() !== Main\EventResult::SUCCESS)
			{
				continue;
			}

			$parameters = $eventResult->getParameters();
			if (!is_array($parameters))
			{
				continue;
			}

			foreach ((array)($parameters['signatures'] ?? []) as $signature)
			{
				if (!is_array($signature) || !isset($signature['id']))
				{
					continue;
				}

				$signatureId = (int)$signature['id'];
				if ($signatureId <= 0 || isset($signatures[$signatureId]))
				{
					continue;
				}

				$signature['id'] = $signatureId;
				$signatures[$signatureId] = $signature;
			}

			foreach ((array)($parameters['senders'] ?? []) as $senderKey => $senderSignatures)
			{
				$senderKey = (string)$senderKey;
				if (!is_array($senderSignatures) || !isset($knownSenderKeys[$senderKey]))
				{
					continue;
				}

				$signaturesBySender[$senderKey] ??= [
					'availableSignatureIds' => [],
					'selectedSignatureId' => null,
				];

				foreach ((array)($senderSignatures['availableSignatureIds'] ?? []) as $signatureId)
				{
					$signatureId = (int)$signatureId;
					if (
						$signatureId > 0
						&& !in_array($signatureId, $signaturesBySender[$senderKey]['availableSignatureIds'], true)
					)
					{
						$signaturesBySender[$senderKey]['availableSignatureIds'][] = $signatureId;
					}
				}

				$selectedSignatureId = $senderSignatures['selectedSignatureId'] ?? null;
				if (
					$signaturesBySender[$senderKey]['selectedSignatureId'] === null
					&& $selectedSignatureId !== null
				)
				{
					$signaturesBySender[$senderKey]['selectedSignatureId'] = (int)$selectedSignatureId;
				}
			}
		}

		return [
			'signatures' => array_values($signatures),
			'signaturesBySender' => $signaturesBySender,
		];
	}

	public static function prepareSmtpConfigForSender(array &$smtp): Main\Result
	{
		$result = new Main\Result();

		if (!empty($smtp['limit']))
		{
			$limit = (int)$smtp['limit'];
			$limit = max($limit, 0);
		}

		$smtp['protocol'] = self::isSmtpsConfigured($smtp) ? 'smtps' : 'smtp';

		$smtp = [
			'server' => mb_strtolower(trim($smtp['server'] ?? '')),
			'port' => (int)($smtp['port'] ?? 0),
			'protocol' => $smtp['protocol'],
			'login' => $smtp['login'] ?? '',
			'password' => $smtp['password'] ?? '',
			'isOauth' => (bool)$smtp['isOauth'] ?? false,
			'limit' => $limit ?? null,
		];

		if (!preg_match(self::MAIN_SENDER_SMTP_SERVER_PATTERN, $smtp['server']))
		{
			$message = Loc::getMessage(
				empty($smtp['server'])
					? 'MAIN_SENDER_EMPTY_SMTP_SERVER'
					: 'MAIN_SENDER_INVALID_SMTP_SERVER'
			);

			return $result->addError(new Error($message));
		}

		if (!preg_match('/^[0-9]+$/i', $smtp['port']) || $smtp['port'] < 1 || $smtp['port'] > 65535)
		{
			$errorMessage = Loc::getMessage(
				empty($smtp['port'])
					? 'MAIN_SENDER_EMPTY_SMTP_PORT'
					: 'MAIN_SENDER_INVALID_SMTP_PORT'
			);

			return $result->addError(new Error($errorMessage));
		}

		if (empty($smtp['login']))
		{
			$errorMessage = Loc::getMessage('MAIN_SENDER_EMPTY_SMTP_LOGIN');

			return $result->addError(new Error($errorMessage));
		}

		if (empty($smtp['password']))
		{
			$errorMessage = Loc::getMessage('MAIN_MAIL_CONFIRM_EMPTY_SMTP_PASSWORD');

			return $result->addError(new Error($errorMessage));
		}

		if (preg_match('/^\^/', $smtp['password']))
		{
			$errorMessage = Loc::getMessage('MAIN_SENDER_INVALID_SMTP_PASSWORD');

			return $result->addError(new Error($errorMessage));
		}

		$smtpConfig = new Smtp\Config([
			'from' => $smtp['login'],
			'host' => $smtp['server'],
			'port' => $smtp['port'],
			'protocol' => $smtp['protocol'],
			'login' => $smtp['login'],
			'password' => $smtp['password'],
			'isOauth' => $smtp['isOauth'],
		]);

		if ($smtpConfig->canCheck())
		{
			$smtpConfig->check($error, $errors);
		}

		if (!empty($error))
		{
			$result->addError(new Error($error));
		}
		else if (!empty($errors) && $errors instanceof Main\ErrorCollection)
		{
			$result->addErrors($errors->toArray());
		}

		return $result;
	}

	/**
	 * checks if the user has a non-mailbox sender with the given email
	*/
	public static function hasUserSenderWithEmail(string $email, ?int $userId = null): bool
	{
		$userId = $userId ?: (int)CurrentUser::get()->getId();
		if (!$userId)
		{
			return false;
		}

		$filter = [
			'=IS_CONFIRMED' => true,
			'=EMAIL' => Address::normalizeEmail($email),
			'=USER_ID' => $userId,
			'=PARENT_MODULE_ID' => 'main',
		];

		$res = Internal\SenderTable::getList([
			'filter' => $filter,
		]);

		while ($item = $res->fetch())
		{
			if (!empty($item['OPTIONS']['smtp']['server']))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * checks if the user can edit sender
	 */
	public static function canEditSender(int $senderId): Main\Result
	{
		$result = new Main\Result();

		$sender = self::getById($senderId);
		if (!$sender)
		{
			$result->addError(new Error(Loc::getMessage('MAIN_MAIL_SENDER_UNKNOWN_SENDER_ERROR')));

			return $result;
		}

		$userId = (int)CurrentUser::get()->getId();
		if (!$userId)
		{
			$result->addError(new Error('User is not authorized'));

			return $result;
		}

		if (
			(int)$sender['USER_ID'] !== $userId
			&& !UserSenderDataProvider::isAdmin()
		)
		{
			$result->addError(new Error(Loc::getMessage('MAIN_MAIL_SENDER_EDIT_ERROR')));
		}

		return $result;
	}

	/**
	 * get first public sender with smtp-server settings, one sender can be excluded by id
	 */
	public static function 	getPublicSmtpSenderByEmail(string $email, ?int $senderId = null, bool $onlyWithSmtp = true): ?int
	{
		$filter = [
			'=IS_CONFIRMED' => true,
			'=EMAIL' => Address::normalizeEmail($email),
			'=IS_PUBLIC' => true,
			'!=ID' => $senderId,
		];

		$res = Internal\SenderTable::getList([
			'filter' => $filter,
		]);

		while ($item = $res->fetch()) {
			if (
				(!empty($item['OPTIONS']['smtp']['server'])  && empty($item['OPTIONS']['smtp']['encrypted']))
				|| !$onlyWithSmtp
			)
			{
				return $item['ID'];
			}
		}

		return null;
	}

	public static function hasUserAvailableSmtpSenderByEmail(string $email, int $userId, bool $onlyWithSmtp = false): bool
	{
		// the stored address is normalized, so a lookup by the address of the caller has to be too
		$email = Address::normalizeEmail($email);

		if (self::getPublicSmtpSenderByEmail($email, onlyWithSmtp: $onlyWithSmtp))
		{
			return true;
		}

		$senders = UserSenderDataProvider::getUserAvailableSendersByEmail($email, $userId);

		$requiredTypes = [
			UserSenderDataProvider::SENDER_TYPE,
			UserSenderDataProvider::MAILBOX_SENDER_TYPE,
		];

		foreach ($senders as $sender)
		{
			if (in_array($sender['type'],$requiredTypes) || !$onlyWithSmtp)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks if the sender's name contains invalid characters
	 *
	 * @param string $name
	 * @return Main\Result
	 */
	public static function checkSenderNameCharacters(string $name): Main\Result
	{
		$result = new Main\Result();
		// regex checks for characters other than letters of the alphabet, numbers, spaces
		// and special characters ("-", ".", "'", "(", ")", ",")
		$pattern = '/[^\p{L}\p{N}\p{Zs}\-.\'(),]+/u';
		if (preg_match($pattern, $name))
		{
			$result->addError(new Error(Loc::getMessage('MAIN_MAIL_SENDER_INVALID_NAME')));
		}

		return $result;
	}

	private static function isSmtpsConfigured(array $smtpSettings): bool
	{
		return
			($smtpSettings['protocol'] ?? '') === 'smtps'
			|| ($smtpSettings['ssl'] ?? '') === 'Y'
		;
	}

}
