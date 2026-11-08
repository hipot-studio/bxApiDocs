<?php

declare(strict_types=1);

namespace Bitrix\MailMobile\Internal\Integration\Mail;

use Bitrix\Mail\Public\Service\MigrationNotice\MigrationNoticeService;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Result;
use Psr\Log\LoggerInterface;

/**
 * DTO-03. The one place where this module asks the mail module which migration message is due on the
 * message list. No rule of the display lives here and none is repeated: the answer of the mail module
 * travels on as it came.
 *
 * The question is put to the public point of the mail module and to nothing behind it. The rules
 * service, the enum of the surfaces and the decision object are the private zone of that module: its
 * owner renames them without telling anyone, and a boundary standing on them breaks in silence.
 *
 * The two modules are released on their own, so the whole contract is asked for before any of it is
 * used: a mail module that carries no public point of the migration yet answers nothing to show, and
 * the list of letters opens as it always did instead of failing on a class that is not there.
 *
 * Softness is not silence. "Nothing to show" is also the answer of a module in perfect order, so every
 * way out that is not an answer says so in the log of this module: without that line a torn channel
 * and a working one look the same from the outside, and a portal can carry the feature switched on
 * while no message ever reaches a single person.
 */
final class MigrationNoticeProvider
{
	private const LOGGER_ID = 'mailmobile.MigrationNotice';

	/** The surface of this module, named as the mail module spells it rather than as a case of its enum. */
	private const MESSAGE_LIST_SURFACE = 'mobile_list';

	/**
	 * DTO-01 the way a surface with nothing to show carries it. The keys are spelled out rather than
	 * copied from the public point of the mail module: this is the answer for the case where that
	 * point is exactly what is missing.
	 */
	private const NOTHING_TO_SHOW = [
		'code' => 'none',
		'mailboxId' => null,
		'title' => '',
		'text' => '',
		'buttons' => [],
		'dismissHint' => '',
		'reactionToken' => '',
	];

	public function __construct(private ?LoggerInterface $logger = null)
	{
	}

	/**
	 * @param int $mailboxId The mailbox the list stands on; a list that stands on none passes zero.
	 *
	 * @return array<string, mixed>
	 */
	public function forMessageList(int $userId, int $mailboxId): array
	{
		if (!class_exists(MigrationNoticeService::class))
		{
			return $this->nothingToShow('the mail module carries no public point of the migration notices');
		}

		$answer = (new MigrationNoticeService())->decideFor(
			$userId,
			self::MESSAGE_LIST_SURFACE,
			$mailboxId > 0 ? $mailboxId : null,
		);

		if (!$answer->isSuccess())
		{
			return $this->nothingToShow('the mail module gave no decision: ' . $this->firstReason($answer));
		}

		$decision = $answer->getData();

		/*
			An answer the client cannot read is worth no more than no answer at all: the list tells the
			messages apart by the code of the decision, and a decision without one leaves it drawing
			nothing while the boundary reports success.
		*/
		return isset($decision['code'])
			? $decision
			: $this->nothingToShow('the answer of the mail module carries no decision')
		;
	}

	/**
	 * Why the mail module refused, in the words of the mail module. The code of the error is what this
	 * module logs and never what it branches on: a reason it has never heard of is still a reason, and
	 * the way out of every one of them is the same.
	 */
	private function firstReason(Result $answer): string
	{
		$error = $answer->getErrors()[0] ?? null;

		return $error === null ? 'no reason given' : (string)$error->getCode();
	}

	/** @return array<string, mixed> */
	private function nothingToShow(string $reason): array
	{
		$this->report($reason);

		return self::NOTHING_TO_SHOW;
	}

	/**
	 * The report may not cost the list of letters anything: a logger that cannot be built is one more
	 * thing the list opens without.
	 */
	private function report(string $reason): void
	{
		try
		{
			$logger = $this->logger ??= (new LoggerFactory())->createById(self::LOGGER_ID);

			$logger?->error('The contract of the migration notices is not available: {reason}', [
				'reason' => $reason,
				'module' => 'mail',
			]);
		}
		catch (\Throwable)
		{
		}
	}
}
