<?php

namespace Bitrix\Mail\Helper\Message;

use Bitrix\Mail\Helper;
use Bitrix\Mail\Helper\MessageFolder;
use Bitrix\Mail\ImapCommands\MailsFoldersManager;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Mail\ImapCommands\MailsFlagsManager;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;
use Bitrix\Main;

final class MessageActions
{
	/**
	 * @param $ids
	 * @param $ignoreOld
	 * @return \Bitrix\Main\Result
	 */
	private static function extractIds($ids, $ignoreOld = false): \Bitrix\Main\Result
	{
		$result = new \Bitrix\Main\Result();

		if (empty($ids))
		{
			return $result->addError(new \Bitrix\Main\Error('validation'));
		}
		$mailboxIds = $messIds = [];
		foreach ($ids as $id)
		{
			[$messId, $mailboxId] = explode('-', $id, 2);

			$mailboxIds[$mailboxId] = $mailboxId;
			$messIds[$messId] = $messId;
		}
		if (count($mailboxIds) > 1)
		{
			return $result->addError(new \Bitrix\Main\Error('validation'));
		}

		if($ignoreOld)
		{
			$oldIds = MailMessageUidTable::getList([
				'select' => ['ID'],
				'filter' => [
					'@ID' => $messIds,
					'=MAILBOX_ID' => current($mailboxIds),
					'=IS_OLD' => 'Y',
				],
			])->fetchAll();

			foreach ($oldIds as $item)
			{
				if(is_set($messIds[$item['ID']]))
				{
					unset($messIds[$item['ID']]);
				}
			}
		}

		if (!count($mailboxIds))
		{
			return $result->addError(new \Bitrix\Main\Error('validation'));
		}
		if (!count($messIds))
		{
			return $result->addError(new \Bitrix\Main\Error('validation'));
		}
		$result->setData([
			'mailboxId' => array_pop($mailboxIds),
			'messagesIds' => array_keys($messIds),
		]);

		return $result;
	}

	/**
	 * @param array $ids
	 * @param bool $deleteImmediately
	 * @return \Bitrix\Main\Result
	 * @throws \Exception
	 */
	public static function delete(array $ids, bool $deleteImmediately = false): \Bitrix\Main\Result
	{
		$extractionResult = self::extractIds($ids);
		$result = new \Bitrix\Main\Result();

		if ($extractionResult->isSuccess())
		{
			$data = $extractionResult->getData();
			$mailboxId = $data['mailboxId'];
			$messagesIds = $data['messagesIds'];
			$mailMarkerManager = new MailsFoldersManager($mailboxId, $messagesIds);

			$dirWithMessagesId = MessageFolder::getDirIdForMessages($mailboxId,$messagesIds);
			$idsUnseenCount = MailMessageUidTable::getCount([
				'!@IS_SEEN' => ['Y', 'S'],
				'@ID' => $messagesIds,
				'=MAILBOX_ID' => $mailboxId,
			]);

			$result = $mailMarkerManager->deleteMails($deleteImmediately);

			if ($result->isSuccess() && $idsUnseenCount)
			{
				MessageFolder::decreaseDirCounter($mailboxId, $dirWithMessagesId, $idsUnseenCount);

				$mailboxHelper = Helper\Mailbox::createInstance($mailboxId);
				Helper::updateMailboxUnseenCounter($mailboxId);
				$mailboxHelper->updateGlobalCounterForCurrentUser();
			}
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
	public static function markAsSpam(array $ids, int $userId): \Bitrix\Main\Result
	{
		$extractionResult = self::extractIds($ids);
		$result = new \Bitrix\Main\Result();

		if ($extractionResult->isSuccess())
		{
			$data = $extractionResult->getData();
			$mailboxId = $data['mailboxId'];
			$messagesIds = $data['messagesIds'];

			$dirWithMessagesId = MessageFolder::getDirIdForMessages($mailboxId,$messagesIds);
			$idsUnseenCount = MailMessageUidTable::getCount([
				'!@IS_SEEN' => ['Y', 'S'],
				'@ID' => $messagesIds,
				'=MAILBOX_ID' => $mailboxId,
			]);

			$mailMarkerManager = new MailsFoldersManager($mailboxId, $messagesIds, $userId);
			$result = $mailMarkerManager->sendMailsToSpam();

			if ($result->isSuccess())
			{
				MessageFolder::decreaseDirCounter($mailboxId, $dirWithMessagesId, $idsUnseenCount);

				$mailboxHelper = Helper\Mailbox::createInstance($mailboxId);
				Helper::updateMailboxUnseenCounter($mailboxId);
				$mailboxHelper->updateGlobalCounterForCurrentUser();
			}
		}

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
		$extractionResult = self::extractIds($ids);
		$result = new \Bitrix\Main\Result();

		if ($extractionResult->isSuccess())
		{
			$data = $extractionResult->getData();
			$mailboxId = $data['mailboxId'];
			$messagesIds = $data['messagesIds'];

			$idsUnseenCount = MailMessageUidTable::getCount([
				'!@IS_SEEN' => ['Y', 'S'],
				'@ID' => $messagesIds,
				'=MAILBOX_ID' => $mailboxId,
			]);

			$mailMarkerManager = new MailsFoldersManager($data['mailboxId'], $data['messagesIds'], $userId);
			$result = $mailMarkerManager->restoreMailsFromSpam();

			if ($result->isSuccess())
			{
				if ($idsUnseenCount)
				{
					$dirForMoveMessagesId = MailboxDirectoryTable::getList([
						'select' => [
							'ID',
						],
						'filter' => [
							'=PATH' => 'INBOX',
							'=MAILBOX_ID' => $mailboxId,
						],
						'limit' => 1,
					])->fetchAll();

					if(isset($dirForMoveMessagesId[0]['ID']))
					{
						$dirForMoveMessagesId = $dirForMoveMessagesId[0]['ID'];
						$mailboxHelper = Helper\Mailbox::createInstance($mailboxId);
						$dirForMoveMessages = $mailboxHelper->getDirsHelper()->getDirByPath('INBOX');
						MessageFolder::increaseDirCounter($mailboxId, $dirForMoveMessages, $dirForMoveMessagesId, $idsUnseenCount);

						Helper::updateMailboxUnseenCounter($mailboxId);
						$mailboxHelper->updateGlobalCounterForCurrentUser();
					}
				}
			}
		}

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
		$extractionResult = self::extractIds($ids);
		$result = new \Bitrix\Main\Result();

		if ($extractionResult->isSuccess())
		{
			$data = $extractionResult->getData();
			$mailMarkerManager = new MailsFoldersManager($data['mailboxId'], $data['messagesIds'], $userId);
			$mailboxId = $data['mailboxId'];

			$idsUnseenCount = MailMessageUidTable::getCount([
				'!@IS_SEEN' => ['Y', 'S'],
				'@ID' => $data['messagesIds'],
				'=MAILBOX_ID' => $mailboxId,
			]);

			$dirWithMessagesId = false;
			$dirForMoveMessagesId = [];

			if($idsUnseenCount)
			{
				$dirWithMessagesId = MessageFolder::getDirIdForMessages($mailboxId,$data['messagesIds']);

				$dirForMoveMessagesId = MailboxDirectoryTable::getList([
					'select' => [
						'ID',
					],
					'filter' => [
						'=PATH' => $folderPath,
						'=MAILBOX_ID' => $mailboxId,
					],
					'limit' => 1,
				])->fetchAll();
			}

			$result = $mailMarkerManager->moveMails($folderPath);

			if ($result->isSuccess())
			{
				if ($dirWithMessagesId && isset($dirForMoveMessagesId[0]['ID']))
				{
					$dirForMoveMessagesId = $dirForMoveMessagesId[0]['ID'];

					MessageFolder::decreaseDirCounter($mailboxId, $dirWithMessagesId, $idsUnseenCount);

					$mailboxHelper = Helper\Mailbox::createInstance($mailboxId);
					$dirForMoveMessages = $mailboxHelper->getDirsHelper()->getDirByPath($folderPath);

					MessageFolder::increaseDirCounter($mailboxId, $dirForMoveMessages, $dirForMoveMessagesId, $idsUnseenCount);

					\Bitrix\Mail\Helper::updateMailboxUnseenCounter($mailboxId);
					$mailboxHelper->updateGlobalCounterForCurrentUser();
				}
			}
		}

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

		if (!empty($ids['for_all']))
		{
			[$mailboxId, $dir] = explode('-', $ids['for_all']);

			$ids = [];

			$res = MailMessageUidTable::getList([
				'select' => ['ID'],
				'filter' => [
					'=MAILBOX_ID' => $mailboxId,
					'=DIR_MD5' => md5($dir),
					'>MESSAGE_ID' => 0,
					'@IS_SEEN' => $seen ? ['N', 'U'] : ['Y', 'S'],
					'==DELETE_TIME' => 0,
				],
			]);

			while ($item = $res->fetch())
			{
				$ids[] = "{$item['ID']}-{$mailboxId}";
			}
		}

		$extractionResult = self::extractIds($ids, true);

		if ($extractionResult->isSuccess())
		{
			$data = $extractionResult->getData();
			$mailMarkerManager = new MailsFlagsManager($data['mailboxId'], $data['messagesIds']);
			$result = $mailMarkerManager->$method();

		}

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
			$message['BODY_HTML'] = \Bitrix\Mail\Helper\Message::sanitizeHtml($message['BODY_HTML'], true);
		}
	}

	public static function createCrmActivity(int $messageId, int $iteration = 1): \Bitrix\Main\Result
	{
		$result = new \Bitrix\Main\Result();

		if (!Loader::includeModule('crm'))
		{
			$result->addError(new \Bitrix\Main\Error(Loc::getMessage('MAIL_MESSAGE_ACTIONS_NO_CRM')));
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
			'select' => [
				'*',
				'MAILBOX_EMAIL' => 'MAILBOX.EMAIL',
				'MAILBOX_NAME' => 'MAILBOX.NAME',
				'MAILBOX_LOGIN' => 'MAILBOX.LOGIN',
				'IS_SEEN' => 'MESSAGE_UID.IS_SEEN',
				'MSG_HASH' => 'MESSAGE_UID.HEADER_MD5',
				'DIR_MD5' => 'MESSAGE_UID.DIR_MD5',
				'MSG_UID' => 'MESSAGE_UID.MSG_UID',
			],
			'filter' => [
				'=ID' => $messageId,
			],
			'order' => [
				'FIELD_DATE' => 'DESC',
				'MESSAGE_UID.ID' => 'DESC',
				'MESSAGE_UID.MSG_UID' => 'ASC',
			],
			'limit' => 1,
		])->fetch();

		if (empty($message) || !Helper\Message::hasAccess($message))
		{
			$result->addError(new \Bitrix\Main\Error(Loc::getMessage('MAIL_MESSAGE_ACTIONS_NO_MESSAGE')));
			return $result;
		}

		if ($iteration <= 1 && Helper\Message::ensureAttachments($message) > 0)
		{
			return self::createCrmActivity($messageId, $iteration + 1);
		}

		Helper\Message::prepare($message);

		$message['IS_OUTCOME'] = $message['__is_outcome'];
		$message['IS_SEEN'] = in_array($message['IS_SEEN'], ['Y', 'S']);

		$message['__forced'] = true;

		self::sanitizeHtmlForOldCrmModule($message);

		if (!\CCrmEMail::imapEmailMessageAdd($message, null, $error))
		{
			$result->addError(new \Bitrix\Main\Error(Loc::getMessage('MAIL_MESSAGE_ACTIONS_UNKNOWN_ERROR')));
		}

		return $result;
	}

}