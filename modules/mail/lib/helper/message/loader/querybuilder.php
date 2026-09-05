<?php

namespace Bitrix\Mail\Helper\Message\Loader;

use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Mail\Internals\MailMessageMarkTable;
use Bitrix\Mail\Internals\MessageAccessTable;
use Bitrix\Mail\Internals\MessageClosureTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Main;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ORM;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;

class QueryBuilder
{
	public const FILTER_KEY_INCLUDE_BINDINGS = '__MAIL_INCLUDE_BINDINGS';
	public const FILTER_KEY_EXCLUDE_BINDINGS = '__MAIL_EXCLUDE_BINDINGS';
	public const FILTER_KEY_IS_FAVORITE = '__MAIL_IS_FAVORITE';
	public const FILTER_KEY_UNANSWERED = '__MAIL_UNANSWERED';
	public const FILTER_KEY_CLASSIFICATION = '__MAIL_CLASSIFICATION';

	private const VISIBLE_UID_FILTERS = [
		'==MESSAGE_UID.DELETE_TIME' => 0,
		'!@MESSAGE_UID.IS_OLD' => MailMessageUidTable::HIDDEN_STATUSES,
		'>MESSAGE_UID.MESSAGE_ID' => 0,
	];

	/* VISIBLE_UID_FILTERS for queries on b_mail_message_uid itself. Public so that actions
	 * resolving a grid id see exactly the messages the list shows. */
	public const VISIBLE_UID_FILTERS_DRIVER = [
		'==DELETE_TIME' => 0,
		'!@IS_OLD' => MailMessageUidTable::HIDDEN_STATUSES,
		'>MESSAGE_ID' => 0,
	];

	/*
	 * Allowlist of fields that the fast path (`buildListQueryFromUid`) can
	 * safely route to the UID-table driver. Anything outside this list —
	 * unknown columns, fields from other tables, References to other entities —
	 * must go through the slow path (`buildListQueryFromMessage`), which has
	 * the full set of References registered.
	 *
	 * Adding a new field to this list — only after confirming it actually
	 * exists in `b_mail_message_uid` (or is reachable from there without an
	 * extra JOIN). When in doubt — don't add, slow path will handle it.
	 */
	private const UID_DRIVER_FIELDS = [
		'ID',
		'MAILBOX_ID',
		'MESSAGE_ID',
		'INTERNALDATE',
		'DIR_MD5',
		'DIR_UIDV',
		'IS_SEEN',
		'IS_OLD',
		'DELETE_TIME',
		'MSG_UID',
		'HEADER_MD5',
		'SESSION_ID',
		'DATE_INSERT',
		'TIMESTAMP_X',
	];

	private const DEFAULT_LIMIT = 26;
	private const DEFAULT_OFFSET = 0;

	/**
	 * @param array $filter Standard Bitrix-ORM filter. Special pseudo-key
	 *                      {@see self::FILTER_KEY_INCLUDE_BINDINGS} (array of ENTITY_TYPE values)
	 *                      includes messages that have a binding of any listed type
	 *                      via an EXISTS subquery on b_mail_message_access.
	 *                      Special pseudo-key
	 *                      {@see self::FILTER_KEY_EXCLUDE_BINDINGS} (array of ENTITY_TYPE values)
	 *                      excludes messages that have a binding of any listed type
	 *                      via a NOT EXISTS subquery on b_mail_message_access.
	 *                      Special pseudo-key
	 *                      {@see self::FILTER_KEY_IS_FAVORITE} (user id) keeps only messages the
	 *                      user marked as favorite via an EXISTS subquery on b_mail_message_mark.
	 *                      Special pseudo-key
	 *                      {@see self::FILTER_KEY_CLASSIFICATION} (array of classification mark
	 *                      codes) keeps only messages that have any listed shared mark.
	 *                      Special pseudo-key
	 *                      {@see self::FILTER_KEY_UNANSWERED} (bool) keeps only messages with no
	 *                      reply of the mailbox in their thread (true) or only answered ones (false).
	 * @param int $limit
	 * @param int $offset
	 * @return Query
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public static function buildMailMessageListQuery(
		array $filter,
		int $limit = self::DEFAULT_LIMIT,
		int $offset = self::DEFAULT_OFFSET
	): Query
	{
		if (self::isUidOnlyFilter($filter))
		{
			return self::buildListQueryFromUid($filter, $limit, $offset);
		}

		return self::buildListQueryFromMessage($filter, $limit, $offset);
	}

	private static function isUidOnlyFilter(array $filter): bool
	{
		foreach (array_keys($filter) as $key)
		{
			$cleanKey = ltrim((string)$key, "@!*<=>");
			$cleanKey = preg_replace('/^MESSAGE_UID\./', '', $cleanKey);

			if (!in_array($cleanKey, self::UID_DRIVER_FIELDS, true))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Extracts and unsets a bindings pseudo-key from $filter.
	 *
	 * @param array $filter passed by reference; the pseudo-key is removed.
	 * @return string[] List of ENTITY_TYPE values; empty when no exclusion is requested.
	 */
	private static function extractBindings(array &$filter, string $key): array
	{
		$raw = $filter[$key] ?? null;
		unset($filter[$key]);

		if (!is_array($raw))
		{
			return [];
		}

		return array_values(array_filter($raw, 'is_string'));
	}

	/**
	 * @return string[]
	 */
	private static function extractIncludeBindings(array &$filter): array
	{
		return self::extractBindings($filter, self::FILTER_KEY_INCLUDE_BINDINGS);
	}

	/**
	 * @return string[]
	 */
	private static function extractExcludeBindings(array &$filter): array
	{
		return self::extractBindings($filter, self::FILTER_KEY_EXCLUDE_BINDINGS);
	}

	/**
	 * @param array $filter passed by reference; the pseudo-key is removed.
	 * @return int User id whose favorites are requested; 0 when not requested.
	 */
	private static function extractFavoriteUserId(array &$filter): int
	{
		$raw = $filter[self::FILTER_KEY_IS_FAVORITE] ?? null;
		unset($filter[self::FILTER_KEY_IS_FAVORITE]);

		return (int)$raw;
	}

	/**
	 * @return bool|null null when the pseudo-key is absent.
	 */
	private static function extractUnanswered(array &$filter): ?bool
	{
		$raw = $filter[self::FILTER_KEY_UNANSWERED] ?? null;
		unset($filter[self::FILTER_KEY_UNANSWERED]);

		return is_bool($raw) ? $raw : null;
	}

	/**
	 * @return int[] List of classification mark codes; empty when not requested.
	 */
	private static function extractClassificationCodes(array &$filter): array
	{
		$raw = $filter[self::FILTER_KEY_CLASSIFICATION] ?? null;
		unset($filter[self::FILTER_KEY_CLASSIFICATION]);

		if (!is_array($raw))
		{
			return [];
		}

		return array_values(array_filter($raw, 'is_int'));
	}

	private static function stripUidPrefix(array $filter): array
	{
		$result = [];
		foreach ($filter as $key => $value)
		{
			$newKey = preg_replace(
				'/^([!=<>@*]*)MESSAGE_UID\.(.+)$/',
				'$1$2',
				(string)$key,
			);
			$result[$newKey] = $value;
		}

		return $result;
	}

	/**
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	private static function buildListQueryFromUid(
		array $filter,
		int $limit,
		int $offset,
	): Query
	{
		return MailMessageUidTable::query()
			->registerRuntimeField(
				'MAX_INTERNALDATE',
				new ExpressionField(
					'MAX_INTERNALDATE',
					'MAX(%s)',
					['INTERNALDATE'],
				),
			)
			->addSelect('MESSAGE_ID', 'DISTINCT_ID')
			->setFilter(array_merge(
				self::VISIBLE_UID_FILTERS_DRIVER,
				self::stripUidPrefix($filter),
			))
			->addGroup('MESSAGE_ID')
			->addOrder('MAX_INTERNALDATE', 'DESC')
			->addOrder('MESSAGE_ID', 'DESC')
			->setLimit($limit)
			->setOffset($offset)
		;
	}

	/**
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	private static function buildListQueryFromMessage(
		array $filter,
		int $limit,
		int $offset,
	): Query
	{
		$includeBindings = self::extractIncludeBindings($filter);
		$excludeBindings = self::extractExcludeBindings($filter);
		$favoriteUserId = self::extractFavoriteUserId($filter);
		$unanswered = self::extractUnanswered($filter);
		$classificationCodes = self::extractClassificationCodes($filter);

		$accessSubquery = (new Query(MessageAccessTable::getEntity()))
			->addFilter('=MAILBOX_ID', new SqlExpression('%s'))
			->addFilter('=MESSAGE_ID', new SqlExpression('%s'))
		;

		$closureSubquery = (new Query(MessageClosureTable::getEntity()))
			->addFilter('=PARENT_ID', new SqlExpression('%s'))
			->addFilter('!=MESSAGE_ID', new SqlExpression('%s'))
		;

		$query = MailMessageTable::query()
			->registerRuntimeField(
				new Reference(
					'MESSAGE_UID',
					MailMessageUidTable::class,
					[
						'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
						'=this.ID' => 'ref.MESSAGE_ID',
					],
					[ 'join_type' => 'INNER' ],
				),
			)
			->registerRuntimeField(
				new Reference(
					'MESSAGE_ACCESS',
					MessageAccessTable::class,
					[
						'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
						'=this.ID' => 'ref.MESSAGE_ID',
					],
				),
			)
			->registerRuntimeField(
				'MESSAGE_ACCESS_EXISTS',
				new ExpressionField(
					'MESSAGE_ACCESS_EXISTS',
					"EXISTS(" . $accessSubquery->getQuery() . ")",
					['MAILBOX_ID', 'ID'],
				),
			)
			->registerRuntimeField(
				'MESSAGE_CLOSURE',
				new ExpressionField(
					'MESSAGE_CLOSURE',
					"EXISTS(" . $closureSubquery->getQuery() . ")",
					['ID', 'ID'],
				),
			)
			->registerRuntimeField(
				'FIELD_MAX_SORT',
				new ExpressionField(
					'FIELD_MAX_SORT',
					'MAX(%s)',
					['MESSAGE_UID.INTERNALDATE']
				),
			)
			->addSelect('ID', 'DISTINCT_ID')
		;

		$finalFilter = array_merge(self::VISIBLE_UID_FILTERS, $filter);

		if ($includeBindings !== [])
		{
			if (in_array(MessageAccessTable::ENTITY_TYPE_NO_BIND, $includeBindings, true))
			{
				$finalFilter['==MESSAGE_ACCESS_EXISTS'] = false;
			}
			else
			{
				$includeSubquery = (new Query(MessageAccessTable::getEntity()))
					->addFilter('=MAILBOX_ID', new SqlExpression('%s'))
					->addFilter('=MESSAGE_ID', new SqlExpression('%s'))
					->addFilter('@ENTITY_TYPE', array_values($includeBindings))
				;

				$query->registerRuntimeField(
					'INCLUDED_BINDING_EXISTS',
					new ExpressionField(
						'INCLUDED_BINDING_EXISTS',
						"EXISTS(" . $includeSubquery->getQuery() . ")",
						['MAILBOX_ID', 'ID'],
					),
				);

				$finalFilter['==INCLUDED_BINDING_EXISTS'] = true;
			}
		}

		if ($excludeBindings !== [])
		{
			$excludeSubquery = (new Query(MessageAccessTable::getEntity()))
				->addFilter('=MAILBOX_ID', new SqlExpression('%s'))
				->addFilter('=MESSAGE_ID', new SqlExpression('%s'))
				->addFilter('@ENTITY_TYPE', array_values($excludeBindings))
			;

			$query->registerRuntimeField(
				'EXCLUDED_BINDING_EXISTS',
				new ExpressionField(
					'EXCLUDED_BINDING_EXISTS',
					"EXISTS(" . $excludeSubquery->getQuery() . ")",
					['MAILBOX_ID', 'ID'],
				),
			);

			$finalFilter['==EXCLUDED_BINDING_EXISTS'] = false;
		}

		if ($favoriteUserId > 0)
		{
			$favoriteSubquery = (new Query(MailMessageMarkTable::getEntity()))
				->addFilter('=MAILBOX_ID', new SqlExpression('%s'))
				->addFilter('=MESSAGE_ID', new SqlExpression('%s'))
				->addFilter('=USER_ID', $favoriteUserId)
				->addFilter('=CODE', MailMessageMarkTable::CODE_FAVORITES)
			;

			$query->registerRuntimeField(
				'IS_FAVORITE_EXISTS',
				new ExpressionField(
					'IS_FAVORITE_EXISTS',
					"EXISTS(" . $favoriteSubquery->getQuery() . ")",
					['MAILBOX_ID', 'ID'],
				),
			);

			$finalFilter['==IS_FAVORITE_EXISTS'] = true;
		}

		if ($classificationCodes !== [])
		{
			$classificationSubquery = (new Query(MailMessageMarkTable::getEntity()))
				->addFilter('=MAILBOX_ID', new SqlExpression('%s'))
				->addFilter('=MESSAGE_ID', new SqlExpression('%s'))
				->addFilter('==USER_ID', MailMessageMarkTable::SHARED_USER_ID)
				->addFilter('@CODE', array_values($classificationCodes))
			;

			$query->registerRuntimeField(
				'CLASSIFICATION_EXISTS',
				new ExpressionField(
					'CLASSIFICATION_EXISTS',
					"EXISTS(" . $classificationSubquery->getQuery() . ")",
					['MAILBOX_ID', 'ID'],
				),
			);

			$finalFilter['==CLASSIFICATION_EXISTS'] = true;
		}

		if ($unanswered !== null)
		{
			$outgoingReplySubquery = (new Query(MailMessageUidTable::getEntity()))
				->registerRuntimeField(
					new Reference(
						'CLOSURE',
						MessageClosureTable::class,
						[
							'=this.MESSAGE_ID' => 'ref.MESSAGE_ID',
						],
						['join_type' => 'INNER'],
					),
				)
				->registerRuntimeField(
					new Reference(
						'DIR',
						MailboxDirectoryTable::class,
						[
							'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
							'=this.DIR_MD5' => 'ref.DIR_MD5',
						],
						['join_type' => 'INNER'],
					),
				)
				->addFilter('=CLOSURE.PARENT_ID', new SqlExpression('%s'))
				->addFilter('!=CLOSURE.MESSAGE_ID', new SqlExpression('%s'))
				->addFilter('=MAILBOX_ID', new SqlExpression('%s'))
				->addFilter('=DIR.IS_OUTCOME', MailboxDirectoryTable::ACTIVE)
				->addFilter('==DELETE_TIME', 0)
				->addFilter('!@IS_OLD', MailMessageUidTable::HIDDEN_STATUSES)
			;

			$query->registerRuntimeField(
				'HAS_OUTGOING_REPLY',
				new ExpressionField(
					'HAS_OUTGOING_REPLY',
					'EXISTS(' . $outgoingReplySubquery->getQuery() . ')',
					['ID', 'ID', 'MAILBOX_ID'],
				),
			);

			$finalFilter['==HAS_OUTGOING_REPLY'] = !$unanswered;
		}

		return $query
			->setFilter($finalFilter)
			->addGroup('ID')
			->addOrder('FIELD_MAX_SORT', 'DESC')
			->addOrder('ID', 'DESC')
			->setLimit($limit)
			->setOffset($offset)
		;
	}

	/**
	 * Counts distinct messages matching the filter, applying the same visibility
	 * constraints as {@see self::buildMailMessageListQuery}.
	 *
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public static function countMailMessages(array $filter): int
	{
		$query = self::buildMailMessageListQuery($filter);
		$query->setLimit(null);
		$query->setOffset(null);

		return (int)$query->queryCountTotal();
	}

	/**
	 * @param array $itemIds
	 * @param array $filter
	 * @return Query
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws SystemException
	 */
	public static function buildDefaultMessagesDetailsQuery(
		array $itemIds,
		array $filter
	): Query
	{
		self::extractIncludeBindings($filter);
		self::extractExcludeBindings($filter);
		self::extractFavoriteUserId($filter);
		self::extractUnanswered($filter);
		self::extractClassificationCodes($filter);

		$sqlHelper = Application::getConnection()->getSqlHelper();
		$query = MailMessageTable::query()
			->setSelect([
				'UID_ID' => 'MESSAGE_UID.ID',
				'IS_SEEN' => 'MESSAGE_UID.IS_SEEN',
				'MSG_UID' => 'MESSAGE_UID.MSG_UID',
				'IS_OLD' => 'MESSAGE_UID.IS_OLD',
				'DIR_MD5' => 'MESSAGE_UID.DIR_MD5',
				'MESSAGE_ID' => 'ID',
				'OPTIONS',
				'SUBJECT',
				'FIELD_FROM',
				'FIELD_TO',
				'FIELD_DATE',
				'INTERNALDATE' => 'MESSAGE_UID.INTERNALDATE',
				'ATTACHMENTS',
				'BODY',
				'HEADER',
				'MAILBOX_ID',
				'MAILBOX_EMAIL' =>'MAILBOX.EMAIL',
				'BIND_ENTITY_TYPE' => 'MESSAGE_ACCESS.ENTITY_TYPE',
				'BIND_ENTITY_ID' => 'MESSAGE_ACCESS.ENTITY_ID',
				'BIND',
			])
			->registerRuntimeField(
				'MESSAGE_UID',
				new Reference(
					'MESSAGE_UID',
					MailMessageUidTable::class,
					[
						'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
						'=this.ID' => 'ref.MESSAGE_ID',
					],
					['join_type' => 'INNER'],
				),
			)
			->registerRuntimeField(
				'MESSAGE_ACCESS',
				new Reference(
					'MESSAGE_ACCESS',
					MessageAccessTable::class,
					[
						'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
						'=this.ID' => 'ref.MESSAGE_ID',
					],
				)
			)
			->registerRuntimeField(
				new Reference(
					'MAILBOX',
					MailboxTable::class,
					[
						'=this.MAILBOX_ID' => 'ref.ID',
					],
					['join_type' => 'INNER'],
				)
			)
			->registerRuntimeField(
				'BIND',
				new ExpressionField(
					'BIND',
					$sqlHelper->getConcatFunction('%s', "'-'", '%s'),
					[
						'MESSAGE_ACCESS.ENTITY_TYPE',
						'MESSAGE_ACCESS.ENTITY_ID',
					]
				)
			)
			->setFilter(array_merge(
				['@ID' => $itemIds],
				self::VISIBLE_UID_FILTERS,
				$filter,
			))
			->addOrder('MESSAGE_UID.INTERNALDATE', 'DESC')
			->addOrder('MESSAGE_ID', 'DESC')
			->addOrder('MSG_UID')
		;

		if (Main\Loader::includeModule('crm'))
		{
			$query
				->addSelect('MESSAGE_ACCESS.CRM_ACTIVITY.OWNER_TYPE_ID', 'CRM_ACTIVITY_OWNER_TYPE_ID')
				->addSelect('MESSAGE_ACCESS.CRM_ACTIVITY.OWNER_ID', 'CRM_ACTIVITY_OWNER_ID')
				->addSelect('CRM_ACTIVITY_OWNER')
				->registerRuntimeField(
					'CRM_ACTIVITY_OWNER',
					new ORM\Fields\ExpressionField(
						'CRM_ACTIVITY_OWNER',
						$sqlHelper->getConcatFunction('%s', "'-'", '%s'),
						[
							'MESSAGE_ACCESS.CRM_ACTIVITY.OWNER_TYPE_ID',
							'MESSAGE_ACCESS.CRM_ACTIVITY.OWNER_ID',
						],
					)
				)
			;
		}

		return $query;
	}

	/**
	 * @throws LoaderException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public static function buildWebMessagesDetailsQuery(
		array $itemIds,
		array $filter
	): Query
	{
		$query = self::buildDefaultMessagesDetailsQuery($itemIds, $filter);
		$sqlHelper = Application::getConnection()->getSqlHelper();

		$query
			->addSelect('MESSAGE_UID.IS_OLD', 'IS_OLD')
			->addSelect('MESSAGE_UID.DIR_MD5', 'DIR_MD5')
			->addSelect('BIND')
			->registerRuntimeField(
				'BIND',
				new ExpressionField(
					'BIND',
					$sqlHelper->getConcatFunction('%s', "'-'", '%s'),
					[
						'MESSAGE_ACCESS.ENTITY_TYPE',
						'MESSAGE_ACCESS.ENTITY_ID',
					]
				)
			)
		;

		return $query;
	}

	/**
	 * @throws LoaderException
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public static function buildMobileMessagesDetailsQuery(
		array $itemIds,
		array $filter
	): Query
	{
		$query = self::buildDefaultMessagesDetailsQuery($itemIds, $filter);

		$query
			->addSelect('BODY')
			->addSelect('HEADER')
			->addSelect('MAILBOX_ID')
			->addSelect('MAILBOX.EMAIL', 'MAILBOX_EMAIL')
			->addSelect('MESSAGE_ACCESS.ENTITY_TYPE', 'BIND_ENTITY_TYPE')
			->addSelect('MESSAGE_ACCESS.ENTITY_ID', 'BIND_ENTITY_ID')
			->registerRuntimeField(
				new Reference(
					'MAILBOX',
					MailboxTable::class,
					[
						'=this.MAILBOX_ID' => 'ref.ID',
					],
					['join_type' => 'INNER'],
				)
			)
		;

		return $query;
	}

}
