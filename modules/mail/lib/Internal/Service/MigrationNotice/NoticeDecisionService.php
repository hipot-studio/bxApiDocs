<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MigrationNotice;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\Mailbox\MailboxSyncManager;
use Bitrix\Mail\Helper\Mailbox\ProviderAccessRestriction;
use Bitrix\Mail\Integration\MailService\MigrationStatusProvider;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\MailServicesTable;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Sign\Signer;

Loc::loadMessages(__FILE__);

/**
 * The only place where the features of a person and of their mailboxes add up to one decision:
 * which message to show on this surface, about which mailbox, with which buttons and texts.
 *
 * The priority and the displacement rules are ALG-01 and live here alone. Nothing of them reaches
 * the clients, and nothing of them is duplicated in the entry points of the module. One rule joins
 * them outside ALG-01: where no migration message is due about a mailbox whose mail stopped
 * arriving, the decision carries the offer that goes next to the red alert of that mailbox
 * ({@see self::offerOrNothing()}).
 *
 * None of the features is computed here: the problem state of a mailbox comes from the state
 * service of the module, the transfer status from the public provider, and the mailboxes from the
 * ownership selection - each read for the whole set at once. The states of what a person has
 * already seen belong to {@see NoticeStateStorage} and are never read or written past it; the only
 * thing the service keeps on its own is the short-lived mark of a fullscreen message of the sitting.
 *
 * The decision is not cached: it depends on states a person changes by hand.
 */
class NoticeDecisionService
{
	public const ERROR_INVALID_TOKEN = 'invalid_reaction_token';
	public const ERROR_TOKEN_EXPIRED = 'reaction_token_expired';

	/** API-02. What a person can answer a message with, as it travels between the clients and here. */
	public const REACTION_SHOWN = 'shown';
	public const REACTION_REMIND_LATER = 'remind_later';
	public const REACTION_DISMISSED = 'dismissed';

	private const TOKEN_VERSION = 1;
	private const TOKEN_SALT = 'mail.migration_notice.reaction';
	private const TOKEN_TTL = 86400;
	private const MAX_TOKEN_LENGTH = 512;

	/**
	 * The mark of a fullscreen message already issued. It lives in the temporary server-side storage
	 * of the kernel and not in the session of the web server: the mobile application has no session,
	 * while the rule "no one gets two fullscreen messages in one sitting" is the same on both.
	 */
	private const FULLSCREEN_KEY_PREFIX = 'mail.migration_notice.fullscreen_issued.';
	private const FULLSCREEN_TTL = 3600;

	/** The switch of the mailbox to the new source is done; the import of the history may go on. */
	private const TRANSFERRED_STATUS = 'done';

	/** The connection form of the Mail section, as the rest of the module addresses it. */
	private const MAILBOX_CONNECT_URL = '/mail/config/';

	private const BUTTON_PHRASES = [
		NoticeDecision::TRANSFER_MAIL => 'MAIL_MIGRATION_NOTICE_BTN_TRANSFER',
		NoticeDecision::REMIND_LATER => 'MAIL_MIGRATION_NOTICE_BTN_REMIND',
		NoticeDecision::DETAILS => 'MAIL_MIGRATION_NOTICE_BTN_DETAILS',
		NoticeDecision::CREATE_COMPANY_MAIL => 'MAIL_MIGRATION_NOTICE_BTN_CREATE',
		NoticeDecision::CONNECT_EXTERNAL => 'MAIL_MIGRATION_NOTICE_BTN_CONNECT_EXTERNAL',
		NoticeDecision::CONNECT_B24_MAIL => 'MAIL_MIGRATION_NOTICE_BTN_CONNECT_B24',
	];

	public function __construct(
		private readonly NoticeStateStorage $states = new NoticeStateStorage(),
		private readonly MigrationStatusProvider $migrationStatuses = new MigrationStatusProvider(),
		private readonly Signer $signer = new Signer(),
		private ?StorageInterface $storage = null,
	)
	{
	}

	/**
	 * ALG-01. The order of the checks is the rule itself and may not be rearranged.
	 *
	 * @param int|null $mailboxId The mailbox of the surface, when the surface knows one.
	 */
	public function decide(int $userId, NoticeSurface $surface, ?int $mailboxId = null): NoticeDecision
	{
		if ($userId <= 0 || !$this->isAvailable())
		{
			return NoticeDecision::none();
		}

		if ($this->isAnnouncementAvailable() && !$this->states->wasAnnouncementShown($userId))
		{
			/*
				The announcement is still owed to this person, and a surface that cannot draw it neither
				takes it nor puts a message of its own in its place: it would be showing the second
				fullscreen message of the sitting while the first one is still due (AC-004).
			*/
			if (!$surface->carriesAnnouncement())
			{
				return NoticeDecision::none();
			}

			/*
				The one announcement of a lifetime is spent on the person who saw it and not on the
				request that fetched it: between handing it out and drawing it lie a lazy extension, a
				queue of the portal banners and, in the application, a neighbouring request that may
				fail for both. The client reports the showing, exactly as it does for the migration
				popup, and until it does the announcement stays owed (AC-002, ERR-001).

				The mark of the sitting is what keeps that from becoming two announcements at once: the
				application asks on its way into mail and mounts the list right after, and the first
				of the two answers is still on its way to the screen. An announcement that never got
				there is therefore owed again from the next sitting on, and not from the next
				request.
			*/
			if ($this->wasFullscreenIssued($userId))
			{
				return NoticeDecision::none();
			}

			$this->markFullscreenIssued($userId);

			return $this->build(NoticeCode::Announcement, $userId, $surface, null);
		}

		if (!$surface->carriesMigrationMessages())
		{
			return NoticeDecision::none();
		}

		if ($this->wasFullscreenIssued($userId))
		{
			return NoticeDecision::none();
		}

		$selection = $this->selectMailboxes($userId, $mailboxId);

		/*
			The state of the display belongs to a mailbox and not to the person, so a mailbox that has
			nothing left to say hands the turn on instead of silencing the rest (ERR-002). The order of
			the candidates is the priority itself - the broken ones stand in front - and the first
			mailbox with a message due takes it: two migration messages are never shown at once.
		*/
		$states = $this->states->getMailboxStates(array_column($selection['candidates'], 'id'));
		foreach ($selection['candidates'] as $candidate)
		{
			$decision = $this->decideForMailbox($candidate, $states[$candidate['id']], $userId, $surface);
			if ($decision !== null)
			{
				return $decision;
			}
		}

		return $this->offerOrNothing($selection['brokenMailboxId'], $userId, $surface);
	}

	/**
	 * ALG-01 as far as it reaches before a single mailbox is read: whether asking this surface for a
	 * decision can be answered with anything at all.
	 *
	 * It is for a surface where the asking is itself the cost - a page that would bring an extension in
	 * and spend a request of its own only to be told there is nothing to show - and it is built out of
	 * the two checks {@see self::decide()} makes before it looks at a mailbox, so that the two cannot
	 * drift apart: the switch of the feature, and the announcement that displaces every other message
	 * while it is still owed.
	 *
	 * No page asks it at present: the one that did, the panel of the portal, no longer draws the
	 * announcement at all.
	 *
	 * Nothing is read about a mailbox here and nothing is written down: a surface answered `false` is
	 * promised no message, a surface answered `true` is promised nothing at all.
	 */
	public function mayHaveSomething(int $userId, NoticeSurface $surface): bool
	{
		if ($userId <= 0 || !$this->isAvailable())
		{
			return false;
		}

		if ($this->isAnnouncementAvailable() && !$this->states->wasAnnouncementShown($userId))
		{
			return $surface->carriesAnnouncement();
		}

		return $surface->carriesMigrationMessages();
	}

	/**
	 * API-02. What the person did with the message, written down by the states that message has.
	 *
	 * Which reaction spends which message is a rule of the display like the order of ALG-01 itself,
	 * and lives here for the same reason: the entry point that takes a reaction knows the shape of the
	 * request and is meant to know nothing else.
	 *
	 * Drawing the migration popup is not the person answering it. The banner takes its place once the
	 * popup is closed and not before (AC-024, and the precondition of SC-004), so the report of the
	 * showing writes nothing about a mailbox: somebody who left for the helpdesk article the popup
	 * links to is in the middle of that showing and not past it, and the article is neither a decision
	 * nor a refusal. The announcement is the other way round - it belongs to the person, is due once
	 * for a lifetime, and the showing is the only reaction it has (AC-002).
	 *
	 * A reaction a message carries no button for writes nothing, and the same reaction twice leaves
	 * the same value behind.
	 */
	public function recordReaction(int $userId, NoticeCode $code, ?int $mailboxId, string $reaction): void
	{
		if ($code === NoticeCode::Announcement)
		{
			if ($reaction === self::REACTION_SHOWN)
			{
				$this->states->markAnnouncementShown($userId);
			}

			return;
		}

		// Every other message is about a mailbox
		if ($mailboxId === null)
		{
			return;
		}

		if ($code === NoticeCode::Banner)
		{
			if ($reaction === self::REACTION_DISMISSED)
			{
				$this->states->markBannerDismissed($mailboxId);
			}

			return;
		}

		if ($code !== NoticeCode::MigrationVariantA && $code !== NoticeCode::MigrationVariantB)
		{
			return;
		}

		if ($reaction === self::REACTION_REMIND_LATER)
		{
			// Only the variant A offers to postpone: a mailbox that fell off leaves nothing to postpone
			if ($code === NoticeCode::MigrationVariantA)
			{
				$this->states->remindLater($mailboxId);
			}

			return;
		}

		if ($reaction === self::REACTION_DISMISSED)
		{
			$this->states->markPopupShown($mailboxId, $code->value);
		}
	}

	/**
	 * ALG-01 for one mailbox: which migration message its state has left, or `null` when it has none
	 * and the turn goes to the mailbox behind it.
	 *
	 * @param array{id: int, isProblem: bool} $target
	 * @param array{popupShown: string|null, remindUntil: int|null, bannerDismissed: bool} $state
	 */
	private function decideForMailbox(
		array $target,
		array $state,
		int $userId,
		NoticeSurface $surface,
	): ?NoticeDecision
	{
		/*
			A mailbox that fell off after the variant A was shown is a new state and not a repeat of a
			message a person has already dealt with, so the variant B follows even when the banner was
			closed: the banner itself never comes back, the popup of the variant B does.
		*/
		$brokenAfterVariantA = $target['isProblem']
			&& $state['popupShown'] === NoticeCode::MigrationVariantA->value
		;

		if ($state['bannerDismissed'])
		{
			return $brokenAfterVariantA
				? $this->build(NoticeCode::MigrationVariantB, $userId, $surface, $target['id'])
				: null
			;
		}

		/*
			A postponed popup outranks the mark that it has been through, and the order of these two
			checks is what makes "remind later" mean anything: it moves the very popup a person is
			looking at to another day, and a mark left by an earlier showing would put the banner in
			its place instead (AC-011, AC-024). A mailbox that fell off meanwhile is postponed by
			nothing - the variant B follows at once.
		*/
		if ($state['remindUntil'] !== null && $state['remindUntil'] > time() && !$target['isProblem'])
		{
			return null;
		}

		if ($state['popupShown'] !== null)
		{
			return $this->build(
				$brokenAfterVariantA ? NoticeCode::MigrationVariantB : NoticeCode::Banner,
				$userId,
				$surface,
				$target['id'],
			);
		}

		return $this->build(
			$target['isProblem'] ? NoticeCode::MigrationVariantB : NoticeCode::MigrationVariantA,
			$userId,
			$surface,
			$target['id'],
		);
	}

	/**
	 * The message a reaction of the client belongs to, read back out of the token the decision was
	 * issued with. Without it a client could silence a message about a mailbox of another person.
	 *
	 * @return Result Data of a success: `code` as {@see NoticeCode}, `mailboxId` as int|null and
	 *     `surface` as {@see NoticeSurface}.
	 */
	public function verifyReactionToken(string $token, int $userId): Result
	{
		if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH)
		{
			return $this->invalidTokenResult();
		}

		try
		{
			$payload = $this->signer->unsign($token, self::TOKEN_SALT);
		}
		catch (\Throwable)
		{
			return $this->invalidTokenResult();
		}

		$parts = explode(':', $payload);
		if (count($parts) !== 6)
		{
			return $this->invalidTokenResult();
		}

		[$version, $tokenUserId, $code, $mailboxId, $surface, $expiresAt] = $parts;
		$noticeCode = NoticeCode::tryFrom($code);
		$noticeSurface = NoticeSurface::tryFrom($surface);
		if (
			(int)$version !== self::TOKEN_VERSION
			|| (int)$tokenUserId !== $userId
			|| $userId <= 0
			|| $noticeCode === null
			|| $noticeCode === NoticeCode::None
			|| $noticeSurface === null
		)
		{
			return $this->invalidTokenResult();
		}

		if ((int)$expiresAt <= time())
		{
			return (new Result())->addError(
				new Error('Reaction token has expired.', self::ERROR_TOKEN_EXPIRED),
			);
		}

		return (new Result())->setData([
			'code' => $noticeCode,
			'mailboxId' => (int)$mailboxId > 0 ? (int)$mailboxId : null,
			'surface' => $noticeSurface,
		]);
	}

	/** The switch of the feature, owned by the configuration helper of the module. */
	protected function isAvailable(): bool
	{
		return Feature::isMigrationNoticeAvailable();
	}

	/**
	 * The switch of the announcement alone. While it is off the announcement is not issued and stays
	 * owed - nobody spends it - and the migration messages go on as if it had never been due: the
	 * warning about the providers does not wait for an invitation that is not being sent.
	 */
	protected function isAnnouncementAvailable(): bool
	{
		return Feature::isMigrationAnnouncementAvailable();
	}

	/**
	 * The address of the mail control panel, the single place it is set for every client. It arrives
	 * checked: the configuration helper answers an address that may not be followed with the one the
	 * feature ships with, so every reader of that option - this service, the toolbar button of the
	 * mailbox list and the hint beside it - is given the same safe value.
	 */
	protected function getControlPanelUrl(): string
	{
		return Feature::getMigrationControlPanelUrl();
	}

	/**
	 * The address of the helpdesk article behind the "Details" link. It arrives assembled: the
	 * configuration helper puts the article the feature names on the helpdesk of the language the
	 * person reads the interface in, so the article does not come out Russian for a German reader.
	 */
	protected function getHelpdeskUrl(): string
	{
		return Feature::getMigrationHelpdeskUrl();
	}

	/** The page an external mailbox is connected on, the same one the rest of the module leads to. */
	protected function getMailboxConnectUrl(): string
	{
		return self::MAILBOX_CONNECT_URL;
	}

	/**
	 * Mailboxes the person owns. A mailbox shared with them never takes part: a migration message is
	 * addressed to the owner, and the owner of a mailbox is one.
	 *
	 * @return array<int, array{id: int, serviceName: string, email: string}>
	 */
	protected function getOwnedMailboxes(int $userId): array
	{
		$rows = MailboxTable::getTheOwnersMailboxes($userId);
		if ($rows === [])
		{
			return [];
		}

		$serviceNames = $this->getServiceNames($rows);

		$mailboxes = [];
		foreach ($rows as $row)
		{
			$mailboxId = (int)($row['ID'] ?? 0);
			if ($mailboxId <= 0)
			{
				continue;
			}

			$mailboxes[] = [
				'id' => $mailboxId,
				'serviceName' => $serviceNames[(int)($row['SERVICE_ID'] ?? 0)] ?? '',
				'email' => (string)($row['EMAIL'] ?? ''),
			];
		}

		return $mailboxes;
	}

	/**
	 * Mailboxes of the person the module already counts as problem ones. No attempt counter of our
	 * own is kept: the module answers this question and answers it for the whole set at once.
	 *
	 * @return array<int, true> Identifiers as keys.
	 */
	protected function getProblemMailboxIds(int $userId): array
	{
		return array_fill_keys(
			(new MailboxSyncManager($userId))->getCachedMailboxesIdsWithConnectionError(),
			true,
		);
	}

	/**
	 * Mailboxes already switched to the new source. The switch is the criterion, not the finished
	 * import of the history: the letters arrive again as soon as the route of the delivery moves.
	 *
	 * @param int[] $mailboxIds
	 * @return array<int, true> Identifiers as keys.
	 */
	protected function getTransferredMailboxIds(array $mailboxIds): array
	{
		/*
			The rendering path of the mail section must not fall with the decision: an incomplete schema
			of the migration operations announces itself by an exception. Nothing known about the
			transfer is a valid input of ALG-01 - no mailbox counts as switched.
		*/
		try
		{
			$statuses = $this->migrationStatuses->getLatestForMailboxes($mailboxIds);
		}
		catch (\Throwable)
		{
			return [];
		}

		$transferred = [];
		foreach ($statuses as $mailboxId => $status)
		{
			if ($status->publicStatus === self::TRANSFERRED_STATUS)
			{
				$transferred[(int)$mailboxId] = true;
			}
		}

		return $transferred;
	}

	/**
	 * The mailboxes the decision may be about, chosen in one pass over the same features.
	 *
	 * `candidates` are the mailboxes a migration message may be due about, in the order they are
	 * asked: the broken ones first, because the state "fell off" displaces the state "still works"
	 * (AC-022). It is an order and not a single answer, because every mailbox carries a state of the
	 * display of its own and the message due about it dies with that state alone (ERR-002). Which of
	 * them has anything left to say is read afterwards, one by one, and the first that has takes the
	 * message - two migration messages are never shown at once.
	 *
	 * `brokenMailboxId` is the mailbox of the offer that goes next to the red alert - there the
	 * provider is not looked at all (AC-029), only the fact that the mail stopped arriving.
	 *
	 * Two conditions take a mailbox out of both answers. A mailbox already switched to the new source
	 * is gone from them, because its mail arrives again and there is nothing left to move (ERR-005):
	 * the criterion is the switch itself, not the finished import of the history. A mailbox that is no
	 * longer a problem one is gone from `brokenMailboxId` the moment the synchronization recovers, so
	 * the offer leaves together with the alert it was added to (AC-032).
	 *
	 * The two features are read in the order of what they cost. The provider of a mailbox and the fact
	 * that its mail stopped arriving are answered without a query of their own - one from the address,
	 * the other from the state the module keeps warm for the whole set - and a mailbox that neither
	 * names is in no answer of this method whatever its transfer says. The transfer is the query, so it
	 * is asked last and only about the mailboxes still standing: a working mailbox of an ordinary
	 * provider is due neither a message nor an offer, and nothing about it is worth a row.
	 *
	 * @return array{candidates: array<int, array{id: int, isProblem: bool}>, brokenMailboxId: int|null}
	 */
	private function selectMailboxes(int $userId, ?int $mailboxId): array
	{
		$nothing = ['candidates' => [], 'brokenMailboxId' => null];

		$mailboxes = $this->getOwnedMailboxes($userId);
		if ($mailboxId !== null)
		{
			$mailboxes = array_values(array_filter(
				$mailboxes,
				static fn (array $mailbox): bool => $mailbox['id'] === $mailboxId,
			));
		}

		if ($mailboxes === [])
		{
			return $nothing;
		}

		$considered = $this->considerByProviderAndProblem($mailboxes, $this->getProblemMailboxIds($userId));
		if ($considered === [])
		{
			return $nothing;
		}

		$transferred = $this->getTransferredMailboxIds(array_column($considered, 'id'));

		$broken = [];
		$working = [];
		$firstProblemMailboxId = null;
		foreach ($considered as $mailbox)
		{
			if (isset($transferred[$mailbox['id']]))
			{
				continue;
			}

			if ($firstProblemMailboxId === null && $mailbox['isProblem'])
			{
				$firstProblemMailboxId = $mailbox['id'];
			}

			if (!$mailbox['isRestricted'])
			{
				continue;
			}

			if ($mailbox['isProblem'])
			{
				$broken[] = ['id' => $mailbox['id'], 'isProblem' => true];
			}
			else
			{
				$working[] = ['id' => $mailbox['id'], 'isProblem' => false];
			}
		}

		return [
			'candidates' => array_merge($broken, $working),
			'brokenMailboxId' => $broken === [] ? $firstProblemMailboxId : $broken[0]['id'],
		];
	}

	/**
	 * The mailboxes either answer of {@see self::selectMailboxes()} could still be about, in the order
	 * they were given: a company domain at one of the two providers may be due a migration message, a
	 * mailbox whose mail stopped arriving may be due the offer, and a mailbox that is neither is due
	 * nothing at all.
	 *
	 * @param array<int, array{id: int, serviceName: string, email: string}> $mailboxes
	 * @param array<int, true> $problem
	 * @return array<int, array{id: int, isProblem: bool, isRestricted: bool}>
	 */
	private function considerByProviderAndProblem(array $mailboxes, array $problem): array
	{
		$considered = [];
		foreach ($mailboxes as $mailbox)
		{
			$isProblem = isset($problem[$mailbox['id']]);
			$isRestricted = ProviderAccessRestriction::isMigrationRestricted(
				$mailbox['serviceName'],
				$mailbox['email'],
			);

			if ($isProblem || $isRestricted)
			{
				$considered[] = [
					'id' => $mailbox['id'],
					'isProblem' => $isProblem,
					'isRestricted' => $isRestricted,
				];
			}
		}

		return $considered;
	}

	/**
	 * What is left for a mailbox no migration message is due about: the offer to connect Bitrix24 Mail
	 * next to the red alert, and only while the mail of that mailbox does not arrive.
	 *
	 * The migration message wins whenever both could be shown: it carries a way out, while this one
	 * only offers a new address (ERR-008). The offer keeps no state of its own - it lasts exactly as
	 * long as the mailbox is a problem one (NFR-001).
	 *
	 * Not every surface takes it ({@see NoticeSurface::carriesBrokenMailboxOffer()}), and a surface
	 * that does not is answered with nothing rather than with a message carrying no buttons: this
	 * offer is buttons and nothing else - the alert beside it is drawn by somebody else, with words of
	 * its own - so a decision with none of them would be an order to show what does not exist.
	 */
	private function offerOrNothing(?int $brokenMailboxId, int $userId, NoticeSurface $surface): NoticeDecision
	{
		return $brokenMailboxId === null || !$surface->carriesBrokenMailboxOffer()
			? NoticeDecision::none()
			: $this->build(NoticeCode::BrokenMailboxOffer, $userId, $surface, $brokenMailboxId)
		;
	}

	/**
	 * @param array<int|string, array<string, mixed>> $rows
	 * @return array<int, string> Service names by service identifier.
	 */
	private function getServiceNames(array $rows): array
	{
		$serviceIds = [];
		foreach ($rows as $row)
		{
			$serviceId = (int)($row['SERVICE_ID'] ?? 0);
			if ($serviceId > 0)
			{
				$serviceIds[$serviceId] = $serviceId;
			}
		}

		if ($serviceIds === [])
		{
			return [];
		}

		$services = MailServicesTable::getList([
			'select' => ['ID', 'NAME'],
			'filter' => ['@ID' => array_values($serviceIds)],
		])->fetchAll();

		$names = [];
		foreach ($services as $service)
		{
			$names[(int)$service['ID']] = (string)$service['NAME'];
		}

		return $names;
	}

	private function build(
		NoticeCode $code,
		int $userId,
		NoticeSurface $surface,
		?int $mailboxId,
	): NoticeDecision
	{
		return new NoticeDecision(
			code: $code,
			mailboxId: $mailboxId,
			title: $this->getTitle($code),
			text: $this->getText($code),
			buttons: $this->getButtons($code),
			dismissHint: $this->getDismissHint($code),
			reactionToken: $this->issueReactionToken($userId, $code, $surface, $mailboxId),
		);
	}

	private function getTitle(NoticeCode $code): string
	{
		return match ($code)
		{
			NoticeCode::Announcement => (string)Loc::getMessage('MAIL_MIGRATION_NOTICE_ANN_1_TITLE'),
			NoticeCode::MigrationVariantA => (string)Loc::getMessage('MAIL_MIGRATION_NOTICE_MIG_A_TITLE'),
			NoticeCode::MigrationVariantB => (string)Loc::getMessage('MAIL_MIGRATION_NOTICE_MIG_B_TITLE'),
			NoticeCode::Banner => (string)Loc::getMessage('MAIL_MIGRATION_NOTICE_BAN_1_TITLE'),
			NoticeCode::BrokenMailboxOffer => (string)Loc::getMessage('MAIL_MIGRATION_NOTICE_ERR_1_TITLE'),
			NoticeCode::None => '',
		};
	}

	private function getText(NoticeCode $code): string
	{
		return match ($code)
		{
			NoticeCode::Announcement => (string)Loc::getMessage('MAIL_MIGRATION_NOTICE_ANN_1_TEXT'),
			NoticeCode::MigrationVariantA => (string)Loc::getMessage('MAIL_MIGRATION_NOTICE_MIG_A_TEXT'),
			NoticeCode::MigrationVariantB => (string)Loc::getMessage('MAIL_MIGRATION_NOTICE_MIG_B_TEXT'),
			NoticeCode::Banner => (string)Loc::getMessage('MAIL_MIGRATION_NOTICE_BAN_1_TEXT'),
			NoticeCode::BrokenMailboxOffer => (string)Loc::getMessage('MAIL_MIGRATION_NOTICE_ERR_1_TEXT'),
			NoticeCode::None => '',
		};
	}

	/** @return array<int, array{id: string, label: string, url: string}> */
	private function getButtons(NoticeCode $code): array
	{
		$buttons = [];
		foreach ($this->getButtonIds($code) as $buttonId)
		{
			$buttons[] = [
				'id' => $buttonId,
				'label' => (string)Loc::getMessage(self::BUTTON_PHRASES[$buttonId]),
				'url' => $this->getButtonUrl($buttonId),
			];
		}

		return $buttons;
	}

	/**
	 * DTO-02. "Remind later" belongs to the variant A alone: a mailbox that no longer works leaves
	 * nothing to postpone.
	 *
	 * ANN-1. The announcement offers the mail of the company first, on every surface. It is settled in
	 * the control panel and nowhere else, and the application has no screen of its own for it - but it
	 * has a way there, the same handover to a computer its other messages take, so the button is one
	 * the application can see through as well.
	 *
	 * @return string[] Ordered as the clients render them.
	 */
	private function getButtonIds(NoticeCode $code): array
	{
		return match ($code)
		{
			NoticeCode::Announcement => [
				NoticeDecision::CREATE_COMPANY_MAIL,
				NoticeDecision::CONNECT_EXTERNAL,
			],
			NoticeCode::MigrationVariantA => [
				NoticeDecision::TRANSFER_MAIL,
				NoticeDecision::REMIND_LATER,
				NoticeDecision::DETAILS,
			],
			NoticeCode::MigrationVariantB => [
				NoticeDecision::TRANSFER_MAIL,
				NoticeDecision::DETAILS,
			],
			NoticeCode::Banner => [NoticeDecision::TRANSFER_MAIL],
			NoticeCode::BrokenMailboxOffer => [NoticeDecision::CONNECT_B24_MAIL],
			NoticeCode::None => [],
		};
	}

	/**
	 * Every button of every message leads somewhere, and the three ways out the feature offers are the
	 * ones the product already has. The mail of the company - its domain, its addresses and a mailbox
	 * on Bitrix24 Mail - is settled in the control panel and nowhere else: the service of our own
	 * hosting is deliberately absent from the showcase of the providers, because its credentials come
	 * with the handover ({@see \Bitrix\Mail\Internals\MailServiceInstaller}). An external mailbox, on
	 * the other hand, is connected on the ordinary page of the Mail section.
	 */
	private function getButtonUrl(string $buttonId): string
	{
		return match ($buttonId)
		{
			NoticeDecision::TRANSFER_MAIL,
			NoticeDecision::CREATE_COMPANY_MAIL,
			NoticeDecision::CONNECT_B24_MAIL => $this->getControlPanelUrl(),
			NoticeDecision::DETAILS => $this->getHelpdeskUrl(),
			NoticeDecision::CONNECT_EXTERNAL => $this->getMailboxConnectUrl(),
			default => '',
		};
	}

	private function getDismissHint(NoticeCode $code): string
	{
		return match ($code)
		{
			NoticeCode::MigrationVariantA, NoticeCode::MigrationVariantB
				=> (string)Loc::getMessage('MAIL_MIGRATION_NOTICE_DISMISS_HINT'),
			default => '',
		};
	}

	/**
	 * The surface travels in the token so that the decision that follows a reaction is the decision of
	 * the place the person is looking at: the entry point of the reaction is told nothing but the token
	 * and knows no rule to guess it by.
	 */
	private function issueReactionToken(
		int $userId,
		NoticeCode $code,
		NoticeSurface $surface,
		?int $mailboxId,
	): string
	{
		$payload = implode(':', [
			self::TOKEN_VERSION,
			$userId,
			$code->value,
			(int)$mailboxId,
			$surface->value,
			time() + self::TOKEN_TTL,
		]);

		return $this->signer->sign($payload, self::TOKEN_SALT);
	}

	private function invalidTokenResult(): Result
	{
		return (new Result())->addError(
			new Error('Reaction token is invalid.', self::ERROR_INVALID_TOKEN),
		);
	}

	private function wasFullscreenIssued(int $userId): bool
	{
		try
		{
			return $this->getStorage()->has(self::FULLSCREEN_KEY_PREFIX . $userId);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	/**
	 * A storage that is not there costs the sitting its mark, and the migration popup may follow the
	 * announcement. That is the cheaper mistake: a page of the portal must render either way.
	 */
	private function markFullscreenIssued(int $userId): void
	{
		try
		{
			$this->getStorage()->set(self::FULLSCREEN_KEY_PREFIX . $userId, true, self::FULLSCREEN_TTL);
		}
		catch (\Throwable)
		{
		}
	}

	/**
	 * The temporary store of the portal. A caller with a store of its own hands it to the constructor;
	 * everyone else gets the binding of the kernel, asked for here and not while the service is built.
	 * Both readers of the store already swallow their own failure, so a broken binding costs a sitting
	 * its mark; resolved in the constructor it would instead take down everything that builds the rules.
	 */
	private function getStorage(): StorageInterface
	{
		return $this->storage ??= ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
	}
}
