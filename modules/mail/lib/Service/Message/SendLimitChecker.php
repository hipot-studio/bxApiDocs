<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\Message;

use Bitrix\Bitrix24\MailCounter;
use Bitrix\Mail\Helper;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Mail\Sender;
use Bitrix\Main\Mail\SenderSendCounter;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Limits of the outgoing path, read by both sides of a delayed letter: the schedule warns the author while the
 * form is still open, and the moment of sending refuses a letter whose quota was spent in between.
 */
class SendLimitChecker
{
	public const ERROR_SEND_LIMIT = 'send_limit';

	/**
	 * @param string[] $to
	 * @param string[] $cc
	 * @param string[] $bcc
	 * @param DateTime|null $sendAt When the letter is meant to leave; null stands for the current moment.
	 */
	public function check(string $sender, array $to, array $cc, array $bcc, ?DateTime $sendAt = null): Result
	{
		$tariffLimit = Helper\LicenseManager::getEmailsLimitToSendMessage();
		if (
			$tariffLimit !== -1
			&& (count($to) > $tariffLimit || count($cc) > $tariffLimit || count($bcc) > $tariffLimit)
		)
		{
			return $this->error(SendMessageService::ERROR_TOO_MANY_RECIPIENTS, 'Recipient limit exceeded.');
		}

		$total = count($to) + count($cc) + count($bcc);
		if ($this->isCountedDay($sendAt))
		{
			$senderLimit = Sender::getEmailLimit($sender);
			if ($senderLimit > 0 && (new SenderSendCounter())->get($sender) + $total > $senderLimit)
			{
				return $this->error(self::ERROR_SEND_LIMIT, 'Daily sender limit reached.');
			}
		}

		if (isModuleInstalled('bitrix24') && Loader::includeModule('bitrix24'))
		{
			$counter = new MailCounter();
			if (
				($this->isCountedDay($sendAt)
					&& $counter->getDailyLimit() > 0
					&& MailCounter::checkLimit($counter->getDailyLimit(), $counter->get() + $total))
				|| ($this->isCountedMonth($sendAt)
					&& $counter->getLimit() > 0
					&& MailCounter::checkLimit($counter->getLimit(), $counter->getMonthly() + $total))
			)
			{
				return $this->error(self::ERROR_SEND_LIMIT, 'Portal send limit reached.');
			}
		}

		return new Result();
	}

	/**
	 * Counters are kept per calendar day and per calendar month of the portal, and only the current ones are
	 * read here. A letter that leaves later is not measured against them: a quota spent today says nothing
	 * about tomorrow, and refusing to schedule for next week over it would be a refusal of nothing.
	 */
	private function isCountedDay(?DateTime $sendAt): bool
	{
		return $sendAt === null || date('Y-m-d', $sendAt->getTimestamp()) === date('Y-m-d');
	}

	private function isCountedMonth(?DateTime $sendAt): bool
	{
		return $sendAt === null || date('Y-m', $sendAt->getTimestamp()) === date('Y-m');
	}

	private function error(string $code, string $message): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
