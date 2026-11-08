<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\MigrationNotice;

use Bitrix\Mail\Internal\Service\MigrationNotice\NoticeDecision;
use Bitrix\Mail\Internal\Service\MigrationNotice\NoticeDecisionService;
use Bitrix\Mail\Internal\Service\MigrationNotice\NoticeSurface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * The messages about the migration of mail to Bitrix24 Mail, as a neighbouring module may ask about
 * them. A consumer that hosts a surface of its own asks this instead of the rules service: which
 * message is due, about which mailbox, with which texts and buttons belongs to the mail module, and a
 * second copy of any of it would let two surfaces of one portal disagree about one person.
 *
 * The private zone stays behind this point on purpose. A consumer naming the rules service, the enum
 * of the surfaces or the decision object is a consumer that breaks on a rename nobody announced, and
 * the surfaces of this feature live in modules released apart from this one.
 *
 * Nothing of the private zone travels out: the surface is named by its code, and the decision leaves
 * as the array DTO-01 that the web and the mobile client already read.
 */
final class MigrationNoticeService
{
	/** The surface named in the request is not one this build of the module carries. */
	public const ERROR_UNKNOWN_SURFACE = 'mail_migration_notice_unknown_surface';

	/** The rules of the display are not to be had on this portal, so no decision can be given at all. */
	public const ERROR_RULES_UNAVAILABLE = 'mail_migration_notice_rules_unavailable';

	/**
	 * @param NoticeDecisionService|null $decisions The rules of the display. The registration of the
	 *     module ({@see \Bitrix\Mail\Internal\Service\MigrationNotice\NoticeDecisionService}) answers
	 *     for the portal; a caller with rules of its own - a case, a scenario of a stand - passes them.
	 */
	public function __construct(private ?NoticeDecisionService $decisions = null)
	{
	}

	/**
	 * What to show this person on this surface right now.
	 *
	 * @param string $surface The code of the surface: "portal", "mail", "crm" or "mobile_list". It is
	 *     a code and not a case of an enum on purpose - a consumer released apart from this module
	 *     names a surface this build may no longer carry, and is answered rather than broken.
	 * @param int|null $mailboxId The mailbox the surface stands on, when it stands on one.
	 *
	 * @return Result The data of a successful result is the decision DTO-01, key for key as the
	 *     clients read it. A result that failed carries the reason as the code of its error and no
	 *     decision: an unknown surface ({@see self::ERROR_UNKNOWN_SURFACE}) or rules this portal
	 *     cannot reach ({@see self::ERROR_RULES_UNAVAILABLE}).
	 */
	public function decideFor(int $userId, string $surface, ?int $mailboxId = null): Result
	{
		$result = new Result();

		$noticeSurface = NoticeSurface::tryFrom($surface);
		if ($noticeSurface === null)
		{
			return $result->addError(new Error(
				'The mail module carries no surface "' . $surface . '"',
				self::ERROR_UNKNOWN_SURFACE,
			));
		}

		$decisions = $this->resolveDecisions();
		if ($decisions === null)
		{
			return $result->addError(new Error(
				'The rules of the migration messages are not registered on this portal',
				self::ERROR_RULES_UNAVAILABLE,
			));
		}

		return $result->setData($decisions->decide($userId, $noticeSurface, $mailboxId)->toArray());
	}

	/**
	 * Whether asking about this surface can be answered with anything at all right now.
	 *
	 * A surface where the asking is itself the cost - a page that has to bring an extension in and
	 * spend a request before it learns there is nothing to draw - asks this first. It reads nothing
	 * about a mailbox and writes nothing down, so it is as cheap as the switch of the feature plus the
	 * one state that belongs to the person; what it cannot do is promise a message, only rule one out.
	 *
	 * A portal that cannot reach the rules at all is answered `false`: a surface that asked anyway
	 * would be told the same thing by {@see self::decideFor()}, one request later.
	 *
	 * @param string $surface The code of the surface, as {@see self::decideFor()} takes it.
	 */
	public function mayHaveSomethingFor(int $userId, string $surface): bool
	{
		$noticeSurface = NoticeSurface::tryFrom($surface);

		return $noticeSurface !== null
			&& $this->resolveDecisions()?->mayHaveSomething($userId, $noticeSurface) === true
		;
	}

	/**
	 * DTO-01 the way a surface with nothing to show carries it.
	 *
	 * A consumer that has to answer without asking - a request that never reached this module, a
	 * client that must be given a field of the shape every decision has - copies this instead of
	 * writing the keys of the decision down a second time.
	 *
	 * @return array<string, mixed>
	 */
	public static function nothingToShow(): array
	{
		return NoticeDecision::none()->toArray();
	}

	/**
	 * The rules of the display of this portal. The key of the registration is a string, so what comes
	 * back under it is whatever the settings of the portal put there: a portal that registered
	 * something else has no rules to speak of, and that is a reason the caller is told rather than a
	 * failure in the middle of building an answer.
	 */
	private function resolveDecisions(): ?NoticeDecisionService
	{
		if ($this->decisions !== null)
		{
			return $this->decisions;
		}

		try
		{
			$registered = ServiceLocator::getInstance()->get(NoticeDecisionService::class);
		}
		catch (\Throwable)
		{
			return null;
		}

		return $this->decisions = ($registered instanceof NoticeDecisionService ? $registered : null);
	}
}
