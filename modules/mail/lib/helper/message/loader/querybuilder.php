<?php

namespace Bitrix\Mail\Helper\Message\Loader;

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
	private const VISIBLE_UID_FILTERS = [
		'==MESSAGE_UID.DELETE_TIME' => 0,
		'!@MESSAGE_UID.IS_OLD' => MailMessageUidTable::HIDDEN_STATUSES,
	];

	private const DEFAULT_LIMIT = 26;
	private const DEFAULT_OFFSET = 0;

	/**
	 * @param array $filter
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
		$accessSubquery = (new Query(MessageAccessTable::getEntity()))
			->addFilter('=MAILBOX_ID', new SqlExpression('%s'))
			->addFilter('=MESSAGE_ID', new SqlExpression('%s'))
		;

		$closureSubquery = (new Query(MessageClosureTable::getEntity()))
			->addFilter('=PARENT_ID', new SqlExpression('%s'))
			->addFilter('!=MESSAGE_ID', new SqlExpression('%s'))
		;

		return MailMessageTable::query()
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
					['FIELD_DATE']
				),
			)
			->addSelect('ID', 'DISTINCT_ID')
			->setFilter(array_merge(
				self::VISIBLE_UID_FILTERS,
				$filter,
			))
			->addGroup('ID')
			->addOrder('FIELD_MAX_SORT', 'DESC')
			->addOrder('ID', 'DESC')
			->setLimit($limit)
			->setOffset($offset)
		;
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
			->addOrder('FIELD_DATE', 'DESC')
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
