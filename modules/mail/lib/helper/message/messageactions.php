<?php

namespace Bitrix\Mail\Helper\Message;

use Bitrix\Mail\Helper;
use Bitrix\Mail\Helper\Dto\MailboxMessageBatch;
use Bitrix\Mail\Helper\MessageFolder;
use Bitrix\Mail\Integration;
use Bitrix\Mail\Integration\Im\Chat;
use Bitrix\Mail\Integration\Intranet\Secretary;
use Bitrix\Mail\ImapCommands\MailsFoldersManager;
use Bitrix\Mail\Internal\Service\Label\LabelCountersService;
use Bitrix\Mail\Internal\Service\SourceGeneration\ActivePlacementResolver;
use Bitrix\Mail\Internal\Service\SourceGeneration\GenerationScope;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationActionGuard;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Mail\Internals\MessageAccessTable;
use Bitrix\Mail\ImapCommands\MailsFlagsManager;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;
use Bitrix\Main;

final class MessageActions
{
	public const DATE_FORMAT = 'Y-m-d H:i:s';
	public const MAX_BATCH_MESSAGE_IDS = 100;
	public const ERROR_CRM_ACCESS_DENIED = 'MAIL_MESSAGE_CRM_ACCESS_DENIED';

	/**
	 * @param string[] $ids Composite IDs in "{messageId}-{mailboxId}" format, e.g. ["123-1", "456-1", "789-2"]
	 * @return MailboxMessageBatch[]
	 */
	private static function groupIdsByMailbox(array $ids): array
	{
		if (empty($ids))
		{
			return [];
		}

		$grouped = [];
		foreach ($ids as $id)
		{
			$separatorPosition = strrpos($id, '-');
			if ($separatorPosition === false)
			{
				continue;
			}

			$messId = substr($id, 0, $separatorPosition);
			$mailboxId = substr($id, $separatorPosition + 1);
			$grouped[$mailboxId]['compositeIds'][] = $id;
			$grouped[$mailboxId]['messageIds'][] = $messId;
		}

		$result = [];
		foreach ($grouped as $mailboxId => $data)
		{
			$result[] = new MailboxMessageBatch(
				mailboxId: (int)$mailboxId,
				compositeIds: $data['compositeIds'],
				messageIds: $data['messageIds'],
			);
		}

		return $result;
	}

	/**
	 * The whole selection is admitted or rejected as a whole: a set mixing placements of the
	 * active physical source with placements left by a previous one runs nothing at all.
	 *
	 * @param MailboxMessageBatch[] $groups
	 */
	private static function rejectStaleGenerations(array $groups): ?\Bitrix\Main\Result
	{
		foreach ($groups as $group)
		{
			$check = (new ActivePlacementResolver($group->mailboxId))->checkPlacements($group->messageIds);

			if (!$check->isSuccess())
			{
				return (new \Bitrix\Main\Result())->addErrors($check->getErrors());
			}
		}

		return null;
	}

	/**
	 * @param MailboxMessageBatch[] $groups
	 * @return array<int, \Bitrix\Main\Result>
	 */
	private static function checkMigrationActions(array $groups): array
	{
		$mailboxIds = array_map(
			static fn (MailboxMessageBatch $group): int => $group->mailboxId,
			$groups,
		);

		return (new MigrationActionGuard())->checkMany($mailboxIds);
	}

	/**
	 * Adds the active generation of the mailbox that owns the message to a filter joining
	 * MESSAGE_UID. The owner is looked up only once the schema of the feature is there.
	 */
	private static function activePlacementFilter(int $messageId, array $filter): array
	{
		if (!GenerationScope::isSchemaInstalled())
		{
			return $filter;
		}

		return GenerationScope::forMailbox(Helper\Mailbox::getIdByMessageId($messageId))
			->apply($filter, 'MESSAGE_UID.')
		;
	}

	private static function filterOldMessages(array $messageIds, int $mailboxId): array
	{
		$oldIds = MailMessageUidTable::getList([
			'select' => ['ID'],
			'filter' => GenerationScope::forMailbox($mailboxId)->apply([
				'@ID' => $messageIds,
				'=MAILBOX_ID' => $mailboxId,
				'=IS_OLD' => 'Y',
			]),
		])->fetchAll();

		$oldMap = array_flip(array_column($oldIds, 'ID'));

		return array_values(array_filter($messageIds, static function ($id) use ($oldMap) {
			return !isset($oldMap[$id]);
		}));
	}

	/**
	 * A refusal that does not depend on the mailbox repeats in every group of the same
	 * operation, and the user has to read it once.
	 *
	 * @param \Bitrix\Main\Error[] $errors
	 */
	private static function addErrorsOncePerCode(\Bitrix\Main\Result $result, array $errors): void
	{
		$knownCodes = array_map(
			static fn (\Bitrix\Main\Error $error): string => (string)$error->getCode(),
			$result->getErrors(),
		);

		foreach ($errors as $error)
		{
			$code = (string)$error->getCode();
			if ($code !== '' && in_array($code, $knownCodes, true))
			{
				continue;
			}

			$result->addError($error);
			$knownCodes[] = $code;
		}
	}

	/**
	 * @param array $ids
	 * @param bool $deleteImmediately
	 * @param int|null $userId MCP: explicit user; when null — session user is used
	 * @return \Bitrix\Main\Result
	 * @throws \Exception
	 */
	public static function delete(array $ids, bool $deleteImmediately = false, ?int $userId = null): \Bitrix\Main\Result
	{
		$result = new \Bitrix\Main\Result();
		$groups = self::groupIdsByMailbox($ids);

		if (empty($groups))
		{
			return $result->addError(new \Bitrix\Main\Error('validation'));
		}

		$staleResult = self::rejectStaleGenerations($groups);
		if ($staleResult !== null)
		{
			return $staleResult;
		}

		$processedIds = [];
		$guardResults = self::checkMigrationActions($groups);

		foreach ($groups as $group)
		{
			$allowed = $guardResults[$group->mailboxId];
			if (!$allowed->isSuccess())
			{
				$result->addErrors($allowed->getErrors());

				continue;
			}

			$mailboxId = $group->mailboxId;
			$messagesIds = $group->messageIds;

			$mailMarkerManager = new MailsFoldersManager($mailboxId, $messagesIds);
			if ($userId !== null)
			{
				$mailMarkerManager->setMailboxUserId($userId);
			}

			$dirWithMessagesId = MessageFolder::getDirIdForMessages($mailboxId, $messagesIds);
			$idsUnseenCount = MailMessageUidTable::getCount(
				GenerationScope::forMailbox($mailboxId)->apply([
					'!@IS_SEEN' => ['Y', 'S'],
					'@ID' => $messagesIds,
					'=MAILBOX_ID' => $mailboxId,
				])
			);

			$groupResult = $mailMarkerManager->deleteMails($deleteImmediately);

			if ($groupResult->isSuccess())
			{
				$processedIds = array_merge($processedIds, $group->compositeIds);

				if ($idsUnseenCount)
				{
					MessageFolder::decreaseDirCounter($mailboxId, $dirWithMessagesId, $idsUnseenCount);

					$mailboxHelper = Helper\Mailbox::createInstance($mailboxId);
					Helper::updateMailboxUnseenCounter($mailboxId);
					(new LabelCountersService())->recalculateForUidRows($mailboxId, $messagesIds);
					$mailboxHelper->updateGlobalCounterForCurrentUser();
				}
			}

			self::addErrorsOncePerCode($result, $groupResult->getErrors());
		}

		$result->setData(array_merge($result->getData(), ['processedIds' => $processedIds]));

		return $result;
	}

	/**
	 * @param array $ids
	 * @return \Bitrix\Main\Result
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function markAsSpam(array $ids, int $userId): \Bitrix\Main\Result
	{
		$result = new \Bitrix\Main\Result();
		$groups = self::groupIdsByMailbox($ids);

		if (empty($groups))
		{
			return $result->addError(new \Bitrix\Main\Error('validation'));
		}

		$staleResult = self::rejectStaleGenerations($groups);
		if ($staleResult !== null)
		{
			return $staleResult;
		}

		$processedIds = [];
		$guardResults = self::checkMigrationActions($groups);

		foreach ($groups as $group)
		{
			$allowed = $guardResults[$group->mailboxId];
			if (!$allowed->isSuccess())
			{
				$result->addErrors($allowed->getErrors());

				continue;
			}

			$mailboxId = $group->mailboxId;
			$messagesIds = $group->messageIds;

			$dirWithMessagesId = MessageFolder::getDirIdForMessages($mailboxId, $messagesIds);
			$idsUnseenCount = MailMessageUidTable::getCount(
				GenerationScope::forMailbox($mailboxId)->apply([
					'!@IS_SEEN' => ['Y', 'S'],
					'@ID' => $messagesIds,
					'=MAILBOX_ID' => $mailboxId,
				])
			);

			$mailMarkerManager = new MailsFoldersManager($mailboxId, $messagesIds, $userId);
			$groupResult = $mailMarkerManager->sendMailsToSpam();

			if ($groupResult->isSuccess())
			{
				$processedIds = array_merge($processedIds, $group->compositeIds);

				MessageFolder::decreaseDirCounter($mailboxId, $dirWithMessagesId, $idsUnseenCount);

				$mailboxHelper = Helper\Mailbox::createInstance($mailboxId);
				Helper::updateMailboxUnseenCounter($mailboxId);
				(new LabelCountersService())->recalculateForUidRows($mailboxId, $messagesIds);
				$mailboxHelper->updateGlobalCounterForCurrentUser();
			}

			$result->addErrors($groupResult->getErrors());
		}

		$result->setData(array_merge($result->getData(), ['processedIds' => $processedIds]));

		return $result;
	}

	/**
	 * @param array $ids
	 * @param int $userId
	 * @return \Bitrix\Main\Result
	 * @throws \Exception
	 */
	public static function restoreFromSpam(array $ids, int $userId): \Bitrix\Main\Result
	{
		$result = new \Bitrix\Main\Result();
		$groups = self::groupIdsByMailbox($ids);

		if (empty($groups))
		{
			return $result->addError(new \Bitrix\Main\Error('validation'));
		}

		$staleResult = self::rejectStaleGenerations($groups);
		if ($staleResult !== null)
		{
			return $staleResult;
		}

		$processedIds = [];
		$guardResults = self::checkMigrationActions($groups);

		foreach ($groups as $group)
		{
			$allowed = $guardResults[$group->mailboxId];
			if (!$allowed->isSuccess())
			{
				$result->addErrors($allowed->getErrors());

				continue;
			}

			$mailboxId = $group->mailboxId;
			$messagesIds = $group->messageIds;

			$idsUnseenCount = MailMessageUidTable::getCount(
				GenerationScope::forMailbox($mailboxId)->apply([
					'!@IS_SEEN' => ['Y', 'S'],
					'@ID' => $messagesIds,
					'=MAILBOX_ID' => $mailboxId,
				])
			);

			$mailMarkerManager = new MailsFoldersManager($mailboxId, $messagesIds, $userId);
			$groupResult = $mailMarkerManager->restoreMailsFromSpam();

			if ($groupResult->isSuccess())
			{
				$processedIds = array_merge($processedIds, $group->compositeIds);

				if ($idsUnseenCount)
				{
					$dirForMoveMessagesId = MailboxDirectoryTable::getList([
						'select' => [
							'ID',
						],
						'filter' => GenerationScope::forMailbox($mailboxId)->apply([
							'=PATH' => 'INBOX',
							'=MAILBOX_ID' => $mailboxId,
						]),
						'limit' => 1,
					])->fetchAll();

					if (isset($dirForMoveMessagesId[0]['ID']))
					{
						$dirForMoveMessagesId = $dirForMoveMessagesId[0]['ID'];
						$mailboxHelper = Helper\Mailbox::createInstance($mailboxId);
						$dirForMoveMessages = $mailboxHelper->getDirsHelper()->getDirByPath('INBOX');
						MessageFolder::increaseDirCounter($mailboxId, $dirForMoveMessages, $dirForMoveMessagesId, $idsUnseenCount);

						Helper::updateMailboxUnseenCounter($mailboxId);
						(new LabelCountersService())->recalculateForUidRows($mailboxId, $messagesIds);
						$mailboxHelper->updateGlobalCounterForCurrentUser();
					}
				}
			}

			$result->addErrors($groupResult->getErrors());
		}

		$result->setData(array_merge($result->getData(), ['processedIds' => $processedIds]));

		return $result;
	}

	/**
	 * @param array $ids
	 * @param string $folderPath
	 * @return \Bitrix\Main\Result
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function moveToFolder(array $ids, string $folderPath, int $userId): \Bitrix\Main\Result
	{
		$result = new \Bitrix\Main\Result();
		$groups = self::groupIdsByMailbox($ids);

		if (empty($groups))
		{
			return $result->addError(new \Bitrix\Main\Error('validation'));
		}

		$staleResult = self::rejectStaleGenerations($groups);
		if ($staleResult !== null)
		{
			return $staleResult;
		}

		$processedIds = [];
		$guardResults = self::checkMigrationActions($groups);

		foreach ($groups as $group)
		{
			$allowed = $guardResults[$group->mailboxId];
			if (!$allowed->isSuccess())
			{
				$result->addErrors($allowed->getErrors());

				continue;
			}

			$mailboxId = $group->mailboxId;
			$messagesIds = $group->messageIds;

			$mailMarkerManager = new MailsFoldersManager($mailboxId, $messagesIds, $userId);

			$idsUnseenCount = MailMessageUidTable::getCount(
				GenerationScope::forMailbox($mailboxId)->apply([
					'!@IS_SEEN' => ['Y', 'S'],
					'@ID' => $messagesIds,
					'=MAILBOX_ID' => $mailboxId,
				])
			);

			$dirWithMessagesId = false;
			$dirForMoveMessagesId = [];

			if ($idsUnseenCount)
			{
				$dirWithMessagesId = MessageFolder::getDirIdForMessages($mailboxId, $messagesIds);

				$dirForMoveMessagesId = MailboxDirectoryTable::getList([
					'select' => [
						'ID',
					],
					'filter' => GenerationScope::forMailbox($mailboxId)->apply([
						'=PATH' => $folderPath,
						'=MAILBOX_ID' => $mailboxId,
					]),
					'limit' => 1,
				])->fetchAll();
			}

			$groupResult = $mailMarkerManager->moveMails($folderPath);

			if ($groupResult->isSuccess())
			{
				$processedIds = array_merge($processedIds, $group->compositeIds);

				if ($dirWithMessagesId && isset($dirForMoveMessagesId[0]['ID']))
				{
					$dirForMoveMessagesId = $dirForMoveMessagesId[0]['ID'];

					MessageFolder::decreaseDirCounter($mailboxId, $dirWithMessagesId, $idsUnseenCount);

					$mailboxHelper = Helper\Mailbox::createInstance($mailboxId);
					$dirForMoveMessages = $mailboxHelper->getDirsHelper()->getDirByPath($folderPath);

					MessageFolder::increaseDirCounter($mailboxId, $dirForMoveMessages, $dirForMoveMessagesId, $idsUnseenCount);

					Helper::updateMailboxUnseenCounter($mailboxId);
					(new LabelCountersService())->recalculateForUidRows($mailboxId, $messagesIds);
					$mailboxHelper->updateGlobalCounterForCurrentUser();
				}
			}

			$result->addErrors($groupResult->getErrors());
		}

		$result->setData(array_merge($result->getData(), ['processedIds' => $processedIds]));

		return $result;
	}

	/**
	 * @param array $ids
	 * @param bool $seen
	 * @return \Bitrix\Main\Result
	 */
	public static function markMessages(array $ids, bool $seen = true): \Bitrix\Main\Result
	{
		$result = new \Bitrix\Main\Result();

		$method = ($seen ? 'markMailsSeen' : 'markMailsUnseen');

		$mailboxIds = null;
		$md5Dirs = null;
		if (!empty($ids['for_all_user_mailboxes']))
		{
			$mailboxIds = array_map('intval', array_keys(\Bitrix\Mail\MailboxTable::getUserMailboxes()));
			$md5Dirs = Helper\MailboxDirectoryHelper::getSyncDirsMd5ForMailboxes($mailboxIds);
		}
		elseif (!empty($ids['for_all']))
		{
			[$mailboxId, $dir] = explode('-', $ids['for_all']);
			$mailboxIds = [(int)$mailboxId];
			$md5Dirs = [md5($dir)];
		}

		if ($mailboxIds !== null)
		{
			$ids = [];
			if (!empty($mailboxIds) && !empty($md5Dirs))
			{
				$res = MailMessageUidTable::getList([
					'select' => ['ID', 'MAILBOX_ID', 'GENERATION_ID'],
					'filter' => [
						'@MAILBOX_ID' => $mailboxIds,
						'@DIR_MD5' => $md5Dirs,
						'>MESSAGE_ID' => 0,
						'@IS_SEEN' => $seen ? ['N', 'U'] : ['Y', 'S'],
						'==DELETE_TIME' => 0,
					],
				]);

				// The scope is per mailbox, so the generation is checked row by row here
				while ($item = $res->fetch())
				{
					$mailboxId = (int)$item['MAILBOX_ID'];

					if (GenerationScope::forMailbox($mailboxId)->includes((int)$item['GENERATION_ID']))
					{
						$ids[] = "{$item['ID']}-{$mailboxId}";
					}
				}
			}
		}

		$groups = self::groupIdsByMailbox($ids);

		if (empty($groups))
		{
			return $result->addError(new \Bitrix\Main\Error('validation'));
		}

		$staleResult = self::rejectStaleGenerations($groups);
		if ($staleResult !== null)
		{
			return $staleResult;
		}

		$processedIds = [];
		$guardResults = self::checkMigrationActions($groups);

		foreach ($groups as $group)
		{
			$allowed = $guardResults[$group->mailboxId];
			if (!$allowed->isSuccess())
			{
				$result->addErrors($allowed->getErrors());

				continue;
			}

			$filteredMessageIds = self::filterOldMessages($group->messageIds, $group->mailboxId);

			if (empty($filteredMessageIds))
			{
				continue;
			}

			$mailMarkerManager = new MailsFlagsManager($group->mailboxId, $filteredMessageIds);
			$groupResult = $mailMarkerManager->$method();

			if ($groupResult->isSuccess())
			{
				$keepIds = array_flip($filteredMessageIds);
				foreach ($group->messageIds as $index => $messageId)
				{
					if (isset($keepIds[$messageId]))
					{
						$processedIds[] = $group->compositeIds[$index];
					}
				}

			}

			$result->addErrors($groupResult->getErrors());
		}

		$result->setData(array_merge($result->getData(), ['processedIds' => $processedIds]));

		return $result;
	}


	/**
	 * @param array $ids
	 * @return \Bitrix\Main\Result
	 */
	public static function markAsSeen(array $ids): \Bitrix\Main\Result
	{
		return self::markMessages($ids, true);
	}

	/**
	 * @param $ids
	 * @return \Bitrix\Main\Result
	 */
	public static function markAsUnseen($ids): \Bitrix\Main\Result
	{
		return self::markMessages($ids, false);
	}

	private static function sanitizeHtmlForOldCrmModule(array &$message): void
	{
		$crmFilterSettings = \CCrmEMail::onGetFilterListImap();
		if (empty($crmFilterSettings['SANITIZE_ON_VIEW'])
			&& !empty($message[MailMessageTable::FIELD_SANITIZE_ON_VIEW])
			&& !empty($message['BODY_HTML']))
		{
			$message['BODY_HTML'] = \Bitrix\Mail\Helper\Message::sanitizeHtmlForMessageView($message['BODY_HTML']);
		}
	}

	public static function createCrmActivity(int $messageId, int $iteration = 1, ?int $userId = null): \Bitrix\Main\Result
	{
		$result = new \Bitrix\Main\Result();

		if (!Loader::includeModule('crm'))
		{
			$result->addError(new \Bitrix\Main\Error(Loc::getMessage('MAIL_MESSAGE_ACTIONS_NO_CRM')));
			return $result;
		}

		$hasAccessToCrm = $userId === null
			? Helper\MailboxAccess::hasCurrentUserAccessToEditMailboxIntegrationCrm()
			: Helper\MailboxAccess::hasUserAccessToEditMailboxIntegrationCrm($userId);
		if (!$hasAccessToCrm)
		{
			$result->addError(new \Bitrix\Main\Error(
				Loc::getMessage('MAIL_MESSAGE_ACTIONS_NO_CRM_ACCESS'),
				self::ERROR_CRM_ACCESS_DENIED,
			));

			return $result;
		}

		$message = MailMessageTable::getList([
			'runtime' => [
				new Main\Entity\ReferenceField(
					'MESSAGE_UID',
					'Bitrix\Mail\MailMessageUidTable',
					[
						'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
						'=this.ID' => 'ref.MESSAGE_ID',
					],
					[
						'join_type' => 'INNER',
					]
				),
			],
			// The message is read through its active placement: a retained one carries
			// the coordinates of a physical source the mailbox no longer talks to
			'filter' => self::activePlacementFilter($messageId, ['=ID' => $messageId]),
			'select' => [
				'*',
				'MAILBOX_EMAIL' => 'MAILBOX.EMAIL',
				'MAILBOX_NAME' => 'MAILBOX.NAME',
				'MAILBOX_LOGIN' => 'MAILBOX.LOGIN',
				'IS_SEEN' => 'MESSAGE_UID.IS_SEEN',
				'MSG_HASH' => 'MESSAGE_UID.HEADER_MD5',
				'DIR_MD5' => 'MESSAGE_UID.DIR_MD5',
				'MSG_UID' => 'MESSAGE_UID.MSG_UID',
				// The version of the folder the uid was written under: the lazy download reads it too
				'DIR_UIDV' => 'MESSAGE_UID.DIR_UIDV',
			],
			'order' => [
				'MESSAGE_UID.INTERNALDATE' => 'DESC',
				'MESSAGE_UID.ID' => 'DESC',
				'MESSAGE_UID.MSG_UID' => 'ASC',
			],
			'limit' => 1,
		])->fetch();

		if (empty($message) || !Helper\Message::hasAccess($message, $userId))
		{
			$result->addError(new \Bitrix\Main\Error(Loc::getMessage('MAIL_MESSAGE_ACTIONS_NO_MESSAGE')));
			return $result;
		}

		if ($iteration <= 1 && Helper\Message::ensureAttachments($message) > 0)
		{
			return self::createCrmActivity($messageId, $iteration + 1, $userId);
		}

		$message = self::prepareMessageForCrm($message);

		$message['IS_OUTCOME'] = $message['__is_outcome'];
		$message['IS_SEEN'] = in_array($message['IS_SEEN'], ['Y', 'S']);

		$message['__forced'] = true;

		self::sanitizeHtmlForOldCrmModule($message);
		$matchedActivityId = self::findMatchedOutgoingCrmActivityId($message);

		if (!\CCrmEMail::imapEmailMessageAdd($message, null, $error))
		{
			if ($error instanceof \Bitrix\Main\Error)
			{
				$result->addError($error);
			}
			else
			{
				$result->addError(new \Bitrix\Main\Error(Loc::getMessage('MAIL_MESSAGE_ACTIONS_UNKNOWN_ERROR')));
			}
		}
		elseif (!self::hasCrmActivityBinding($messageId, (int)$message['MAILBOX_ID'], $matchedActivityId))
		{
			$result->addError(new \Bitrix\Main\Error(Loc::getMessage('MAIL_MESSAGE_ACTIONS_UNKNOWN_ERROR')));
		}

		return $result;
	}

	private static function findMatchedOutgoingCrmActivityId(array $message): ?int
	{
		if (empty($message['IS_OUTCOME']) || empty($message['MSG_ID']))
		{
			return null;
		}

		if (!preg_match(
			'/<crm\.activity\.((\d+)-[0-9a-z]+)@[^>]+>/i',
			sprintf('<%s>', $message['MSG_ID']),
			$matches,
		))
		{
			return null;
		}

		$activity = \Bitrix\Crm\ActivityTable::getByPrimary((int)$matches[2], [
			'select' => ['ID', 'URN'],
		])->fetch();

		if (!$activity || mb_strtolower((string)$activity['URN']) !== mb_strtolower($matches[1]))
		{
			return null;
		}

		return (int)$activity['ID'];
	}

	private static function hasCrmActivityBinding(int $messageId, int $mailboxId, ?int $activityId = null): bool
	{
		$query = MessageAccessTable::query()
			->setSelect(['TOKEN'])
			->where('MESSAGE_ID', $messageId)
			->where('MAILBOX_ID', $mailboxId)
			->where('ENTITY_TYPE', MessageAccessTable::ENTITY_TYPE_CRM_ACTIVITY)
			->setLimit(1)
		;
		if ($activityId !== null)
		{
			$query->where('ENTITY_ID', $activityId);
		}

		return (bool)$query->fetch();
	}

	private static function prepareMessageForCrm(array $message): array
	{
		if (empty($message['FIELD_RCPT']))
		{
			$message['FIELD_RCPT'] = Helper\Message::getOriginalRecipientsFromHeader(
				(string)($message['HEADER'] ?? '')
			);
		}

		Helper\Message::prepare($message);

		return $message;
	}

	public static function deleteByMessageIds(array $messageIds, int $userId, bool $deleteImmediately = false): \Bitrix\Main\Result
	{
		$ids = self::buildUidIds($messageIds, $userId);
		if (empty($ids))
		{
			return (new \Bitrix\Main\Result())->addError(new \Bitrix\Main\Error('No accessible messages found.'));
		}

		$result = self::delete($ids, $deleteImmediately, $userId);
		if ($result->isSuccess())
		{
			$result->setData(['affectedCount' => count($ids)]);
		}

		return $result;
	}

	/**
	 * @param array $ids
	 * @return \Bitrix\Main\Result
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */

	public static function markAsSpamByMessageIds(array $messageIds, int $userId): \Bitrix\Main\Result
	{
		$ids = self::buildUidIds($messageIds, $userId);
		if (empty($ids))
		{
			return (new \Bitrix\Main\Result())->addError(new \Bitrix\Main\Error('No accessible messages found.'));
		}

		$result = self::markAsSpam($ids, $userId);
		if ($result->isSuccess())
		{
			$result->setData(['affectedCount' => count($ids)]);
		}

		return $result;
	}

	/**
	 * @param array $ids
	 * @param int $userId
	 * @return \Bitrix\Main\Result
	 * @throws \Exception
	 */

	public static function moveToFolderByMessageIds(array $messageIds, string $folder, int $userId): \Bitrix\Main\Result
	{
		$ids = self::buildUidIds($messageIds, $userId);
		if (empty($ids))
		{
			return (new \Bitrix\Main\Result())->addError(new \Bitrix\Main\Error('No accessible messages found.'));
		}

		$mailboxIds = array_unique(array_map(
			static fn(string $id) => (int)(explode('-', $id, 2)[1] ?? 0),
			$ids,
		));

		if (count($mailboxIds) > 1)
		{
			return (new \Bitrix\Main\Result())->addError(new \Bitrix\Main\Error('All messages must belong to the same mailbox.'));
		}

		$resolvedFolder = MessageFolder::resolveFolderPath($folder, $mailboxIds[0]);
		$folderPath = $resolvedFolder ?? $folder;

		$result = self::moveToFolder($ids, $folderPath, $userId);
		if ($result->isSuccess())
		{
			$result->setData(['affectedCount' => count($ids)]);
		}

		return $result;
	}

	/**
	 * @param array $ids
	 * @param bool $seen
	 * @return \Bitrix\Main\Result
	 */

	public static function removeCrmActivity(int $messageId, ?int $userId = null): \Bitrix\Main\Result
	{
		$result = new \Bitrix\Main\Result();

		if (!Loader::includeModule('crm'))
		{
			$result->addError(new \Bitrix\Main\Error(Loc::getMessage('MAIL_MESSAGE_ACTIONS_NO_CRM')));

			return $result;
		}

		$hasAccessToCrm = $userId === null
			? Helper\MailboxAccess::hasCurrentUserAccessToEditMailboxIntegrationCrm()
			: Helper\MailboxAccess::hasUserAccessToEditMailboxIntegrationCrm($userId);
		if (!$hasAccessToCrm)
		{
			$result->addError(new \Bitrix\Main\Error(
				Loc::getMessage('MAIL_MESSAGE_ACTIONS_NO_CRM_ACCESS'),
				self::ERROR_CRM_ACCESS_DENIED,
			));

			return $result;
		}

		$message = MailMessageTable::getRow([
			'select' => [
				'*',
				'MAILBOX_EMAIL' => 'MAILBOX.EMAIL',
				'MAILBOX_NAME' => 'MAILBOX.NAME',
				'MAILBOX_LOGIN' => 'MAILBOX.LOGIN',
			],
			'filter' => ['=ID' => $messageId],
		]);

		if (empty($message) || !Helper\Message::hasAccess($message, $userId))
		{
			$result->addError(new \Bitrix\Main\Error(Loc::getMessage('MAIL_MESSAGE_ACTIONS_NO_MESSAGE')));

			return $result;
		}

		$activityIds = MessageAccessTable::query()
			->where('MAILBOX_ID', $message['MAILBOX_ID'])
			->where('MESSAGE_ID', $messageId)
			->where('ENTITY_TYPE', 'CRM_ACTIVITY')
			->setDistinct()
			->addSelect('ENTITY_ID')
			->fetchAll()
		;

		$activityIds = array_column($activityIds, 'ENTITY_ID');

		if (empty($activityIds))
		{
			return $result;
		}

		if (!\Bitrix\Mail\Integration\Crm\Permissions::getInstance()->canDeleteActivity($userId))
		{
			$result->addError(new \Bitrix\Main\Error('Access denied: cannot delete CRM activity'));

			return $result;
		}

		self::addSenderToExclusionList($message, $userId);

		foreach ($activityIds as $activityId)
		{
			\CCrmActivity::delete($activityId, checkPerms: false); // permissions already verified above
		}

		return $result;
	}


	private static function addSenderToExclusionList(array $message, ?int $userId): void
	{
		Helper\Message::prepare($message);

		if (!empty($message['__is_outcome']))
		{
			return;
		}

		if (!Integration\Crm\Permissions::getInstance()->canEditExclusionItems($userId))
		{
			return;
		}

		foreach (array_merge($message['__from'], $message['__reply_to']) as $item)
		{
			if (!empty($item['email']))
			{
				Integration\Crm\Exclusion::addEmail($item['email']);
			}
		}
	}


	public static function createTask(
		int $messageId,
		int $userId,
		?string $title = null,
		?int $responsibleId = null,
		?string $description = null,
	): Main\Result
	{
		$result = new Main\Result();

		if (!Loader::includeModule('tasks'))
		{
			$result->addError(new Main\Error('Module tasks is not installed.'));

			return $result;
		}

		$message = Helper\Message::getWithAccessCheck($messageId, $userId);
		if ($message === null)
		{
			$result->addError(new Main\Error(Loc::getMessage('MAIL_MESSAGE_ACTIONS_NO_MESSAGE')));

			return $result;
		}

		$taskTitle = $title ?? $message['SUBJECT'] ?? '';
		if ($taskTitle === '')
		{
			$taskTitle = 'Task from email #' . $messageId;
		}

		$fields = [
			'TITLE' => $taskTitle,
			'CREATED_BY' => $userId,
			'RESPONSIBLE_ID' => $responsibleId ?? $userId,
			'UF_MAIL_MESSAGE' => $messageId,
		];

		if ($description !== null)
		{
			$fields['DESCRIPTION'] = $description;
		}

		try
		{
			$task = \CTaskItem::add($fields, $userId);
			$taskId = $task->getId();
		}
		catch (\Exception $e)
		{
			$result->addError(new Main\Error($e->getMessage()));

			return $result;
		}

		Secretary::provideAccessToMessage(
			$messageId,
			MessageAccessTable::ENTITY_TYPE_TASKS_TASK,
			$taskId,
			$userId,
		);

		$result->setData(['taskId' => $taskId]);

		return $result;
	}


	public static function createCalendarEvent(
		int $messageId,
		int $userId,
		string $dateFrom,
		string $dateTo,
		?string $name = null,
		?string $description = null,
	): Main\Result
	{
		$result = new Main\Result();

		if (!Loader::includeModule('calendar'))
		{
			$result->addError(new Main\Error('Module calendar is not installed.'));

			return $result;
		}

		if ($dateFrom === '' || $dateTo === '')
		{
			$result->addError(new Main\Error('dateFrom and dateTo are required.'));

			return $result;
		}

		try
		{
			$eventDateFrom = self::parseDateTime($dateFrom);
			$eventDateTo = self::parseDateTime($dateTo);
		}
		catch (Main\ArgumentException $e)
		{
			$result->addError(new Main\Error($e->getMessage()));

			return $result;
		}

		$message = Helper\Message::getWithAccessCheck($messageId, $userId);
		if ($message === null)
		{
			$result->addError(new Main\Error(Loc::getMessage('MAIL_MESSAGE_ACTIONS_NO_MESSAGE')));

			return $result;
		}

		$eventName = $name ?? $message['SUBJECT'] ?? '';
		if ($eventName === '')
		{
			$eventName = 'Event from email #' . $messageId;
		}

		$siteFormat = Main\Type\DateTime::getFormat();

		$link = Helper\Message::getMessageUrl($messageId);
		$eventDescription = $description ?? '';
		if ($link !== '')
		{
			$eventDescription .= ($eventDescription !== '' ? "\n\n" : '') . 'Email: ' . $link;
		}

		$eventId = \CCalendar::SaveEvent([
			'arFields' => [
				'NAME' => $eventName,
				'DATE_FROM' => $eventDateFrom->format($siteFormat),
				'DATE_TO' => $eventDateTo->format($siteFormat),
				'CAL_TYPE' => 'user',
				'OWNER_ID' => $userId,
				'CREATED_BY' => $userId,
				'DESCRIPTION' => $eventDescription,
			],
			'userId' => $userId,
		]);

		if (!$eventId)
		{
			$result->addError(new Main\Error('Failed to create calendar event.'));

			return $result;
		}

		Secretary::provideAccessToMessage(
			$messageId,
			MessageAccessTable::ENTITY_TYPE_CALENDAR_EVENT,
			(int)$eventId,
			$userId,
		);

		$result->setData(['eventId' => (int)$eventId]);

		return $result;
	}


	public static function createChat(int $messageId, int $userId): Main\Result
	{
		$result = new Main\Result();

		if (!Loader::includeModule('im') || !Loader::includeModule('intranet'))
		{
			$result->addError(new Main\Error('Modules im/intranet are not installed.'));

			return $result;
		}

		$message = Helper\Message::getWithAccessCheck($messageId, $userId);
		if ($message === null)
		{
			$result->addError(new Main\Error(Loc::getMessage('MAIL_MESSAGE_ACTIONS_NO_MESSAGE')));

			return $result;
		}

		if ($chatId = \Bitrix\Intranet\Secretary::getChatIdIfExists($messageId, 'MAIL'))
		{
			if (!\Bitrix\Intranet\Secretary::isUserInChat($chatId, $userId))
			{
				\Bitrix\Intranet\Secretary::addUserToChat($chatId, $userId, false);
			}

			$result->setData(['chatId' => $chatId, 'existing' => true]);

			return $result;
		}

		$lockName = "chat_create_mail_{$messageId}";
		if (!Application::getConnection()->lock($lockName))
		{
			$result->addError(new Main\Error('Failed to acquire lock for chat creation.'));

			return $result;
		}

		try
		{
			$messageData = Secretary::getMessage($messageId)->toArray();
			$messageData['USER_IDS'] = [$userId];

			$createResult = Chat::createMailChat($messageData, $userId);

			if (!$createResult->isSuccess())
			{
				$result->addErrors($createResult->getErrors());

				return $result;
			}

			$chatId = $createResult->getData()['chatId'];
			$result->setData(['chatId' => $chatId, 'existing' => false]);
		}
		finally
		{
			Application::getConnection()->unlock($lockName);
		}

		return $result;
	}


	public static function createFeedPost(
		int $messageId,
		int $userId,
		?string $title = null,
	): Main\Result
	{
		$result = new Main\Result();

		if (!Loader::includeModule('blog') || !Loader::includeModule('socialnetwork'))
		{
			$result->addError(new Main\Error('Modules blog/socialnetwork are not installed.'));

			return $result;
		}

		$message = Helper\Message::getWithAccessCheck($messageId, $userId);
		if ($message === null)
		{
			$result->addError(new Main\Error(Loc::getMessage('MAIL_MESSAGE_ACTIONS_NO_MESSAGE')));

			return $result;
		}

		$postTitle = $title ?? $message['SUBJECT'] ?? '';
		if ($postTitle === '')
		{
			$postTitle = 'Post from email #' . $messageId;
		}

		$link = Helper\Message::getMessageUrl($messageId);
		$postBody = $link !== '' ? 'Email: ' . $link : $postTitle;

		$blog = \CBlog::GetByOwnerID($userId);
		if (!$blog)
		{
			$result->addError(new Main\Error('User blog not found.'));

			return $result;
		}
		$blogId = (int)$blog['ID'];

		$postId = \CBlogPost::Add([
			'BLOG_ID' => $blogId,
			'TITLE' => $postTitle,
			'DETAIL_TEXT' => $postBody,
			'DETAIL_TEXT_TYPE' => 'text',
			'AUTHOR_ID' => $userId,
			'DATE_CREATE' => new Main\Type\DateTime(),
			'DATE_PUBLISH' => new Main\Type\DateTime(),
			'PUBLISH_STATUS' => BLOG_PUBLISH_STATUS_PUBLISH,
			'SOCNET_RIGHTS' => ['UA'],
			'UF_MAIL_MESSAGE' => $messageId,
		]);

		if (!$postId)
		{
			$result->addError(new Main\Error('Failed to create blog post.'));

			return $result;
		}

		Secretary::provideAccessToMessage(
			$messageId,
			MessageAccessTable::ENTITY_TYPE_BLOG_POST,
			(int)$postId,
			$userId,
		);

		$result->setData(['postId' => (int)$postId]);

		return $result;
	}

	/**
	 * Converts MailMessageTable IDs to uid format "{uidId}-{mailboxId}" with access check.
	 *
	 * @param int[] $messageIds
	 * @return string[]
	 */

	private static function buildUidIds(array $messageIds, int $userId): array
	{
		if (count($messageIds) > self::MAX_BATCH_MESSAGE_IDS)
		{
			throw new Main\SystemException(sprintf(
				'Too many message IDs in a single batch: %d, must not exceed %d.',
				count($messageIds),
				self::MAX_BATCH_MESSAGE_IDS,
			));
		}

		$ids = [];

		$rows = MailMessageUidTable::getList([
			'select' => ['ID', 'MAILBOX_ID', 'MESSAGE_ID', 'GENERATION_ID'],
			'filter' => [
				'@MESSAGE_ID' => $messageIds,
				'!@IS_OLD' => MailMessageUidTable::HIDDEN_STATUSES,
			],
		])->fetchAll();

		// A logical message is addressed through its active placement only
		$rows = array_values(array_filter(
			$rows,
			static fn(array $row): bool => GenerationScope::forMailbox((int)$row['MAILBOX_ID'])
				->includes((int)$row['GENERATION_ID']),
		));

		if (empty($rows))
		{
			return $ids;
		}

		$uniqueMailboxIds = array_unique(array_map(
			static fn(array $row): int => (int)$row['MAILBOX_ID'],
			$rows,
		));

		$accessByMailboxId = [];
		foreach ($uniqueMailboxIds as $mailboxId)
		{
			$accessByMailboxId[$mailboxId] = Helper\MailboxAccess::hasUserAccessToMailbox(
				$mailboxId,
				$userId,
				true,
			);
		}

		foreach ($rows as $row)
		{
			if ($accessByMailboxId[(int)$row['MAILBOX_ID']])
			{
				$ids[] = $row['ID'] . '-' . $row['MAILBOX_ID'];
			}
		}

		return $ids;
	}


	private static function parseDateTime(?string $value): ?Main\Type\DateTime
	{
		if ($value === null || $value === '')
		{
			return null;
		}

		try
		{
			return new Main\Type\DateTime($value, self::DATE_FORMAT);
		}
		catch (Main\ObjectException $e)
		{
			throw new Main\ArgumentException(
				"Invalid date format for value '{$value}'. Expected '" . self::DATE_FORMAT . "'.",
				previous: $e,
			);
		}
	}


}
