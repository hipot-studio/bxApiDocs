<?php

namespace Bitrix\Mail\Helper\Message\Loader;

use Bitrix\Mail\Internal\Service\SourceGeneration\GenerationScope;
use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Mail\Internals\MailMessageMarkTable;
use Bitrix\Mail\Internals\MessageAccessTable;
use Bitrix\Mail\Internals\MessageClosureTable;
use Bitrix\Mail\Internals\MessageLabelTable;
use Bitrix\Mail\Internals\UserLabelTable;
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
	public const FILTER_KEY_LABEL = '__MAIL_LABEL_ID';
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
			// The classification pseudo-key does not take the filter off the uid driver: it narrows the
			// letters by MESSAGE_ID, and that is a field of the driver itself.
			if ($key === self::FILTER_KEY_CLASSIFICATION)
			{
				continue;
			}

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
	 * Extracts and unsets the label pseudo-key from $filter.
	 *
	 * @param array $filter passed by reference; the pseudo-key is removed.
	 * @return array{id: int, userId: int}|null Label id with its owner, or null when no label filter is requested.
	 */
	private static function extractLabel(array &$filter): ?array
	{
		$raw = $filter[self::FILTER_KEY_LABEL] ?? null;
		unset($filter[self::FILTER_KEY_LABEL]);

		if (!is_array($raw))
		{
			return null;
		}

		$labelId = (int)($raw['id'] ?? 0);
		$userId = (int)($raw['userId'] ?? 0);

		return $labelId > 0 ? ['id' => $labelId, 'userId' => $userId] : null;
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

	/**
	 * Keeps a read inside the visible uid rows of the ACTIVE source generation of every
	 * mailbox it names. The rows a previous physical source left behind are the technical
	 * identity history of the mailbox, not a fallback source of visibility: a message that
	 * exists in the retained generation only is not listed, searched or counted. A message
	 * present in both stays one row, because the uid of the new generation points at the
	 * same b_mail_message.ID.
	 *
	 * The generations are resolved once per query - and, thanks to the pointer cache of the
	 * scope, once per request - so the condition costs no lookup per message.
	 *
	 * Public so that a read path built outside this class - the search, the thread of the
	 * assistant tools, the chain of the mobile client - states the same condition in the
	 * same words instead of a copy of it.
	 *
	 * @param int[] $mailboxIds Mailboxes the filter is limited to.
	 * @param string $fieldPrefix Path to the uid entity, '' when it drives the query itself.
	 * @return array Conditions to merge into the filter. Empty for a mailbox whose pointer
	 *               names no generation yet, so its read keeps the shape it had before.
	 */
	public static function generationScopeFilter(array $mailboxIds, string $fieldPrefix): array
	{
		if ($mailboxIds === [])
		{
			return [];
		}

		$scoped = [];
		foreach (GenerationScope::forMailboxes($mailboxIds) as $mailboxId => $scope)
		{
			if ($scope->getGenerationIds() !== null)
			{
				$scoped[$mailboxId] = $scope;
			}
		}

		if ($scoped === [])
		{
			return [];
		}

		if (count($mailboxIds) === 1)
		{
			return reset($scoped)->apply([], $fieldPrefix);
		}

		$branches = ['LOGIC' => 'OR'];

		foreach ($scoped as $mailboxId => $scope)
		{
			$branches[] = $scope->apply(['=MAILBOX_ID' => $mailboxId], $fieldPrefix);
		}

		if (count($scoped) < count($mailboxIds))
		{
			// A mailbox that has never been switched keeps every row it has
			$branches[] = ['!@MAILBOX_ID' => array_keys($scoped)];
		}

		return [$branches];
	}

	/**
	 * Mailbox ids the filter is limited to. Only a positive constraint counts, and only a
	 * top-level one: that is the shape {@see MessageFilter} always produces.
	 *
	 * @return int[]
	 */
	private static function extractMailboxIds(array $filter): array
	{
		$ids = [];

		foreach ($filter as $key => $value)
		{
			if (!is_string($key) || !preg_match('/^(=|==|@)?(MESSAGE_UID\.)?MAILBOX_ID$/', $key))
			{
				continue;
			}

			foreach ((array)$value as $mailboxId)
			{
				$ids[] = (int)$mailboxId;
			}
		}

		return array_values(array_unique(array_filter($ids)));
	}

	/**
	 * The same conditions for a read that names its messages rather than their mailboxes.
	 *
	 * @param array $itemIds b_mail_message ids
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public static function generationScopeFilterOfMessages(array $itemIds, string $fieldPrefix): array
	{
		return self::generationScopeFilter(self::resolveMailboxIdsOfMessages($itemIds), $fieldPrefix);
	}

	/**
	 * The mailboxes of the requested messages, for the details entries that address a
	 * message directly and carry no mailbox constraint of their own. Not asked for while
	 * the schema of the feature is missing: there would be nothing to scope by.
	 *
	 * @param array $itemIds b_mail_message ids
	 * @return int[]
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	private static function resolveMailboxIdsOfMessages(array $itemIds): array
	{
		if (empty($itemIds) || !GenerationScope::isSchemaInstalled())
		{
			return [];
		}

		$rows = MailMessageTable::query()
			->addSelect('MAILBOX_ID')
			->whereIn('ID', $itemIds)
			->setDistinct()
			->fetchAll()
		;

		return array_map('intval', array_column($rows, 'MAILBOX_ID'));
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
		$classificationCodes = self::extractClassificationCodes($filter);
		$driverFilter = self::stripUidPrefix($filter);

		$query = MailMessageUidTable::query()
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
				$driverFilter,
				self::generationScopeFilter(self::extractMailboxIds($driverFilter), ''),
			))
			->addGroup('MESSAGE_ID')
			->addOrder('MAX_INTERNALDATE', 'DESC')
			->addOrder('MESSAGE_ID', 'DESC')
			->setLimit($limit)
			->setOffset($offset)
		;

		if ($classificationCodes !== [])
		{
			$query->whereIn(
				'MESSAGE_ID',
				self::classifiedMessageIdsQuery($classificationCodes, self::mailboxIdsFromFilter($driverFilter)),
			);
		}

		return $query;
	}

	/**
	 * @return int[] Mailbox ids the outer filter is already limited to; empty when it names none.
	 */
	private static function mailboxIdsFromFilter(array $filter): array
	{
		$raw = $filter['@MAILBOX_ID'] ?? $filter['=MAILBOX_ID'] ?? null;
		$ids = array_map('intval', (array)$raw);

		return array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
	}

	/**
	 * Narrowed from the marks and not from the letters. The mailbox is repeated here on purpose: it keeps the
	 * subquery a range scan over the marks of that mailbox, while without it the scan spans the marks of the
	 * whole portal and most of what it reads belongs to other mailboxes.
	 *
	 * @param int[] $classificationCodes
	 * @param int[] $mailboxIds
	 */
	private static function classifiedMessageIdsQuery(array $classificationCodes, array $mailboxIds): Query
	{
		$query = MailMessageMarkTable::query()
			->addSelect('MESSAGE_ID')
			->where('USER_ID', MailMessageMarkTable::SHARED_USER_ID)
			->whereIn('CODE', $classificationCodes)
		;

		if ($mailboxIds !== [])
		{
			$query->whereIn('MAILBOX_ID', $mailboxIds);
		}

		return $query;
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
		$label = self::extractLabel($filter);
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

		$finalFilter = array_merge(
			self::VISIBLE_UID_FILTERS,
			$filter,
			self::generationScopeFilter(self::extractMailboxIds($filter), 'MESSAGE_UID.'),
		);

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

		if ($label !== null)
		{
			$labelSubquery = (new Query(MessageLabelTable::getEntity()))
				->registerRuntimeField(
					new Reference(
						'USER_LABEL',
						UserLabelTable::class,
						[
							'=this.LABEL_ID' => 'ref.ID',
						],
						['join_type' => 'INNER'],
					),
				)
				->addFilter('=MAILBOX_ID', new SqlExpression('%s'))
				->addFilter('=MESSAGE_ID', new SqlExpression('%s'))
				->addFilter('=LABEL_ID', $label['id'])
				->addFilter('=USER_LABEL.USER_ID', $label['userId'])
			;

			$query->registerRuntimeField(
				'LABEL_BINDING_EXISTS',
				new ExpressionField(
					'LABEL_BINDING_EXISTS',
					"EXISTS(" . $labelSubquery->getQuery() . ")",
					['MAILBOX_ID', 'ID'],
				),
			);

			$finalFilter['==LABEL_BINDING_EXISTS'] = true;
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

			/*
				After the conditions carrying the placeholders: they are bound in the order they
				appear. The folder is scoped as well as the placement - both generations keep a
				folder of the same path, and the roles are picked for the prepared one, so an
				unscoped join would read IS_OUTCOME from the folder of the retained generation.
			*/
			foreach (['', 'DIR.'] as $scopedEntity)
			{
				foreach (self::generationScopeFilter(self::extractMailboxIds($filter), $scopedEntity) as $key => $condition)
				{
					$outgoingReplySubquery->addFilter(is_int($key) ? null : $key, $condition);
				}
			}

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
		array $filter,
		bool $withBindings = true,
	): Query
	{
		self::extractIncludeBindings($filter);
		self::extractExcludeBindings($filter);
		self::extractLabel($filter);
		self::extractFavoriteUserId($filter);
		self::extractUnanswered($filter);
		self::extractClassificationCodes($filter);
		if (!$withBindings)
		{
			unset(
				$filter['=MESSAGE_ACCESS.ENTITY_TYPE'],
				$filter['==MESSAGE_ACCESS.ENTITY_TYPE'],
			);
		}

		$mailboxIds = self::extractMailboxIds($filter);
		if ($mailboxIds === [])
		{
			$mailboxIds = self::resolveMailboxIdsOfMessages($itemIds);
		}

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
				new Reference(
					'MAILBOX',
					MailboxTable::class,
					[
						'=this.MAILBOX_ID' => 'ref.ID',
					],
					['join_type' => 'INNER'],
				)
			)
			->setFilter(array_merge(
				['@ID' => $itemIds],
				self::VISIBLE_UID_FILTERS,
				$filter,
				self::generationScopeFilter($mailboxIds, 'MESSAGE_UID.'),
			))
			->addOrder('MESSAGE_UID.INTERNALDATE', 'DESC')
			->addOrder('MESSAGE_ID', 'DESC')
			->addOrder('MSG_UID')
		;

		if ($withBindings)
		{
			$query
				->addSelect('MESSAGE_ACCESS.ENTITY_TYPE', 'BIND_ENTITY_TYPE')
				->addSelect('MESSAGE_ACCESS.ENTITY_ID', 'BIND_ENTITY_ID')
				->addSelect('BIND')
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
			;

			$sqlHelper = Application::getConnection()->getSqlHelper();
			$query->registerRuntimeField(
				'BIND',
				new ExpressionField(
					'BIND',
					$sqlHelper->getConcatFunction('%s', "'-'", '%s'),
					[
						'MESSAGE_ACCESS.ENTITY_TYPE',
						'MESSAGE_ACCESS.ENTITY_ID',
					]
				)
			);

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
		$query = self::buildDefaultMessagesDetailsQuery($itemIds, $filter, false);

		$query
			->addSelect('MESSAGE_UID.IS_OLD', 'IS_OLD')
			->addSelect('MESSAGE_UID.DIR_MD5', 'DIR_MD5')
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
