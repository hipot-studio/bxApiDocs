<?php

namespace Bitrix\Mail;

use Bitrix\Main;
use Bitrix\Mail\Helper\Message\MessageInternalDateHandler;
use Bitrix\Mail\Internal\Service\Message\Conversation\ConversationBackfill;
use Bitrix\Mail\Internal\Service\SourceGeneration\GenerationScope;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationActionGuard;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationMetrics;

class Helper
{
	/** Seconds until the next pass of the vacation auto-reply check interval agent when a pack came out full. */
	private const VACATION_AUTO_REPLY_NEXT_PACK_DELAY = 60;

	public static function syncAllDirsInMailboxForTheFirstSyncDayAgent()
	{
		$userMailboxes = \Bitrix\Mail\MailboxTable::getList([
			'select' => [
				'ID'
			],
			'filter' => [
				'=ACTIVE' => 'Y',
				'=SERVER_TYPE' => 'imap',
			],
		])->fetchAll();

		if (empty($userMailboxes))
		{
			return '';
		}

		$numberOfUnSynchronizedMailboxes = count($userMailboxes);

		foreach ($userMailboxes as $mailbox)
		{
			$mailboxID = $mailbox['ID'];
			$mailboxHelper = Helper\Mailbox::createInstance($mailboxID, false);
			if (empty($mailboxHelper))
			{
				$numberOfUnSynchronizedMailboxes--;
				continue;
			}

			$keyRow = [
				'MAILBOX_ID' => $mailboxID,
				'ENTITY_TYPE' => 'MAILBOX',
				'ENTITY_ID' => $mailboxID,
				'PROPERTY_NAME' => 'SYNC_FIRST_DAY',
			];

			$filter = [
				'=MAILBOX_ID' => $keyRow['MAILBOX_ID'],
				'=ENTITY_TYPE' => $keyRow['ENTITY_TYPE'],
				'=ENTITY_ID' => $keyRow['ENTITY_ID'],
				'=PROPERTY_NAME' => $keyRow['PROPERTY_NAME'],
			];

			$startValue = 'started_for_id_'.$mailboxID;

			if(\Bitrix\Mail\Internals\MailEntityOptionsTable::getCount($filter))
			{
				if(Internals\MailEntityOptionsTable::getList([
					'select' => [
						'VALUE',
					],
					'filter' => $filter,
				])->fetchAll()[0]['VALUE'] !== 'completed')
				{
					\Bitrix\Mail\Internals\MailEntityOptionsTable::update(
						$keyRow,
						['VALUE' => $startValue]
					);

					$synchronizationSuccess = $mailboxHelper->syncFirstDay();

					if($synchronizationSuccess)
					{
						\Bitrix\Mail\Internals\MailEntityOptionsTable::update(
							$keyRow,
							['VALUE' => 'completed']
						);
						$numberOfUnSynchronizedMailboxes--;
					}
				}
				else
				{
					$numberOfUnSynchronizedMailboxes--;
				}
			}
			else
			{
				$fields = $keyRow;
				$fields['VALUE'] = $startValue;
				\Bitrix\Mail\Internals\MailEntityOptionsTable::add(
					$fields
				);
				$synchronizationSuccess = $mailboxHelper->syncFirstDay();

				if($synchronizationSuccess)
				{
					\Bitrix\Mail\Internals\MailEntityOptionsTable::update(
						$keyRow,
						['VALUE' => 'completed']
					);
					$numberOfUnSynchronizedMailboxes--;
				}
			}
		}

		if($numberOfUnSynchronizedMailboxes === 0)
		{
			return '';
		}
		else
		{
			return 'Bitrix\Mail\Helper::syncAllDirsInMailboxForTheFirstSyncDayAgent();';
		}
	}

	public static function syncMailboxAgent($id)
	{
		$mailboxHelper = Helper\Mailbox::createInstance($id, false);

		if (empty($mailboxHelper))
		{
			return '';
		}

		$mailbox = $mailboxHelper->getMailbox();

		if ($mailbox['OPTIONS']['next_sync'] <= time())
		{
			$mailboxHelper->sync();

			$mailbox = $mailboxHelper->getMailbox();
		}

		global $pPERIOD;

		$pPERIOD = min($pPERIOD, max($mailbox['OPTIONS']['next_sync'] - time(), 60));

		return sprintf('Bitrix\Mail\Helper::syncMailboxAgent(%u);', $id);
	}

	public static function syncOutgoingAgent($id)
	{
		$mailboxHelper = Helper\Mailbox::createInstance($id, false);

		if ($mailboxHelper)
		{
			$mailboxHelper->syncOutgoing();
		}

		return '';
	}

	public static function markOldMessagesAgent()
	{
		$userMailboxes = \Bitrix\Mail\MailboxTable::getList([
			'select' => [
				'ID'
			],
			'filter' => [
				'=ACTIVE' => 'Y',
				'=SERVER_TYPE' => 'imap',
			],
		])->fetchAll();

		if (empty($userMailboxes))
		{
			return '';
		}

		$numberOfUnSynchronizedMailboxes = count($userMailboxes);

		foreach ($userMailboxes as $mailbox)
		{
			$mailboxID = $mailbox['ID'];
			$mailboxHelper = Helper\Mailbox::createInstance($mailboxID, false);
			if (empty($mailboxHelper))
			{
				$numberOfUnSynchronizedMailboxes--;
				continue;
			}

			$keyRow = [
				'MAILBOX_ID' => $mailboxID,
				'ENTITY_TYPE' => 'MAILBOX',
				'ENTITY_ID' => $mailboxID,
				'PROPERTY_NAME' => 'SYNC_IS_OLD_STATUS',
			];

			$filter = [
				'=MAILBOX_ID' => $keyRow['MAILBOX_ID'],
				'=ENTITY_TYPE' => $keyRow['ENTITY_TYPE'],
				'=ENTITY_ID' => $keyRow['ENTITY_ID'],
				'=PROPERTY_NAME' => $keyRow['PROPERTY_NAME'],
			];

			$startValue = 'started_for_id_'.$mailboxID;

			if(\Bitrix\Mail\Internals\MailEntityOptionsTable::getCount($filter))
			{
				if(Internals\MailEntityOptionsTable::getList([
						'select' => [
							'VALUE',
						],
						'filter' => $filter,
					])->fetchAll()[0]['VALUE'] !== 'completed')
				{
					\Bitrix\Mail\Internals\MailEntityOptionsTable::update(
						$keyRow,
						['VALUE' => $startValue]
					);

					$synchronizationSuccess = $mailboxHelper->resyncIsOldStatus();

					if($synchronizationSuccess)
					{
						\Bitrix\Mail\Internals\MailEntityOptionsTable::update(
							$keyRow,
							['VALUE' => 'completed']
						);
						$numberOfUnSynchronizedMailboxes--;
					}
				}
				else
				{
					$numberOfUnSynchronizedMailboxes--;
				}
			}
			else
			{
				$fields = $keyRow;
				$fields['VALUE'] = $startValue;
				\Bitrix\Mail\Internals\MailEntityOptionsTable::add(
					$fields
				);
				$synchronizationSuccess = $mailboxHelper->resyncIsOldStatus();

				if($synchronizationSuccess)
				{
					\Bitrix\Mail\Internals\MailEntityOptionsTable::update(
						$keyRow,
						['VALUE' => 'completed']
					);
					$numberOfUnSynchronizedMailboxes--;
				}
			}
		}

		if($numberOfUnSynchronizedMailboxes === 0)
		{
			return '';
		}
		else
		{
			return 'Bitrix\Mail\Helper::markOldMessagesAgent();';
		}
	}

	/**
	 * @param int $checkLettersWithId
	 * @param int $packSize
	 * @return string
	 * @throws Main\ArgumentException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 *
	 * Recommended agent call parameters:
	 * CAgent::addAgent('Bitrix\Mail\Helper::removeUnattachedMessagesAgent();','mail', 'N', 3600);
	 */
	public static function removeUnattachedMessagesAgent(int $checkLettersWithId = 0, int $packSize = 40): string
	{
		$queryMessage = new \Bitrix\Main\ORM\Query\Query(\Bitrix\Mail\MailMessageTable::getEntity());

		$deleteMessagesOlderThan = (new \Bitrix\Main\Type\DateTime())->add('- 3 days');

		$queryMessage
			->setSelect([
				'ID',
				'MAILBOX_ID',
			])
			->where(\Bitrix\Main\ORM\Query\Query::filter()
				->logic('and')
				->where('DATE_INSERT', '<=', $deleteMessagesOlderThan)
				->where('ID', '>', $checkLettersWithId)
			)
			->setOrder(['ID' => 'ASC'])
			->setLimit($packSize);

		$rowsMailMessage = $queryMessage->fetchAll();

		if (empty($rowsMailMessage))
		{
			return '';
		}

		$messageIds = array_map('intval', array_column($rowsMailMessage, 'ID'));

		$maxId = max($messageIds);

		$queryMailMessageUid = new \Bitrix\Main\ORM\Query\Query(\Bitrix\Mail\MailMessageUidTable::getEntity());

		$queryMailMessageUid
			->setSelect(['MESSAGE_ID'])
			->setFilter([
				'@MESSAGE_ID' => $messageIds,
			]);

		$rowsMailMessageUid = $queryMailMessageUid->fetchAll();
		$existingMessageIds = array_map('intval', array_column($rowsMailMessageUid, 'MESSAGE_ID'));

		$messageIdsWithoutUid = array_map('intval', array_values(array_diff($messageIds, $existingMessageIds)));

		$mailboxIdByMessageId = array_column($rowsMailMessage, 'MAILBOX_ID', 'ID');

		$deletedByMailbox = [];
		foreach($messageIdsWithoutUid as $messageId)
		{
			$mailboxId = (int)$mailboxIdByMessageId[$messageId];
			\CMailMessage::delete($messageId, $mailboxId);
			$deletedByMailbox[$mailboxId][] = $messageId;
		}

		// The letters are gone for good, so the conversation projection may not keep them either
		$conversationBackfill = new ConversationBackfill();
		foreach ($deletedByMailbox as $mailboxId => $deletedMessageIds)
		{
			$conversationBackfill->removeMessages($mailboxId, $deletedMessageIds);
		}

		return sprintf('Bitrix\Mail\Helper::removeUnattachedMessagesAgent(%u, %u);', $maxId, $packSize);
	}

	/**
	 * Every physical trace of a logical message, in all source generations of its mailbox:
	 * the placements, the queue rows that address them and the matching fingerprints. Called
	 * while the message itself is being deleted, so it stays silent: the deletion event
	 * belongs to the paths where a placement disappears and the message survives.
	 *
	 * @param int $mailboxId Pass it when the caller knows it; resolved from the placements otherwise.
	 */
	public static function deleteMessagePlacements(int $messageId, int $mailboxId = 0): void
	{
		if ($messageId <= 0)
		{
			return;
		}

		$connection = Main\Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();

		$placements = $connection->query(sprintf(
			'SELECT ID, MAILBOX_ID FROM b_mail_message_uid WHERE MESSAGE_ID = %u',
			$messageId,
		))->fetchAll();

		$queued = [];
		foreach ($placements as $placement)
		{
			$queued[(int)$placement['MAILBOX_ID']][] = "'" . $sqlHelper->forSql((string)$placement['ID']) . "'";
		}

		foreach ($queued as $queueMailboxId => $placementIds)
		{
			/*
				An outgoing letter may still be waiting for the upload of one of these
				placements. The whole letter goes in one statement: the mass callers of this
				method walk every letter of a mailbox, so a query per placement is a query
				per placement of every letter of it.
			*/
			$connection->queryExecute(sprintf(
				'DELETE FROM b_mail_message_upload_queue WHERE MAILBOX_ID = %u AND ID IN (%s)',
				$queueMailboxId,
				implode(', ', $placementIds),
			));
		}

		if (!empty($placements))
		{
			$connection->queryExecute(sprintf('DELETE FROM b_mail_message_uid WHERE MESSAGE_ID = %u', $messageId));
		}

		$mailboxId = $mailboxId > 0 ? $mailboxId : (int)($placements[0]['MAILBOX_ID'] ?? 0);

		if ($mailboxId <= 0)
		{
			return;
		}

		$connection->queryExecute(sprintf(
			'DELETE FROM b_mail_message_delete_queue WHERE MAILBOX_ID = %u AND MESSAGE_ID = %u',
			$mailboxId,
			$messageId,
		));

		try
		{
			/*
				A fingerprint left behind would keep answering the matching of the next
				migration, and the imported letter would resolve to a local identity that
				does not exist anymore. The table comes with the source generation update,
				so its absence is tolerated the same way the backfill tolerates it.
			*/
			$connection->queryExecute(sprintf(
				'DELETE FROM b_mail_message_fingerprint WHERE MAILBOX_ID = %u AND MESSAGE_ID = %u',
				$mailboxId,
				$messageId,
			));
		}
		catch (\Throwable)
		{
			self::countGenerationCleanupError($mailboxId);
		}

		/*
			A cleanup of its own on purpose: one of the two failing must not take the other with it.
			A terminal matching result left behind would be reused by the next import of the same uid -
			the matcher answers with the stored decision before it decides anything - and the new
			placement would start serving a letter that is gone: after the switch that letter is
			invisible. The candidate list of such a result hangs on its row and goes with it.
		*/
		try
		{
			$matchIds = array_column(
				$connection->query(sprintf(
					'SELECT ID FROM b_mail_source_generation_match WHERE MAILBOX_ID = %u AND MESSAGE_ID = %u',
					$mailboxId,
					$messageId,
				))->fetchAll(),
				'ID',
			);

			if ($matchIds !== [])
			{
				/*
					The candidate list of a matching result is written to b_mail_entity_data, so it is
					removed from there. The ids are collected first and the rows are named by them: the
					column holding them is a string one, so a subquery would compare a string with a
					number - portable on one database engine and an error on another.
				*/
				$connection->queryExecute(sprintf(
					"DELETE FROM b_mail_entity_data WHERE MAILBOX_ID = %u AND ENTITY_TYPE = '%s' AND ENTITY_ID IN (%s)",
					$mailboxId,
					$sqlHelper->forSql(Internals\MailEntityOptionsTable::SOURCE_GENERATION_MATCH_TYPE_NAME),
					implode(', ', array_map(
						static fn ($id): string => "'" . $sqlHelper->forSql((string)$id) . "'",
						$matchIds,
					)),
				));

				$connection->queryExecute(sprintf(
					'DELETE FROM b_mail_source_generation_match WHERE MAILBOX_ID = %u AND MESSAGE_ID = %u',
					$mailboxId,
					$messageId,
				));
			}
		}
		catch (\Throwable)
		{
			self::countGenerationCleanupError($mailboxId);
		}
	}

	/**
	 * A fingerprint that could not be removed is a cleanup error of the generation data of
	 * the mailbox: the next migration would match an imported letter against a message
	 * that is gone. Counted with the active generation and never in the way of a deletion -
	 * a mailbox whose schema knows nothing about generations counts nothing.
	 */
	private static function countGenerationCleanupError(int $mailboxId): void
	{
		try
		{
			$generationId = GenerationScope::forMailbox($mailboxId)->getStampGenerationId();

			if ($generationId > 0)
			{
				// The cleanup belongs to no migration operation of its own
				$metrics = new MigrationMetrics($mailboxId, $generationId, '', MigrationMetrics::SCOPE_CLEANUP);
				$metrics->countCleanupError();
				$metrics->save();
			}
		}
		catch (\Throwable)
		{
		}
	}

	public static function repairCrmImapFilterAgent(int $lastMailboxId = 0, int $packSize = 100): string
	{
		return '';
	}

	public static function cleanupMailboxAgent($id)
	{
		Helper\OrphanedMailboxLifecycle::tick((int)$id);

		$mailboxHelper = Helper\Mailbox::rawInstance($id, false);

		if (empty($mailboxHelper))
		{
			return '';
		}

		$mailboxHelper->setCheckpoint();

		$remoteDismissed = $mailboxHelper->dismissRemoteMessages();

		$stage1 = $mailboxHelper->dismissOldMessages();
		$stage2 = $mailboxHelper->dismissDeletedUidMessages();
		$stage3 = $mailboxHelper->cleanup();

		global $pPERIOD;

		$pPERIOD = min($pPERIOD, max($remoteDismissed && $stage1 && $stage2 && $stage3 ? $pPERIOD : 600, 60));

		if ($pPERIOD === null)
		{
			$pPERIOD = 60;
		}

		return sprintf('Bitrix\Mail\Helper::cleanupMailboxAgent(%u);', $id);
	}

	/**
	 * Keeps the check interval of the mailboxes in step with their vacation auto-reply periods: gives their
	 * own interval back to those whose period is over and shortens the interval of those whose period is
	 * about to start.
	 *
	 * Switching the auto-reply off restores the interval right away, but a user who leaves the portal for
	 * good would otherwise leave the mailbox on the shortened interval forever.
	 *
	 * The switch of the feature does not stop the restoring pass: it only gives a mailbox its own interval
	 * back and turns nothing on. A feature switched off closes the seam, the receiver and saving the
	 * setting, so a mailbox shortened by a period would have nobody left to free it - and would keep
	 * checking its mail server every ten minutes for good. With the feature off every remembered interval
	 * goes back at once, and switching the feature on again shortens the periods that are still running.
	 */
	public static function restoreVacationAutoReplyCheckIntervalAgent(): string
	{
		$agentCall = 'Bitrix\Mail\Helper::restoreVacationAutoReplyCheckIntervalAgent();';
		$adjuster = new Service\VacationAutoReply\MailboxCheckIntervalAdjuster();

		$shortened = 0;

		if (Helper\Config\Feature::isVacationAutoReplyAvailable())
		{
			$restored = $adjuster->restoreExpired();
			// A period saved long in advance shortens nothing when it is saved, so the interval of a
			// mailbox whose vacation is about to start is shortened here
			$shortened = $adjuster->shortenStarting();
		}
		else
		{
			$restored = $adjuster->restoreAll();
		}

		if (
			$restored >= Service\VacationAutoReply\MailboxCheckIntervalAdjuster::AGENT_PACK_SIZE
			|| $shortened >= Service\VacationAutoReply\MailboxCheckIntervalAdjuster::AGENT_PACK_SIZE
		)
		{
			// A full pack leaves more mailboxes waiting, and each of them keeps its shortened interval
			// until a pass reaches it: an hour between passes is a day per 12 000 mailboxes
			global $pPERIOD;
			$pPERIOD = $pPERIOD === null
				? self::VACATION_AUTO_REPLY_NEXT_PACK_DELAY
				: min((int)$pPERIOD, self::VACATION_AUTO_REPLY_NEXT_PACK_DELAY)
			;
		}

		return $agentCall;
	}

	/**
	 * @deprecated
	 */
	public static function resortTreeAgent($id)
	{
		$mailboxHelper = Helper\Mailbox::createInstance($id, false);

		if ($mailboxHelper)
		{
			$mailboxHelper->resortTree();
		}

		return '';
	}

	public static function deleteMailboxAgent($id)
	{
		return \CMailbox::delete($id) ? '' : sprintf('Bitrix\Mail\Helper::deleteMailboxAgent(%u);', $id);
	}

	public static function resyncDomainUsersAgent()
	{
		$res = MailServicesTable::getList(array(
			'filter' => array(
				'=ACTIVE'       => 'Y',
				'@SERVICE_TYPE' => array('domain', 'crdomain'),
			)
		));
		while ($item = $res->fetch())
		{
			if ($item['SERVICE_TYPE'] == 'domain')
			{
				$lockName = sprintf('domain_users_sync_lock_%u', $item['ID']);
				$syncLock = \Bitrix\Main\Config\Option::get('mail', $lockName, 0);

				if ($syncLock < time()-3600)
				{
					\Bitrix\Main\Config\Option::set('mail', $lockName, time());
					\CMailDomain2::getDomainUsers($item['TOKEN'], $item['SERVER'], $error, true);
					\Bitrix\Main\Config\Option::set('mail', $lockName, 0);
				}
			}
			else if ($item['SERVICE_TYPE'] == 'crdomain')
			{
				\CControllerClient::executeEvent('OnMailControllerResyncMemberUsers', array('DOMAIN' => $item['SERVER']));
			}
		}

		return 'Bitrix\Mail\Helper::resyncDomainUsersAgent();';
	}

	public static function syncMailbox($id, &$error)
	{
		$mailboxHelper = Helper\Mailbox::createInstance($id, false);

		return empty($mailboxHelper) ? false : $mailboxHelper->sync();
	}

	public static function listImapDirs($mailbox, &$error = [], &$errors = null)
	{
		$error  = null;
		$errors = null;

		$client = static::createClient($mailbox);

		$list   = $client->listMailboxes('*', $error, true);
		$errors = $client->getErrors();

		if ($list === false)
			return false;

		$k = count($list);
		for ($i = 0; $i < $k; $i++)
		{
			$item = $list[$i];

			$list[$i] = array(
				'path' => $item['name'],
				'name' => $item['title'],
				'level' => $item['level'],
				'disabled' => (bool) preg_grep('/^ \x5c Noselect $/ix', $item['flags']),
				'income' => mb_strtolower($item['name']) == 'inbox',
				'outcome' => (bool) preg_grep('/^ \x5c Sent $/ix', $item['flags']),
			);
		}

		return $list;
	}

	public static function getImapUIDsForSpecificDay($mailboxID, $dirPath = 'inbox', $internalDate)
	{
		$error  = null;
		$errors = null;

		$mailbox = Helper\Mailbox::prepareMailbox([
			'=ID'=>$mailboxID,
			'=ACTIVE'=>'Y'
		]);

		$client = static::createClient($mailbox);

		$result = $client->getUIDsForSpecificDay($dirPath, $internalDate);

		return $result;
	}

	/**
	 * The boundary the IS_OLD marking of a run works from. Both generations of a switched
	 * mailbox keep folders of the same path, so an unfinished placement of a retained one
	 * would set the boundary of the active generation and drop the wrong messages out of
	 * its counters.
	 *
	 * @param GenerationScope|null $generationScope The scope of the run; the active generation
	 *                                              of the mailbox when the caller has none.
	 */
	public static function getLastDeletedOldMessageInternaldate(
		$mailboxId,
		$dirPath,
		$filter = [],
		?GenerationScope $generationScope = null
	)
	{
		$generationScope ??= GenerationScope::forMailbox((int)$mailboxId);

		$firstSyncUID = MailMessageUidTable::getList(
			[
				'select' => [
					'INTERNALDATE'
				],
				'filter' => $generationScope->apply(array_merge(
					[
						'!=IS_OLD' => 'D',
						'=MESSAGE_ID' => 0,
						'=MAILBOX_ID' => $mailboxId,
						'=DIR_MD5' => md5($dirPath),
					],
					$filter
				)),
				'order' => [
					'INTERNALDATE' => 'DESC',
				],
				'limit' => 1,
			]
		)->fetchAll();

		if(isset($firstSyncUID[0]['INTERNALDATE']))
		{
			return $firstSyncUID[0]['INTERNALDATE'];
		}
		else
		{
			return false;
		}
	}

	public static function getStartInternalDateForDir(
		$mailboxId,
		$dirPath,
	)
	{
		$startInternalDate =  MessageInternalDateHandler::getStartInternalDateForDir($mailboxId, $dirPath);

		return $startInternalDate ?? false;
	}

	public static function getImapUnseenSyncForDir($mailbox = null, $dirPath ,$mailboxID = null)
	{
		//for testing via mailbox id
		if(is_int($mailboxID) && is_null($mailbox))
		{
			$mailbox = Helper\Mailbox::prepareMailbox([
				'=ID'=>$mailboxID,
				'=ACTIVE'=>'Y'
			]);
		}

		$startInternalDate = static::getStartInternalDateForDir($mailbox['ID'], $dirPath);

		if($startInternalDate)
		{
			$error = [];
			$errors = [];
			return static::getImapUnseen($mailbox, $dirPath,$error,$errors, $startInternalDate);
		}
		else
		{
			return 0;
		}

		return false;
	}

	public static function setMailboxUnseenCounter($mailboxId, $count): void
	{
		$keyRow = [
			'MAILBOX_ID' => $mailboxId,
			'ENTITY_TYPE' => 'MAILBOX',
			'ENTITY_ID' => $mailboxId
		];

		$filter = [
			'=MAILBOX_ID' => $keyRow['MAILBOX_ID'],
			'=ENTITY_TYPE' => $keyRow['ENTITY_TYPE'],
			'=ENTITY_ID' => $keyRow['ENTITY_ID']
		];

		if ($count < 0)
		{
			$count = 0;
		}

		$rowValue = ['VALUE' => $count];

		$row = Internals\MailCounterTable::getRow(
			[
				'filter' => $filter,
				'select' => ['VALUE'],
			]
		);

		$counterHasChanged = false;

		if (!is_null($row))
		{
			if ((int)$row['VALUE'] !== $count)
			{
				Internals\MailCounterTable::update($keyRow, $rowValue);
				$counterHasChanged = true;
			}
		}
		else
		{
			Internals\MailCounterTable::add(array_merge($rowValue,$keyRow));
			$counterHasChanged = true;
		}

		if ($counterHasChanged && Main\Loader::includeModule('pull'))
		{
			\CPullWatch::addToStack(
				'mail_mailbox_' .$mailboxId,
				[
					'module_id' => 'mail',
					'params' => [
						'mailboxId' => $mailboxId,
					],
					'command' => 'counters_updated',
				]
			);
			\Bitrix\Pull\Event::send();
		}
	}

	public static function updateMailboxUnseenCounter($mailboxId)
	{
		$parameters = [
			'filter' => [
				'ENTITY_TYPE' => 'DIR',
				'MAILBOX_ID' => $mailboxId,
			],
			'runtime' => [
				new \Bitrix\Main\Entity\ExpressionField('COUNT', 'SUM(%s)', 'VALUE'),
			],
			'select' => [
				'COUNT'
			]
		];

		$scope = GenerationScope::servingMailbox((int)$mailboxId);
		if (!$scope->isUnfiltered())
		{
			/*
				A folder of another generation keeps its own counter row, and its path is the
				same as that of the folder serving the user now, so the rows can only be told
				apart by the generation of the folder each of them addresses. The folders are
				read separately rather than joined: ENTITY_ID of a counter is a string while
				the folder id is an integer, and no portable comparison of the two exists -
				PostgreSQL has no operator for it at all. A mailbox holds units of folders, so
				the list stays short and the condition stays in the query.
			*/
			$directoryIds = array_column(
				Internals\MailboxDirectoryTable::getList([
					'select' => ['ID'],
					'filter' => $scope->apply(['=MAILBOX_ID' => (int)$mailboxId]),
				])->fetchAll(),
				'ID',
			);

			if (empty($directoryIds))
			{
				return;
			}

			$parameters['filter']['@ENTITY_ID'] = array_map('strval', $directoryIds);
		}

		$count = Internals\MailCounterTable::getList($parameters)->fetchAll();

		if(!is_null($count[0]["COUNT"]))
		{
			static::setMailboxUnseenCounter($mailboxId,(int)$count[0]["COUNT"]);
		}
	}

	public static function updateMailCounters($mailbox)
	{
		$mailboxId = $mailbox['ID'];
		$directoryHelper = new Helper\MailboxDirectoryHelper($mailboxId);
		$syncDirs = $directoryHelper->getSyncDirs();
		$totalCount = 0;
		$folderCountersForAdding = [];

		//Since we work with internalDate inside the method
		\CTimeZone::Disable();

		foreach ($syncDirs as $dir)
		{
			if($dir->isInvisibleToCounters())
			{
				continue;
			}

			$count = static::getImapUnseenSyncForDir($mailbox, $dir->getPath());

			if (is_int($count))
			{
				$folderCountersForAdding[$dir->getId()] = $count;
				$totalCount += $count;
			}
		}

		\CTimeZone::Enable();

		$currentFolderCounters = Internals\MailCounterTable::getList([
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'=ENTITY_TYPE' => 'DIR',
				'=ENTITY_ID' =>  array_keys($folderCountersForAdding)
			],
			'select' => [
				'ENTITY_ID',
				'VALUE'
			]
		]);

		$folderCountersForUpdating = [];

		while ($folderCounter = $currentFolderCounters->fetch())
		{
			$folderId = (int)$folderCounter['ENTITY_ID'];
			$folderCount = (int)$folderCounter['VALUE'];

			if (isset($folderCountersForAdding[$folderId]))
			{
				if ($folderCountersForAdding[$folderId] !== $folderCount)
				{
					$folderCountersForUpdating[$folderId] = $folderCountersForAdding[$folderId];
				}

				unset($folderCountersForAdding[$folderId]);
			}
		}

		foreach ($folderCountersForAdding as $id => $count)
		{
			if ($count <= 0)
			{
				continue;
			}

			Internals\MailCounterTable::add(
				[
					'MAILBOX_ID' => $mailboxId,
					'ENTITY_TYPE' => 'DIR',
					'ENTITY_ID' => $id,
					'VALUE' => $count,
				]
			);
		}

		foreach ($folderCountersForUpdating as $id => $count)
		{
			Internals\MailCounterTable::update(
				[
					'MAILBOX_ID' => $mailboxId,
					'ENTITY_TYPE' => 'DIR',
					'ENTITY_ID' => $id,
				],
				[
					'VALUE' => $count,
				]
			);
		}

		return $totalCount;
	}

	public static function getImapUnseen($mailbox, $dirPath = 'inbox', &$error = [], &$errors = null, $startInternalDate = null)
	{
		$error  = null;
		$errors = null;

		$client = static::createClient($mailbox);

		$result = $client->getUnseen($dirPath, $error, $startInternalDate);
		$errors = $client->getErrors();

		return $result;
	}

	public static function addImapMessage($id, $data, &$error)
	{
		$error = null;

		$id = (int) (is_array($id) ? $id['ID'] : $id);

		$mailbox = MailboxTable::getList(array(
			'filter' => array('ID' => $id, 'ACTIVE' => 'Y'),
			'select' => array('*', 'LANG_CHARSET' => 'SITE.CULTURE.CHARSET')
		))->fetch();

		if (empty($mailbox))
			return;

		if (!in_array($mailbox['SERVER_TYPE'], array('imap', 'controller', 'domain', 'crdomain')))
			return;

		if (in_array($mailbox['SERVER_TYPE'], array('controller', 'crdomain')))
		{
			// @TODO: request controller
			$result = \CMailDomain2::getImapData();

			$mailbox['SERVER']  = $result['server'];
			$mailbox['PORT']    = $result['port'];
			$mailbox['USE_TLS'] = $result['secure'];
		}
		elseif ($mailbox['SERVER_TYPE'] == 'domain')
		{
			$result = \CMailDomain2::getImapData();

			$mailbox['SERVER']  = $result['server'];
			$mailbox['PORT']    = $result['port'];
			$mailbox['USE_TLS'] = $result['secure'];
		}

		$client = static::createClient($mailbox, $mailbox['LANG_CHARSET'] ?: $mailbox['CHARSET']);

		$dir = MailboxDirectory::fetchOneOutcome($mailbox['ID']);
		$path = $dir ? $dir->getPath() : 'INBOX';

		return $client->addMessage($path, $data, $error);
	}

	public static function updateImapMessage($userId, $hash, $data, &$error)
	{
		$error = null;

		$items = MailMessageUidTable::getList(array(
			'select' => array(
				'ID', 'MAILBOX_ID', 'IS_SEEN', 'GENERATION_ID',
				'MAILBOX_USER_ID' => 'MAILBOX.USER_ID',
				'MAILBOX_OPTIONS' => 'MAILBOX.OPTIONS',
			),
			'filter' => array(
				'=HEADER_MD5'  => $hash,
				'==DELETE_TIME' => 0,
			),
		))->fetchAll();

		$mailboxIds = array_column($items, 'MAILBOX_ID');
		// The active generations and migration states of the mailboxes involved are resolved together, once
		$scopes = GenerationScope::forMailboxes($mailboxIds);
		$migrationGuards = (new MigrationActionGuard())->checkMany($mailboxIds);

		foreach ($items as $item)
		{
			$mailboxId = (int)$item['MAILBOX_ID'];
			$scope = $scopes[$mailboxId] ?? null;
			$migrationGuard = $migrationGuards[$mailboxId] ?? null;

			// The same header hash matches the rows of every generation; only the active one is writable
			if (
				$scope === null
				|| !$scope->includes((int)$item['GENERATION_ID'])
				|| $migrationGuard === null
				|| !$migrationGuard->isSuccess()
			)
			{
				continue;
			}

			$isOwner = $item['MAILBOX_USER_ID'] == $userId;
			$isPublic = in_array('crm_public_bind', (array) $item['MAILBOX_OPTIONS']['flags']);
			$inQueue = in_array($userId, (array) $item['MAILBOX_OPTIONS']['crm_lead_resp']);
			if (!$isOwner && !$isPublic && !$inQueue)
			{
				continue;
			}

			if (in_array($item['IS_SEEN'], array('Y', 'S')) != $data['seen'])
			{
				MailMessageUidTable::update(
					array(
						'ID' => $item['ID'],
						'MAILBOX_ID' => $item['MAILBOX_ID'],
					),
					array(
						'IS_SEEN' => $data['seen'] ? 'S' : 'U',
					)
				);
			}
		}
	}

	private static function createClient($mailbox, $langCharset = null)
	{
		return new Imap(
			$mailbox['SERVER'], $mailbox['PORT'],
			$mailbox['USE_TLS'] == 'Y' || $mailbox['USE_TLS'] == 'S',
			$mailbox['USE_TLS'] == 'Y',
			$mailbox['LOGIN'], $mailbox['PASSWORD'],
			$langCharset ? $langCharset : LANG_CHARSET
		);
	}
}

class DummyMail extends Main\Mail\Mail
{

	public function initSettings()
	{
		parent::initSettings();

		$this->settingServerMsSmtp = false;
		$this->settingMailFillToEmail = false;
		$this->settingMailConvertMailHeader = true;
		$this->settingConvertNewLineUnixToWindows = true;
		$this->useBlacklist = false;
	}

	public static function getMailEol()
	{
		return "\r\n";
	}

	public function __toString()
	{
		return sprintf("%s\r\n\r\n%s", $this->getHeaders(), $this->getBody());
	}

	/**
	 * @deprecated
	 */
	public static function overwriteMessageHeaders(Main\Mail\Mail $message, array $headers)
	{
		foreach ($headers as $name => $value)
		{
			$message->headers[$name] = $value;
		}
	}

}
