<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Async\Receiver;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Internal\Async\Message\VacationAutoReplyMessage;
use Bitrix\Mail\Internal\Service\SourceGeneration\GenerationScope;
use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Mail\Service\VacationAutoReply\VacationAutoReplyResponder;
use Bitrix\Mail\Service\VacationAutoReply\VacationAutoReplyService;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\NoLoggableException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\RecoverableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

/**
 * Sends the vacation auto-reply outside the pass that brought the letter in.
 *
 * The answer cannot leave from inside the arrival event at all: `Helper\Mailbox\Imap::syncMessage()` ties
 * the letter to its uid row only after the event returns, while the send path looks the letter up through
 * that very tie (`Storage\Message::getMessage()` over `MailMessageTable::getConsistentById()`). A job that
 * finds no tie yet is a letter still arriving and not a failure, so it is given back for another pass.
 *
 * Only identifiers travel in the job, so everything the answer depends on is asked again here: the switch
 * of the feature, the setting of the mailbox with its period and the folders the letter lies in now. The
 * period that has ended, the sender already answered within it and the letter thrown away meanwhile all
 * keep the answer in - the first two through the rules of the domain, the last one through the folders
 * read below.
 */
class VacationAutoReplyReceiver extends AbstractReceiver
{
	/**
	 * A pass sends letters out, and the far side of a mail session is the slow part of that, so the budget
	 * is checked between letters instead of trusting their count.
	 */
	private const PASS_TIME_BUDGET = 15;

	/**
	 * Folders whose letters get no answer, in the fields of `b_mail_mailbox_dir`. The arrival event names
	 * the same four, and the rules of the domain judge "not incoming" by them.
	 */
	private const FOLDER_FLAGS = ['IS_OUTCOME', 'IS_DRAFT', 'IS_TRASH', 'IS_SPAM'];

	private ?float $passStartedAt = null;

	/** The receiver may outlive a pass as an instance of the service locator, its budget may not. */
	public function run(): void
	{
		$this->passStartedAt = null;

		parent::run();
	}

	protected function process(MessageInterface $message): void
	{
		if (!$message instanceof VacationAutoReplyMessage)
		{
			throw new UnprocessableMessageException($message);
		}

		if ($this->isTimeBudgetSpent())
		{
			throw new RecoverableMessageException(
				sprintf('vacation auto-reply: the pass time budget of %d s is spent', self::PASS_TIME_BUDGET),
			);
		}

		if (!Feature::isVacationAutoReplyAvailable())
		{
			return;
		}

		$setting = $this->getSettingService()->getSetting($message->mailboxId);
		if ($setting === null)
		{
			return;
		}

		$letter = $this->loadLetter($message->mailboxId, $message->messageId);
		if ($letter === null)
		{
			// The retry is spent on purpose, and RecoverableMessageException is not used: it hands the job
			// back without spending one, and a letter that never gets its uid row - deleted while the job
			// waited - would then come back to the queue forever.
			throw new NoLoggableException(sprintf(
				'vacation auto-reply: the letter mailboxId=%d messageId=%d is not in the mailbox (yet)',
				$message->mailboxId,
				$message->messageId,
			));
		}

		// The job exists because the letter had just arrived: a letter of a history pass is turned down by
		// the seam before the queue, so that a load of old mail costs the queue nothing. A failed send is
		// left as it is - the answer has no retry rules of its own.
		$this->getResponder()->respond($letter, $setting, isFreshArrival: true);
	}

	/**
	 * RecoverableMessageException gives the leftovers of the batch back without spending a retry; the
	 * first letter of a pass always runs.
	 */
	private function isTimeBudgetSpent(): bool
	{
		$now = $this->currentTime();

		$this->passStartedAt ??= $now;

		return ($now - $this->passStartedAt) >= self::PASS_TIME_BUDGET;
	}

	/**
	 * The letter in the shape the arrival event publishes it, as it stands now: its own fields plus the
	 * folder flags. The mailbox is part of every filter and not a check of its own - a job naming a foreign
	 * mailbox must not read the letter at all.
	 *
	 * @return array<string, mixed>|null Null when the letter is not in the mailbox: no live uid row (still
	 *         arriving, or already deleted) or no row of the letter itself left.
	 */
	private function loadLetter(int $mailboxId, int $messageId): ?array
	{
		$folders = $this->readLiveFolderFlags($mailboxId, $messageId);
		if ($folders === [])
		{
			return null;
		}

		// HEADER of the letter is the copy in the database, and only an oversized letter loses the tail of
		// it (`CMailMessage::addMessage()` trims the insert to the maximum packet). The parse cannot read
		// the header of the event instead: the event is long gone by the time the job runs.
		$letter = MailMessageTable::getRow([
			'select' => ['HEADER', 'SUBJECT', 'FIELD_FROM', 'FIELD_REPLY_TO', 'FIELD_TO', 'FIELD_CC'],
			'filter' => [
				'=ID' => $messageId,
				'=MAILBOX_ID' => $mailboxId,
			],
		]);
		if ($letter === null)
		{
			return null;
		}

		return $letter + $folders + [
			'ID' => $messageId,
			'MAILBOX_ID' => $mailboxId,
		];
	}

	/**
	 * Flags of every folder the letter lies in right now. A letter lies in more than one at a time (labels
	 * of gmail are folders of imap) and a move is two rows of its own for a while, so a letter counted as
	 * sent, draft, trash or spam anywhere is counted so here: silence costs less than an answer on behalf
	 * of a letter the user has thrown away.
	 *
	 * A folder the letter names but the mailbox no longer has leaves the flags as they are - an unknown
	 * folder is no proof of anything, and the letter stays answerable.
	 *
	 * @return array<string, bool> Empty when the letter has no live uid row at all.
	 */
	private function readLiveFolderFlags(int $mailboxId, int $messageId): array
	{
		$scope = GenerationScope::forMailbox($mailboxId);

		// The folder is scoped as well as the placement: both generations of a mailbox keep a folder of
		// the same path, so an unscoped join reads the flags from the folder of the retained one.
		$filter = $scope->apply([
			'=MAILBOX_ID' => $mailboxId,
			'=MESSAGE_ID' => $messageId,
			'==DELETE_TIME' => 0,
		]);

		$rows = MailMessageUidTable::getList([
			'select' => [
				'IS_OUTCOME' => 'FOLDER.IS_OUTCOME',
				'IS_DRAFT' => 'FOLDER.IS_DRAFT',
				'IS_TRASH' => 'FOLDER.IS_TRASH',
				'IS_SPAM' => 'FOLDER.IS_SPAM',
			],
			'filter' => $scope->apply($filter, 'FOLDER.'),
			'runtime' => [
				new Reference(
					'FOLDER',
					MailboxDirectoryTable::class,
					Join::on('this.DIR_MD5', 'ref.DIR_MD5')->whereColumn('this.MAILBOX_ID', 'ref.MAILBOX_ID'),
				),
			],
		])->fetchAll();

		if ($rows === [])
		{
			return [];
		}

		$flags = array_fill_keys(self::FOLDER_FLAGS, false);
		foreach ($rows as $row)
		{
			foreach (self::FOLDER_FLAGS as $flag)
			{
				$flags[$flag] = $flags[$flag] || !empty($row[$flag]);
			}
		}

		return $flags;
	}

	protected function currentTime(): float
	{
		return microtime(true);
	}

	protected function getSettingService(): VacationAutoReplyService
	{
		return new VacationAutoReplyService();
	}

	protected function getResponder(): VacationAutoReplyResponder
	{
		return new VacationAutoReplyResponder();
	}
}
