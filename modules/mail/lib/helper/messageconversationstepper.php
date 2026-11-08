<?php

namespace Bitrix\Mail\Helper;

use Bitrix\Mail\Internal\Service\Message\Conversation\ConversationBackfill;
use Bitrix\Main;

/**
 * Fills the conversation projection of the mailboxes that already existed, in resumable portions, and
 * lets a mailbox group its list only after a whole pass over it and the check that follows.
 *
 * The pass walks the active IMAP mailboxes in the order of their identifiers and each mailbox in the
 * order of its messages. The cursors and a stable-pass flag in `$option` are enough to continue after
 * a stop at any moment. Projecting an unchanged component writes nothing, so repeating a portion is
 * free, and repeating a whole pass heals a mailbox whose conversations have to be merged.
 *
 * A mailbox that is already allowed to group its list is skipped: the pass is armed again by every
 * newly connected mailbox and by every failure of the inline projection, and re-walking the whole
 * portal each time would cost far more than it repairs. Readiness is therefore also the way to ask
 * for a repeated pass: whatever takes it back gets the mailbox walked again.
 *
 * The pass never sets readiness by itself; {@see ConversationBackfill::completePass()} does, and only
 * when its check finds nothing missing and nothing failed.
 */
class MessageConversationStepper extends Main\Update\Stepper
{
	/** Mailboxes one step looks at before it hands the time back. */
	private const MAILBOX_PORTION = 10;
	/** A transient reconciliation failure gets one whole-pass retry without an external event. */
	private const COMPLETE_PASS_RETRY_LIMIT = 1;

	private const STAGE_INDEX = 'index';
	private const STAGE_PRUNE = 'prune';

	protected static $moduleId = 'mail';

	private ConversationBackfill $backfill;

	public function __construct(?ConversationBackfill $backfill = null)
	{
		$this->backfill = $backfill ?? new ConversationBackfill();
	}

	public static function execAgent()
	{
		if (Main\Config\Option::get('mail', 'history_backfill_stopped') !== 'N')
		{
			return '';
		}

		return parent::execAgent();
	}

	public function execute(array &$option): bool
	{
		if (Main\Config\Option::get('mail', 'history_backfill_stopped') !== 'N')
		{
			// Keep the cursor: FINISH_EXECUTION would delete the saved progress.
			return self::CONTINUE_EXECUTION;
		}

		if (!isset($option['mailboxId'], $option['stage']))
		{
			$this->startMailbox($option, 0);
		}

		$mailboxId = (int)$option['mailboxId'];
		if ($mailboxId <= 0)
		{
			return $this->continueWithNextMailbox($option);
		}

		if ((string)$option['stage'] === self::STAGE_INDEX)
		{
			return $this->indexPortion($option, $mailboxId);
		}

		return $this->prunePortion($option, $mailboxId);
	}

	private function indexPortion(array &$option, int $mailboxId): bool
	{
		$portion = $this->backfill->indexPortion(
			$mailboxId,
			(int)$option['lastMessageId'],
			ConversationBackfill::PORTION_SIZE,
		);

		$option['lastMessageId'] = $portion['lastMessageId'];
		$option['failed'] = (int)$option['failed'] + $portion['failed'];
		$option['steps'] = (int)($option['steps'] ?? 0) + $portion['processed'];
		$option['count'] = max((int)($option['count'] ?? 0), (int)$option['steps']);
		$option['merged'] = (bool)($option['merged'] ?? false) || $portion['merged'];
		if ($portion['exhausted'])
		{
			if ($option['merged'])
			{
				$option['lastMessageId'] = 0;
				$option['merged'] = false;
			}
			else
			{
				$option['stage'] = self::STAGE_PRUNE;
			}
		}

		return self::CONTINUE_EXECUTION;
	}

	private function prunePortion(array &$option, int $mailboxId): bool
	{
		$portion = $this->backfill->prunePortion($mailboxId, ConversationBackfill::PORTION_SIZE);
		$option['failed'] = (int)$option['failed'] + $portion['failed'];
		if (!$portion['exhausted'])
		{
			return self::CONTINUE_EXECUTION;
		}

		if (!$this->backfill->completePass($mailboxId, (int)$option['failed']))
		{
			$retryCount = (int)($option['completePassRetries'] ?? 0);
			if ($retryCount < self::COMPLETE_PASS_RETRY_LIMIT)
			{
				$this->startMailbox($option, $mailboxId);
				$option['completePassRetries'] = $retryCount + 1;

				return self::CONTINUE_EXECUTION;
			}
		}

		return $this->continueWithNextMailbox($option);
	}

	/**
	 * Moves on to the next mailbox that still has to be walked, and ends the pass when the portal has
	 * none left.
	 */
	private function continueWithNextMailbox(array &$option): bool
	{
		$lastMailboxId = (int)$option['lastMailboxId'];
		$mailboxIds = $this->backfill->findMailboxIdsAfter($lastMailboxId, self::MAILBOX_PORTION);
		foreach ($mailboxIds as $mailboxId)
		{
			$option['lastMailboxId'] = $mailboxId;
			if ($this->backfill->isReady($mailboxId))
			{
				continue;
			}

			$this->startMailbox($option, $mailboxId);

			return self::CONTINUE_EXECUTION;
		}

		if (count($mailboxIds) === self::MAILBOX_PORTION)
		{
			// Every mailbox of the portion is ready already; the next step looks at the following ones.
			$option['mailboxId'] = 0;

			return self::CONTINUE_EXECUTION;
		}

		return self::FINISH_EXECUTION;
	}

	private function startMailbox(array &$option, int $mailboxId): void
	{
		if ($mailboxId > 0)
		{
			$this->backfill->startActivityRebuild($mailboxId);
		}
		$option['mailboxId'] = $mailboxId;
		$option['lastMailboxId'] = max((int)($option['lastMailboxId'] ?? 0), $mailboxId);
		$option['lastMessageId'] = 0;
		$option['failed'] = 0;
		$option['merged'] = false;
		$option['completePassRetries'] = 0;
		$option['stage'] = self::STAGE_INDEX;
		$option['steps'] = (int)($option['steps'] ?? 0);
		$option['count'] = (int)($option['count'] ?? 0);
	}
}
