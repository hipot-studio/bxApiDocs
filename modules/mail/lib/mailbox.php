<?php

namespace Bitrix\Mail;

use Bitrix\Mail\Helper\Enum\MailboxStatus;
use Bitrix\Mail\Internal\Agent\DeletedMailboxCleanupAgent;
use Bitrix\Mail\Internal\Service\Message\Conversation\ConversationBackfill;
use Bitrix\Mail\Internals\MailboxAccessTable;
use Bitrix\Mail\Internals\Repository\MailboxAddressAliasRepository;
use Bitrix\Mail\Internals\Service\Mailbox\AddressBindingResolver;
use Bitrix\Mail\Internals\Service\Mailbox\EmailNormalizer;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\DB\ArrayResult;
use Bitrix\Main\Entity;
use Bitrix\Main\Localization;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Query;

Localization\Loc::loadMessages(__FILE__);

/**
 * Class MailboxTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Mailbox_Query query()
 * @method static EO_Mailbox_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Mailbox_Result getById($id)
 * @method static EO_Mailbox_Result getList(array $parameters = [])
 * @method static EO_Mailbox_Entity getEntity()
 * @method static \Bitrix\Mail\EO_Mailbox createObject($setDefaultValues = true)
 * @method static \Bitrix\Mail\EO_Mailbox_Collection createCollection()
 * @method static \Bitrix\Mail\EO_Mailbox wakeUpObject($row)
 * @method static \Bitrix\Mail\EO_Mailbox_Collection wakeUpCollection($rows)
 */
class MailboxTable extends Entity\DataManager
{
	use DeleteByFilterTrait;

	private const CACHE_TTL = 86400;
	private const MEMBERSHIP_FIELDS = ['USER_ID', 'ACTIVE', 'SERVER_TYPE'];
	public const SHARED_MAILBOX_KEY = 'mailbox_shared_mailboxes';
	public const OWNER_MAILBOX_KEY = 'mailbox_owners_mailboxes';
	public const SHARED_CACHE_DIR = '/mail/shared/';
	public const OWNER_CACHE_DIR = '/mail/owner/';
	private static array $ownerCache = [];
	private static array $onlyIdOwnerCache = [];
	private static array $sharedCache = [];
	private static array $onlyIdSharedCache = [];
	private static int $deferredCacheDepth = 0;
	private static bool $deferredSharedCacheClean = false;
	private static bool $deferredEntityCacheClean = false;
	private static bool $deferredCacheCleanupShutdownRegistered = false;

	public static function getFilePath()
	{
		return __FILE__;
	}

	public static function getTableName()
	{
		return 'b_mail_mailbox';
	}

	public static function update($primary, array $data)
	{
		$mailboxId = self::extractMailboxId($primary);
		$updateFields = isset($data['fields']) && is_array($data['fields']) ? $data['fields'] : $data;
		$addressFields = array_intersect(['EMAIL', 'NAME', 'LOGIN'], array_keys($updateFields));
		$membershipFields = array_intersect(self::MEMBERSHIP_FIELDS, array_keys($updateFields));
		$connection = \Bitrix\Main\Application::getConnection();
		$addressTransactionStarted = false;
		$previousMembership = [];

		try
		{
			if ($mailboxId > 0 && $addressFields !== [])
			{
				$connection->startTransaction();
				$addressTransactionStarted = true;
				$sqlHelper = $connection->getSqlHelper();
				$connection->query(sprintf(
					'SELECT %1$s FROM %2$s WHERE %1$s = %3$u FOR UPDATE',
					$sqlHelper->quote('ID'),
					$sqlHelper->quote(self::getTableName()),
					$mailboxId,
				));
			}

			if ($mailboxId > 0 && $membershipFields !== [])
			{
				$previousMembership = self::getMembershipFields($mailboxId);
			}

			$result = parent::update($primary, $data);
			if ($addressTransactionStarted)
			{
				if ($result->isSuccess())
				{
					$connection->commitTransaction();
				}
				else
				{
					$addressTransactionStarted = false;
					self::rollbackOwnTransaction($connection);
				}
				$addressTransactionStarted = false;
			}

			if ($result->isSuccess() && $previousMembership !== [])
			{
				$currentMembership = self::getMembershipFields($mailboxId);
				self::invalidateMembershipCaches($previousMembership, $currentMembership);
			}

			return $result;
		}
		catch (\Throwable $exception)
		{
			if ($addressTransactionStarted)
			{
				self::rollbackOwnTransaction($connection);
			}

			self::cleanRuntimeFullRowCachesByMailboxId($mailboxId);
			if ($previousMembership !== [])
			{
				try
				{
					self::invalidateMembershipCaches(
						$previousMembership,
						self::getMembershipFields($mailboxId),
					);
				}
				catch (\Throwable)
				{
				}
			}

			throw $exception;
		}
	}

	public static function delete($primary)
	{
		$mailboxId = self::extractMailboxId($primary);
		$connection = \Bitrix\Main\Application::getConnection();
		$connection->startTransaction();

		try
		{
			(new MailboxAddressAliasRepository())->deleteByMailboxId($mailboxId);
			$result = parent::delete($primary);
			if ($result->isSuccess())
			{
				$connection->commitTransaction();
			}
			else
			{
				self::rollbackOwnTransaction($connection);

				return $result;
			}

			DeletedMailboxCleanupAgent::ensureScheduled();

			return $result;
		}
		catch (\Throwable $exception)
		{
			self::rollbackOwnTransaction($connection);

			throw $exception;
		}
	}

	public static function deleteByFilter(array|Query\Filter\ConditionTree $filter)
	{
		$where = Query\Query::buildFilterSql(static::getEntity(), $filter);
		if ($where === '')
		{
			throw new \Bitrix\Main\ArgumentException(
				'Deleting by empty filter is not allowed, use truncate (' . static::getTableName() . ').',
				'filter',
			);
		}

		$connection = static::getEntity()->getConnection();
		static::onBeforeDeleteByFilter(' where ' . $where);
		$connection->startTransaction();
		try
		{
			$sqlHelper = $connection->getSqlHelper();
			$mailboxRows = $connection->query(sprintf(
				'SELECT %1$s FROM %2$s WHERE %3$s ORDER BY %1$s ASC FOR UPDATE',
				$sqlHelper->quote('ID'),
				$sqlHelper->quote(static::getTableName()),
				$where,
			));
			$mailboxIds = [];
			while ($row = $mailboxRows->fetch())
			{
				$mailboxIds[] = (int)$row['ID'];
			}

			$aliasRepository = new MailboxAddressAliasRepository();
			foreach ($mailboxIds as $mailboxId)
			{
				$aliasRepository->deleteByMailboxId($mailboxId);
			}
			$connection->queryExecute(sprintf(
				'DELETE FROM %s WHERE %s',
				$sqlHelper->quote(static::getTableName()),
				$where,
			));
			$connection->commitTransaction();
			static::cleanCache();
		}
		catch (\Throwable $exception)
		{
			self::rollbackOwnTransaction($connection);

			throw $exception;
		}

		if ($mailboxIds !== [])
		{
			DeletedMailboxCleanupAgent::ensureScheduled();
		}
	}

	public static function updateMulti($primaries, $data, $ignoreEvents = false)
	{
		$primaries = (array)$primaries;
		$data = (array)$data;
		$addressFields = array_intersect(['EMAIL', 'NAME', 'LOGIN'], array_keys($data));
		$membershipFields = array_intersect(self::MEMBERSHIP_FIELDS, array_keys($data));
		$mailboxIds = array_values(array_unique(array_filter(
			array_map(static fn($primary): int => self::extractMailboxId($primary), $primaries),
			static fn(int $mailboxId): bool => $mailboxId > 0,
		)));
		sort($mailboxIds, SORT_NUMERIC);
		if ($mailboxIds === [])
		{
			return parent::updateMulti($primaries, $data, $ignoreEvents);
		}
		if ($addressFields === [])
		{
			if ($membershipFields === [])
			{
				try
				{
					$result = parent::updateMulti($primaries, $data, $ignoreEvents);
				}
				catch (\Throwable $exception)
				{
					self::cleanRuntimeCachesAfterIgnoredBatch($mailboxIds, $ignoreEvents);

					throw $exception;
				}
				if ($result->isSuccess())
				{
					self::cleanRuntimeCachesAfterIgnoredBatch($mailboxIds, $ignoreEvents);
				}

				return $result;
			}

			$previousMembership = self::getMembershipFieldsForIds($mailboxIds);
			try
			{
				$result = parent::updateMulti($primaries, $data, $ignoreEvents);
			}
			catch (\Throwable $exception)
			{
				self::cleanRuntimeCachesAfterIgnoredBatch($mailboxIds, $ignoreEvents);
				self::invalidateMembershipCachesForBatch(
					$previousMembership,
					self::getMembershipFieldsForIds($mailboxIds),
				);

				throw $exception;
			}
			if ($result->isSuccess())
			{
				self::cleanRuntimeCachesAfterIgnoredBatch($mailboxIds, $ignoreEvents);
				self::invalidateMembershipCachesForBatch(
					$previousMembership,
					self::getMembershipFieldsForIds($mailboxIds),
				);
			}

			return $result;
		}

		$connection = static::getEntity()->getConnection();
		$connection->startTransaction();

		try
		{
			if ($mailboxIds !== [])
			{
				$sqlHelper = $connection->getSqlHelper();
				$connection->query(sprintf(
					'SELECT %1$s FROM %2$s WHERE %1$s IN (%3$s) ORDER BY %1$s ASC FOR UPDATE',
					$sqlHelper->quote('ID'),
					$sqlHelper->quote(static::getTableName()),
					implode(', ', $mailboxIds),
				));
			}
			$previousMembership = $membershipFields === [] ? [] : self::getMembershipFieldsForIds($mailboxIds);

			if ($ignoreEvents)
			{
				$result = parent::updateMulti($primaries, $data, true);
				if ($result->isSuccess())
				{
					$mailboxesById = self::getAddressFieldsForIds($mailboxIds);
					$groups = [];
					$normalizer = new EmailNormalizer();
					foreach ($mailboxesById as $mailboxId => $mailbox)
					{
						$normalizedEmail = $normalizer->normalizeMailbox($mailbox);
						$groupKey = $normalizedEmail ?? "\0";
						$groups[$groupKey]['ids'][] = $mailboxId;
						$groups[$groupKey]['normalizedEmail'] = $normalizedEmail;
						$groups[$groupKey]['domainHash'] = $normalizer->domainHash($normalizedEmail);
					}

					foreach ($groups as $group)
					{
						$derivedResult = parent::updateMulti(
							$group['ids'],
							[
								'EMAIL_NORMALIZED' => $group['normalizedEmail'],
								'EMAIL_DOMAIN_HASH' => $group['domainHash'],
							],
							true,
						);
						if (!$derivedResult->isSuccess())
						{
							$result->addErrors($derivedResult->getErrors());
							break;
						}
					}
				}
			}
			else
			{
				$result = parent::updateMulti($primaries, $data, false);
			}
			if ($result->isSuccess())
			{
				$connection->commitTransaction();
				self::cleanRuntimeCachesAfterIgnoredBatch($mailboxIds, $ignoreEvents);
				if ($previousMembership !== [])
				{
					self::invalidateMembershipCachesForBatch(
						$previousMembership,
						self::getMembershipFieldsForIds($mailboxIds),
					);
				}
			}
			else
			{
				self::rollbackOwnTransaction($connection);
			}

			return $result;
		}
		catch (\Throwable $exception)
		{
			self::rollbackOwnTransaction($connection);
			self::cleanRuntimeCachesAfterIgnoredBatch($mailboxIds, $ignoreEvents);

			throw $exception;
		}
	}

	/**
	 * Decides whether a mailbox that has just been connected may group its list.
	 *
	 * It stores no letter yet, so this is the whole pass of the backfill over it: the check counts
	 * three empty sets instead of walking history. The rule stays the one of the feature: readiness
	 * comes from the check and from nowhere else, and everything that can leave the projection behind
	 * takes it back and arms the background pass.
	 *
	 * Never throws: connecting a mailbox must not fail because of a read model.
	 */
	private static function decideConversationProjectionReadiness(int $mailboxId): void
	{
		if ($mailboxId <= 0)
		{
			return;
		}

		try
		{
			(new ConversationBackfill())->completePass($mailboxId, 0);
		}
		catch (\Throwable)
		{
			// Readiness simply stays unset, and the background pass decides it later.
		}
	}

	private static function rollbackOwnTransaction(\Bitrix\Main\DB\Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (\Bitrix\Main\DB\TransactionException $exception)
		{
			// The drivers throw this only after ROLLBACK TO SAVEPOINT has succeeded.
			if ($exception->getMessage() !== 'Nested rollbacks are unsupported.')
			{
				throw $exception;
			}
		}
	}

	/**
	 * ( A user can connect the same mailbox only once )
	 *
	 * @param $email
	 * @return mixed
	 */
	public static function getUserMailboxWithEmail($email): mixed
	{
		global $USER;

		if (!is_object($USER) || !$USER->isAuthorized())
		{
			return null;
		}

		$resolved = (new AddressBindingResolver())->findBindings((string)$email, (int)$USER->getId())[0] ?? null;

		if ($resolved === null)
		{
			return null;
		}

		return static::getUserMailbox($resolved->mailboxId, (int)$USER->getId()) ?: null;
	}

	/**
	 * @param $email
	 * @return ArrayResult
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function getMailboxesWithEmail($email)
	{
		$result = [];
		$bindings = (new AddressBindingResolver())->findBindings((string)$email);
		$mailboxIds = array_map(
			static fn($binding): int => $binding->mailboxId,
			$bindings,
		);

		if ($mailboxIds !== [])
		{
			$rowsById = (new AddressBindingResolver())->findMailboxesByIds($mailboxIds);

			foreach ($mailboxIds as $mailboxId)
			{
				if (isset($rowsById[$mailboxId]))
				{
					$result[] = [
						'ID' => $rowsById[$mailboxId]['ID'],
						'USER_ID' => $rowsById[$mailboxId]['USER_ID'],
					];
				}
			}
		}

		$dbResult = new ArrayResult($result);
		$dbResult->setCount(count($result));

		return $dbResult;
	}

	public static function getOwnerId($mailboxId): int
	{
		$mailbox = self::getList([
			'select' => [
				'USER_ID',
			],
			'filter' => [
				'=ID' => $mailboxId,
			],
			'limit' => 1,
		])->fetch();

		if (isset($mailbox['USER_ID']))
		{
			return (int) $mailbox['USER_ID'];
		}

		return 0;
	}

	public static function getUserMailbox($mailboxId, $userId = null)
	{
		$mailboxes = static::getUserMailboxes($userId);

		return array_key_exists($mailboxId, $mailboxes) ? $mailboxes[$mailboxId] : false;
	}

	public static function getTheOwnersMailboxes($userId = null, bool $onlyIds = false): array
	{
		global $USER;

		if (!($userId > 0 || (is_object($USER) && $USER->isAuthorized())))
		{
			return [];
		}

		if (!($userId > 0))
		{
			$userId = $USER->getId();
		}

		if ($onlyIds && isset(self::$onlyIdOwnerCache[$userId]))
		{
			return self::$onlyIdOwnerCache[$userId];
		}

		if (!$onlyIds && isset(self::$ownerCache[$userId]))
		{
			return self::$ownerCache[$userId];
		}

		$cacheManager = Cache::createInstance();
		$cacheKey = self::getOwnerMailboxCacheKey($userId);
		if ($cacheManager->initCache(self::CACHE_TTL, $cacheKey,self::OWNER_CACHE_DIR))
		{
			$result = $cacheManager->getVars();
			//cache stores only id values, but empty value also works for full request
			if ($onlyIds || $result === [])
			{
				return $result;
			}
		}

		self::$onlyIdOwnerCache[$userId] = [];
		if (!$onlyIds)
		{
			self::$ownerCache[$userId] = [];
		}

		$getListParams = [
			'filter' => [
				[
					'=USER_ID' => $userId,
				],
				'=ACTIVE' => 'Y',
				'=SERVER_TYPE' => 'imap',
			],
			'order' => [
				'ID' => 'DESC',
			],
		];

		if ($onlyIds)
		{
			$getListParams['select'] = ['ID'];
		}

		$res = static::getList($getListParams);
		while ($mailbox = $res->fetch())
		{
			static::normalizeEmail($mailbox);
			$mailboxId = $mailbox['ID'] ?? null;
			self::$onlyIdOwnerCache[$userId][$mailboxId] = [
				'ID' => $mailboxId,
			];

			if (!$onlyIds)
			{
				self::$ownerCache[$userId][$mailboxId] = $mailbox;
			}
		}

		if (empty(self::$onlyIdOwnerCache[$userId]))
		{
			self::$ownerCache[$userId] = [];
		}

		if ($cacheManager->startDataCache(self::CACHE_TTL, $cacheKey,self::OWNER_CACHE_DIR))
		{
			$cacheManager->endDataCache(self::$onlyIdOwnerCache[$userId]);
		}

		return $onlyIds ? self::$onlyIdOwnerCache[$userId] : self::$ownerCache[$userId];
	}

	public static function getTheSharedMailboxes($userId = null, bool $onlyIds = false): array
	{
		global $USER;

		if (!($userId > 0 || (is_object($USER) && $USER->isAuthorized())))
		{
			return [];
		}

		if (!($userId > 0))
		{
			$userId = $USER->getId();
		}

		if ($onlyIds && isset(self::$onlyIdSharedCache[$userId]))
		{
			return self::$onlyIdSharedCache[$userId];
		}

		if (!$onlyIds && isset(self::$sharedCache[$userId]))
		{
			return self::$sharedCache[$userId];
		}

		$cacheManager = Cache::createInstance();
		$cacheKey = self::getSharedMailboxCacheKey($userId);
		if ($cacheManager->initCache(self::CACHE_TTL, $cacheKey, self::SHARED_CACHE_DIR))
		{
			$result = $cacheManager->getVars();
			//cache stores only id values, but empty value also works for full request
			if ($onlyIds || $result === [])
			{
				return $result;
			}
		}

		self::$onlyIdSharedCache[$userId] = [];
		if (!$onlyIds)
		{
			self::$sharedCache[$userId] = [];
		}

		(new \CAccess)->updateCodes(['USER_ID' => $userId]);

		$getListParams = [
			'runtime' => [
				new Entity\ReferenceField(
					'ACCESS',
					'Bitrix\Mail\Internals\MailboxAccessTable',
					[
						'=this.ID' => 'ref.MAILBOX_ID',
					],
					[
						'join_type' => 'LEFT',
					],
				),
				new Entity\ReferenceField(
					'USER_ACCESS',
					'Bitrix\Main\UserAccess',
					[
						'this.ACCESS.ACCESS_CODE' => 'ref.ACCESS_CODE',
					],
					[
						'join_type' => 'LEFT',
					],
				),
			],
			'filter' => [
				[
					'LOGIC' => 'AND',
					'!=USER_ID' => $userId,
					'=USER_ACCESS.USER_ID' => $userId,
				],
				'=ACTIVE' => 'Y',
				'=SERVER_TYPE' => 'imap',
			],
			'order' => [
				'ID' => 'DESC',
			],
		];

		if ($onlyIds)
		{
			$getListParams['select'] = ['ID'];
		}

		$res = static::getList($getListParams);

		while ($mailbox = $res->fetch())
		{
			static::normalizeEmail($mailbox);

			$mailboxId = $mailbox['ID'] ?? null;
			self::$onlyIdSharedCache[$userId][$mailboxId] = [
				'ID' => $mailboxId,
			];

			if (!$onlyIds)
			{
				self::$sharedCache[$userId][$mailboxId] = $mailbox;
			}
		}

		if (empty(self::$onlyIdSharedCache[$userId]))
		{
			self::$sharedCache[$userId] = [];
		}

		if ($cacheManager->startDataCache(self::CACHE_TTL, $cacheKey,self::SHARED_CACHE_DIR))
		{
			$cacheManager->endDataCache(self::$onlyIdSharedCache[$userId]);
		}

		return $onlyIds ? self::$onlyIdSharedCache[$userId] : self::$sharedCache[$userId];
	}

	/**
	 * Returns ACTIVE mailboxes that the user has access to
	 *
	 * @param $userId
	 * @return array
	 */
	public static function getUserMailboxes($userId = null, bool $onlyIds = false): array
	{
		global $USER;

		if (!($userId > 0 || (is_object($USER) && $USER->isAuthorized())))
		{
			return [];
		}

		if (!($userId > 0))
		{
			$userId = $USER->getId();
		}

		$sharedMailboxes = static::getTheSharedMailboxes($userId, $onlyIds);
		$ownersMailboxes = static::getTheOwnersMailboxes($userId, $onlyIds);

		return $ownersMailboxes + $sharedMailboxes;
	}

	public static function onAfterAdd(Entity\Event $event): void
	{
		$mailbox = $event->getParameter('fields');
		if (isset($mailbox['USER_ID']))
		{
			self::cleanOwnerCacheByUserId((int)$mailbox['USER_ID']);
		}

		if ((string)($mailbox['SERVER_TYPE'] ?? '') === 'imap')
		{
			self::decideConversationProjectionReadiness((int)$event->getParameter('id'));
		}
	}

	public static function onBeforeAdd(Entity\Event $event): Entity\EventResult
	{
		return self::setNormalizedEmail($event, true);
	}

	public static function onBeforeUpdate(Entity\Event $event): Entity\EventResult
	{
		return self::setNormalizedEmail($event);
	}

	private static function setNormalizedEmail(Entity\Event $event, bool $always = false): Entity\EventResult
	{
		$result = new Entity\EventResult();
		$fields = $event->getParameter('fields');
		$addressFields = ['EMAIL', 'NAME', 'LOGIN'];
		if ($always || array_intersect($addressFields, array_keys($fields)) !== [])
		{
			$mailbox = [];
			if (!$always)
			{
				$primary = $event->getParameter('primary');
				$mailbox = static::getById((int)($primary['ID'] ?? 0))->fetch() ?: [];
			}

			$normalizer = new EmailNormalizer();
			$normalizedEmail = $normalizer->normalizeMailbox(array_merge($mailbox, $fields));
			$result->modifyFields([
				'EMAIL_NORMALIZED' => $normalizedEmail,
				'EMAIL_DOMAIN_HASH' => $normalizer->domainHash($normalizedEmail),
			]);
		}

		return $result;
	}

	public static function onAfterUpdate(Entity\Event $event): void
	{
		$primary = $event->getParameter('primary');
		$mailboxId = (int)($primary['ID'] ?? 0);
		if ($mailboxId > 0)
		{
			self::cleanRuntimeFullRowCachesByMailboxId($mailboxId);
		}
	}

	public static function onAfterDelete(Entity\Event $event): void
	{
		self::cleanAllCache();
	}

	public static function normalizeEmail(&$mailbox)
	{
		foreach (array($mailbox['EMAIL'], $mailbox['NAME'], $mailbox['LOGIN']) as $item)
		{
			$address = new \Bitrix\Main\Mail\Address($item);
			if ($address->validate())
			{
				$mailbox['EMAIL'] = $address->getEmail();
				break;
			}
		}

		return $mailbox;
	}

	private static function normalizeMailboxOptions(mixed $options): array
	{
		if (is_array($options))
		{
			return $options;
		}

		if (!is_string($options))
		{
			return [];
		}

		try
		{
			$options = @unserialize($options, ['allowed_classes' => false]);
		}
		catch (\Throwable)
		{
			return [];
		}

		if (is_array($options))
		{
			return $options;
		}

		if (!is_string($options))
		{
			return [];
		}

		try
		{
			$options = @unserialize($options, ['allowed_classes' => false]);
		}
		catch (\Throwable)
		{
			return [];
		}

		return is_array($options) ? $options : [];
	}

	public static function getMap()
	{
		return array(
			'ID' => array(
				'data_type'    => 'integer',
				'primary'      => true,
				'autocomplete' => true,
			),
			'TIMESTAMP_X' => array(
				'data_type' => 'datetime',
			),
			'LID' => array(
				'data_type' => 'string',
				'required'  => true,
			),
			'ACTIVE' => array(
				'data_type' => 'enum',
				'values'    => array_column(MailboxStatus::cases(), 'value'),
			),
			'SERVICE_ID' => array(
				'data_type' => 'integer',
			),
			'EMAIL' => array(
				'data_type' => 'string',
			),
			'EMAIL_NORMALIZED' => array(
				'data_type' => 'string',
			),
			'EMAIL_DOMAIN_HASH' => array(
				'data_type' => 'string',
			),
			'USERNAME' => array(
				'data_type' => 'string',
			),
			'NAME' => array(
				'data_type' => 'string',
			),
			'SERVER' => array(
				'data_type' => 'string',
			),
			'PORT' => array(
				'data_type' => 'integer',
			),
			'LINK' => array(
				'data_type' => 'string',
			),
			'LOGIN' => array(
				'data_type' => 'string',
			),
			'CHARSET' => array(
				'data_type' => 'string',
			),
			'PASSWORD' => array(
				'data_type' => (static::cryptoEnabled('PASSWORD') ? 'crypto' : 'string'),
				'save_data_modification' => function()
				{
					return array(
						function ($value)
						{
							return static::cryptoEnabled('PASSWORD') ? $value : \CMailUtil::crypt($value);
						},
					);
				},
				'fetch_data_modification' => function()
				{
					return array(
						function ($value)
						{
							return static::cryptoEnabled('PASSWORD') ? $value : \CMailUtil::decrypt($value);
						},
					);
				},
			),
			'DESCRIPTION' => array(
				'data_type' => 'text',
			),
			'USE_MD5' => array(
				'data_type' => 'boolean',
				'values'    => array('N', 'Y'),
			),
			'DELETE_MESSAGES' => array(
				'data_type' => 'boolean',
				'values'    => array('N', 'Y'),
			),
			'PERIOD_CHECK' => array(
				'data_type' => 'integer',
			),
			'MAX_MSG_COUNT' => array(
				'data_type' => 'integer',
			),
			'MAX_MSG_SIZE' => array(
				'data_type' => 'integer',
			),
			'MAX_KEEP_DAYS' => array(
				'data_type' => 'integer',
			),
			'USE_TLS' => array(
				'data_type' => 'enum',
				'values'    => array('N', 'Y', 'S'),
			),
			'SERVER_TYPE' => array(
				'data_type' => 'enum',
				'values'    => array('smtp', 'pop3', 'imap', 'controller', 'domain', 'crdomain'),
			),
			'DOMAINS' => array(
				'data_type' => 'string',
			),
			'RELAY' => array(
				'data_type' => 'boolean',
				'values'    => array('N', 'Y'),
			),
			'AUTH_RELAY' => array(
				'data_type' => 'boolean',
				'values'    => array('N', 'Y'),
			),
			'USER_ID' => array(
				'data_type' => 'integer',
			),
			'SYNC_LOCK' => array(
				'data_type' => 'integer',
			),
			// Hot pointer to the active physical source generation, see MailboxSourceGenerationTable
			'ACTIVE_GENERATION_ID' => array(
				'data_type' => 'integer',
				'default_value' => 0,
			),
			'AVATAR_RESOURCE_ID' => array(
				'data_type' => 'integer',
			),
			'AVATAR_REVISION' => array(
				'data_type' => 'integer',
				'default_value' => 0,
			),
			'OPTIONS' => array(
				'data_type'  => 'text',
				'save_data_modification' => function()
				{
					return array(
						function ($options)
						{
							return serialize($options);
						},
					);
				},
				'fetch_data_modification' => function()
				{
					return array(
						function ($values)
						{
							return static::normalizeMailboxOptions($values);
						},
					);
				},
			),
			'SITE' => array(
				'data_type' => 'Bitrix\Main\Site',
				'reference' => array('=this.LID' => 'ref.LID'),
			),
		);
	}

	public static function cleanOwnerCacheByUserId(int $userId): void
	{
		unset(self::$ownerCache[$userId]);
		unset(self::$onlyIdOwnerCache[$userId]);
		Cache::createInstance()
			 ->clean(self::getOwnerMailboxCacheKey($userId),MailboxTable::OWNER_CACHE_DIR)
		;
	}

	/** A caller that already holds the row passes its owner instead of making it be read again. */
	public static function cleanCachesByMailboxId(int $mailboxId, ?int $ownerId = null): void
	{
		$ownerId = ($ownerId ?? 0) > 0 ? $ownerId : self::getOwnerId($mailboxId);
		if ($ownerId > 0)
		{
			self::cleanOwnerCacheByUserId($ownerId);
		}

		self::cleanAllSharedCache();
	}

	private static function extractMailboxId(mixed $primary): int
	{
		return (int)(is_array($primary) ? ($primary['ID'] ?? 0) : $primary);
	}

	private static function getMembershipFields(int $mailboxId): array
	{
		return static::getByPrimary($mailboxId, [
			'select' => self::MEMBERSHIP_FIELDS,
		])->fetch() ?: [];
	}

	private static function getMembershipFieldsForIds(array $mailboxIds): array
	{
		$result = [];
		foreach (array_chunk($mailboxIds, 100) as $mailboxIdChunk)
		{
			$rows = static::getList([
				'select' => array_merge(['ID'], self::MEMBERSHIP_FIELDS),
				'filter' => ['@ID' => $mailboxIdChunk],
			])->fetchAll();
			foreach ($rows as $row)
			{
				$result[(int)$row['ID']] = $row;
			}
		}

		return $result;
	}

	private static function getAddressFieldsForIds(array $mailboxIds): array
	{
		$result = [];
		foreach (array_chunk($mailboxIds, 100) as $mailboxIdChunk)
		{
			$rows = static::getList([
				'select' => ['ID', 'EMAIL', 'NAME', 'LOGIN'],
				'filter' => ['@ID' => $mailboxIdChunk],
			])->fetchAll();
			foreach ($rows as $row)
			{
				$result[(int)$row['ID']] = $row;
			}
		}

		return $result;
	}

	private static function invalidateMembershipCaches(array $previous, array $current): void
	{
		self::invalidateMembershipCachesForBatch([0 => $previous], [0 => $current]);
	}

	private static function invalidateMembershipCachesForBatch(array $previousById, array $currentById): void
	{
		$ownerIds = [];
		$sharedCacheMustBeCleaned = false;
		foreach ($previousById as $mailboxId => $previous)
		{
			$current = $currentById[$mailboxId] ?? [];
			$previousOwnerId = (int)($previous['USER_ID'] ?? 0);
			$currentOwnerId = (int)($current['USER_ID'] ?? 0);
			$userChanged = $previousOwnerId !== $currentOwnerId;
			$membershipChanged = $userChanged
				|| (string)($previous['ACTIVE'] ?? '') !== (string)($current['ACTIVE'] ?? '')
				|| (string)($previous['SERVER_TYPE'] ?? '') !== (string)($current['SERVER_TYPE'] ?? '')
			;
			if (!$membershipChanged)
			{
				continue;
			}

			$ownerIds[$previousOwnerId] = true;
			$ownerIds[$currentOwnerId] = true;
			$sharedCacheMustBeCleaned = true;
		}

		foreach (array_keys($ownerIds) as $ownerId)
		{
			if ($ownerId > 0)
			{
				self::cleanOwnerCacheByUserId($ownerId);
			}
		}

		if ($sharedCacheMustBeCleaned)
		{
			// Shared access can include users, departments and other access codes.
			// Their exact users cannot be determined safely from the mailbox row alone.
			self::cleanAllSharedCache();
		}
	}

	public static function cleanRuntimeFullRowCachesByMailboxId(int $mailboxId): void
	{
		foreach (self::$ownerCache as $userId => $mailboxes)
		{
			if (array_key_exists($mailboxId, $mailboxes))
			{
				unset(self::$ownerCache[$userId]);
			}
		}

		foreach (self::$sharedCache as $userId => $mailboxes)
		{
			if (array_key_exists($mailboxId, $mailboxes))
			{
				unset(self::$sharedCache[$userId]);
			}
		}
	}

	private static function cleanRuntimeCachesAfterIgnoredBatch(array $mailboxIds, bool $ignoreEvents): void
	{
		if (!$ignoreEvents)
		{
			return;
		}

		foreach ($mailboxIds as $mailboxId)
		{
			self::cleanRuntimeFullRowCachesByMailboxId($mailboxId);
		}
	}

	private static function getOwnerMailboxCacheKey(int $userId): string
	{
		return MailboxTable::OWNER_MAILBOX_KEY . '_' . $userId;
	}

	private static function cleanAllCache(): void
	{
		self::$onlyIdOwnerCache = [];
		self::$ownerCache = [];
		self::$onlyIdSharedCache = [];
		self::$sharedCache = [];

		$cacheManager = Cache::createInstance();
		$cacheManager->cleanDir(self::SHARED_CACHE_DIR);
		$cacheManager->cleanDir(self::OWNER_CACHE_DIR);
	}

	public static function cleanUserSharedCache(int $userId): void
	{
		unset(self::$sharedCache[$userId]);
		unset(self::$onlyIdSharedCache[$userId]);

		Cache::createInstance()
			 ->clean(self::getSharedMailboxCacheKey($userId),MailboxTable::SHARED_CACHE_DIR)
		;
	}

	private static function getSharedMailboxCacheKey(int $userId): string
	{
		return self::SHARED_MAILBOX_KEY . '_' . $userId;
	}

	public static function cleanAllSharedCache(): void
	{
		self::$onlyIdSharedCache = [];
		self::$sharedCache = [];

		if (self::$deferredCacheDepth > 0)
		{
			self::$deferredSharedCacheClean = true;

			return;
		}

		Cache::createInstance()->cleanDir(self::SHARED_CACHE_DIR);
	}

	/** Within a deferred scope the entity cache is dropped once at the end of it. */
	public static function cleanEntityCache(): void
	{
		if (self::$deferredCacheDepth > 0)
		{
			self::$deferredEntityCacheClean = true;

			return;
		}

		self::getEntity()->cleanCache();
	}

	/**
	 * Defers to the end of the scope the drop of the cache directories that belong to no single
	 * mailbox: the portal-wide shared one and the one of the entity. The in-memory maps are still
	 * dropped at once, so a read of the shared mailboxes inside the scope answers from the disk
	 * directory and may be stale until the scope ends. A fatal error skips `finally`, so the drop is
	 * asked for on shutdown too: it is lost only when the process itself is killed.
	 */
	public static function withDeferredCacheCleanup(callable $run): mixed
	{
		self::registerDeferredCacheCleanupOnShutdown();
		self::$deferredCacheDepth++;
		try
		{
			return $run();
		}
		finally
		{
			self::$deferredCacheDepth--;
			if (self::$deferredCacheDepth === 0)
			{
				self::flushDeferredCacheCleanup();
			}
		}
	}

	/**
	 * The last chance of a scope that never reached its end. The depth is dropped as well: after the
	 * shutdown of the hit there is no scope left to defer anything to.
	 */
	public static function flushDeferredCacheCleanupOnShutdown(): void
	{
		self::$deferredCacheDepth = 0;
		self::flushDeferredCacheCleanup();
	}

	private static function registerDeferredCacheCleanupOnShutdown(): void
	{
		if (self::$deferredCacheCleanupShutdownRegistered)
		{
			return;
		}

		self::$deferredCacheCleanupShutdownRegistered = true;
		register_shutdown_function([self::class, 'flushDeferredCacheCleanupOnShutdown']);
	}

	private static function flushDeferredCacheCleanup(): void
	{
		if (self::$deferredSharedCacheClean)
		{
			self::$deferredSharedCacheClean = false;
			Cache::createInstance()->cleanDir(self::SHARED_CACHE_DIR);
		}

		if (self::$deferredEntityCacheClean)
		{
			self::$deferredEntityCacheClean = false;
			self::getEntity()->cleanCache();
		}
	}
}
