<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage mail
 * @copyright 2001-2025 Bitrix
 */

require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/mail/classes/general/mail.php");

class CMailbox extends CAllMailBox
{
	public static function CleanUp()
	{
		$connection = \Bitrix\Main\Application::getConnection();
		$helper = $connection->getSqlHelper();
		$days = COption::GetOptionInt("mail", "time_keep_log", B_MAIL_KEEP_LOG);

		$strSql = "DELETE FROM b_mail_log WHERE DATE_INSERT < " . $helper->addDaysToDateTime(-intval($days));
		$connection->query($strSql);

		$mt = microtime(true);
		$dbr = $connection->query("
			SELECT MS.ID, MS.MAILBOX_ID
			FROM
				b_mail_message MS
				INNER JOIN b_mail_mailbox MB ON MS.MAILBOX_ID = MB.ID
			WHERE
				MB.MAX_KEEP_DAYS > 0
				AND MS.DATE_INSERT < ".$helper->addDaysToDateTime('-MB.MAX_KEEP_DAYS')."
		");
		/*
			The retention limit deletes the letters for good, so the projection of the conversations
			loses them as well - in batches, so that a long run neither asks per letter nor keeps a
			growing list of them.
		*/
		$portionSize = \Bitrix\Mail\Internal\Service\Message\Conversation\ConversationBackfill::PORTION_SIZE;
		$conversationBackfill = new \Bitrix\Mail\Internal\Service\Message\Conversation\ConversationBackfill();
		$deletedByMailbox = [];
		while ($ar = $dbr->fetch())
		{
			$mailboxId = (int)$ar["MAILBOX_ID"];
			CMailMessage::Delete($ar["ID"], $mailboxId);
			$deletedByMailbox[$mailboxId][] = (int)$ar["ID"];
			if (count($deletedByMailbox[$mailboxId]) >= $portionSize)
			{
				$conversationBackfill->removeMessages($mailboxId, $deletedByMailbox[$mailboxId]);
				unset($deletedByMailbox[$mailboxId]);
			}
			if (microtime(true) - $mt > 10 * 1000)
				break;
		}

		foreach ($deletedByMailbox as $mailboxId => $messageIds)
		{
			$conversationBackfill->removeMessages($mailboxId, $messageIds);
		}

		\Bitrix\Mail\Helper\Message\MessageInternalDateHandler::clearStartInternalDate();

		return "CMailbox::CleanUp();";
	}
}

class CMailUtil extends CAllMailUtil
{
	public static function IsSizeAllowed($size)
	{
		global $B_MAIL_MAX_ALLOWED;

		$dbConnection = \Bitrix\Main\Application::getConnection();

		$B_MAIL_MAX_ALLOWED = $dbConnection->getMaxAllowedPacket();

		return $B_MAIL_MAX_ALLOWED > $size;
	}
}

class CMailMessage extends CAllMailMessage
{
}
