<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MigrationNotice;

use Bitrix\Mail\Access\Model\UserModel;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\Mailbox\MailboxSyncManager;
use Bitrix\Mail\Integration\MailService\MigrationStatusProvider;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Sign\Signer;

Loc::loadMessages(__FILE__);

/** Selects the settings consent panel or the general announcement. */
class NoticeDecisionService
{
	public const ERROR_INVALID_TOKEN = 'invalid_reaction_token';
	public const ERROR_TOKEN_EXPIRED = 'reaction_token_expired';

	/** API-02. What a person can answer a message with, as it travels between the clients and here. */
	public const REACTION_SHOWN = 'shown';

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
	private const MAILSERVICE_MODULE_ID = 'mailservice';

	/** The connection form of the Mail section, as the rest of the module addresses it. */
	private const MAILBOX_CONNECT_URL = '/mail/config/';

	private const BUTTON_PHRASES = [
		NoticeDecision::TRANSFER_MAIL => 'MAIL_MIGRATION_NOTICE_BTN_TRANSFER',
		NoticeDecision::DETAILS => 'MAIL_MIGRATION_NOTICE_BTN_DETAILS',
		NoticeDecision::CREATE_COMPANY_MAIL => 'MAIL_MIGRATION_NOTICE_BTN_CREATE',
		NoticeDecision::CONNECT_EXTERNAL => 'MAIL_MIGRATION_NOTICE_BTN_CONNECT_EXTERNAL',
	];

	private readonly MigrationEntryToken $migrationTokens;
	private readonly NoticeMailboxCandidates $mailboxCandidates;

	public function __construct(
		private readonly NoticeStateStorage $states = new NoticeStateStorage(),
		private readonly MigrationStatusProvider $migrationStatuses = new MigrationStatusProvider(),
		private readonly Signer $signer = new Signer(),
		private ?StorageInterface $storage = null,
		?MigrationEntryToken $migrationTokens = null,
		?NoticeMailboxCandidates $mailboxCandidates = null,
	)
	{
		$this->migrationTokens = $migrationTokens ?? new MigrationEntryToken();
		$this->mailboxCandidates = $mailboxCandidates ?? new NoticeMailboxCandidates();
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

		if ($surface === NoticeSurface::MailboxSettings)
		{
			return $this->isAdmin($userId)
				? $this->decideForMailboxSettings($userId, $surface, $mailboxId)
				: NoticeDecision::none()
			;
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
				fail for both. The client reports the showing, and until it does the announcement
				stays owed (AC-002, ERR-001).

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

		return NoticeDecision::none();
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

		if ($surface === NoticeSurface::MailboxSettings)
		{
			return $this->isAdmin($userId);
		}

		if ($this->isAnnouncementAvailable() && !$this->states->wasAnnouncementShown($userId))
		{
			return $surface->carriesAnnouncement();
		}

		return false;
	}

	/** Records the shown reaction of the general announcement. */
	public function recordReaction(int $userId, NoticeCode $code, ?int $mailboxId, string $reaction): void
	{
		if ($code === NoticeCode::Announcement)
		{
			if ($reaction === self::REACTION_SHOWN)
			{
				$this->states->markAnnouncementShown($userId);
			}
		}
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
			|| $noticeCode !== NoticeCode::Announcement
			|| $noticeSurface === null
			|| !$noticeSurface->carriesAnnouncement()
			|| (int)$mailboxId !== 0
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

	/**
	 * @return Result Data: actor, mailboxId, domain, surface, code, textVersion.
	 */
	public function verifyMigrationToken(string $token, int $userId): Result
	{
		if (!$this->isAvailable())
		{
			return (new Result())->addError(
				new Error('Migration entry is unavailable.', MigrationEntryToken::ERROR_INVALID),
			);
		}

		if (!$this->isAdmin($userId))
		{
			return (new Result())->addError(
				new Error('Migration entry is forbidden.', MigrationEntryToken::ERROR_INVALID),
			);
		}

		$verified = $this->migrationTokens->verify($token, $userId);
		if (!$verified->isSuccess())
		{
			return $verified;
		}

		$data = $verified->getData();
		$domain = $this->resolveMailboxDomain((int)$data['mailboxId']);
		if (!$domain->isSuccess())
		{
			return (new Result())->addError(
				new Error('Mailbox domain is unavailable.', MigrationEntryToken::ERROR_INVALID),
			);
		}

		$domainData = $domain->getData();
		if (
			($domainData['eligible'] ?? false) !== true
			|| (int)($domainData['mailboxId'] ?? 0) !== (int)$data['mailboxId']
			|| (string)($domainData['domain'] ?? '') !== (string)$data['domain']
		)
		{
			return (new Result())->addError(
				new Error('Mailbox domain has changed.', MigrationEntryToken::ERROR_INVALID),
			);
		}

		foreach ($this->getMailboxSettingsCandidatePages([(int)$data['mailboxId'] => true]) as $mailboxes)
		{
			$selection = $this->selectFromMailboxes($mailboxes, $this->getProblemMailboxIds($userId));
			if (in_array((int)$data['mailboxId'], array_column($selection['candidates'], 'id'), true))
			{
				return $verified;
			}
		}

		return (new Result())->addError(
			new Error('Mailbox is no longer eligible.', MigrationEntryToken::ERROR_INVALID),
		);
	}

	/**
	 * The domains the consent panel stands for: every domain of a mailbox of the portal it is shown for -
	 * a domain of a public mail service and a mailbox already switched are not - that has no consent yet. One press of the
	 * panel gives the consent for all of them, so that it is not pressed again for every domain.
	 *
	 * @return list<string> Empty when the domains or the consents cannot be read.
	 */
	public function getDomainsAwaitingConsent(): array
	{
		$domains = [];
		foreach ($this->getMailboxSettingsCandidatePages() as $mailboxes)
		{
			$candidates = $this->selectFromMailboxes($mailboxes, [])['candidates'];
			if ($candidates === [])
			{
				continue;
			}

			$resolved = $this->resolveMailboxDomains(array_column($candidates, 'id'));
			if (!$resolved->isSuccess())
			{
				return [];
			}

			foreach (($resolved->getData()['mailboxes'] ?? []) as $data)
			{
				$domain = is_array($data) ? (string)($data['domain'] ?? '') : '';
				if (
					($data['eligible'] ?? false) === true
					&& $domain !== ''
					&& !ConsentExcludedDomains::isExcludedDomain($domain)
				)
				{
					$domains[$domain] = $domain;
				}
			}
		}

		if ($domains === [])
		{
			return [];
		}

		$read = $this->getGrantedMigrationDomains(array_values($domains));
		$granted = $read->isSuccess() ? ($read->getData()['domains'] ?? null) : null;
		if (!is_array($granted))
		{
			return [];
		}

		return array_values(array_diff_key($domains, $granted));
	}

	/** The switch of the feature, owned by the configuration helper of the module. */
	protected function isAvailable(): bool
	{
		return Feature::isMigrationNoticeAvailable();
	}

	protected function isAdmin(int $userId): bool
	{
		return $userId > 0 && UserModel::createFromId($userId)->isAdmin();
	}

	protected function resolveMailboxDomain(int $mailboxId): Result
	{
		if (
			!Loader::includeModule(self::MAILSERVICE_MODULE_ID)
			|| !class_exists(\Bitrix\MailService\Public\Provider\MailboxDomainProvider::class)
		)
		{
			return (new Result())->addError(new Error('Mailbox domain provider is unavailable.'));
		}

		return (new \Bitrix\MailService\Public\Provider\MailboxDomainProvider())->resolve($mailboxId);
	}

	/** @param list<int> $mailboxIds */
	protected function resolveMailboxDomains(array $mailboxIds): Result
	{
		if (
			!Loader::includeModule(self::MAILSERVICE_MODULE_ID)
			|| !class_exists(\Bitrix\MailService\Public\Provider\MailboxDomainProvider::class)
			|| !method_exists(\Bitrix\MailService\Public\Provider\MailboxDomainProvider::class, 'resolveMany')
		)
		{
			return (new Result())->addError(new Error('Mailbox domain provider is unavailable.'));
		}

		return (new \Bitrix\MailService\Public\Provider\MailboxDomainProvider())->resolveMany($mailboxIds);
	}

	/** @param list<string> $canonicalDomains */
	protected function getGrantedMigrationDomains(array $canonicalDomains): Result
	{
		if (
			!Loader::includeModule(self::MAILSERVICE_MODULE_ID)
			|| !class_exists(\Bitrix\MailService\Public\Provider\MigrationConsentStatusProvider::class)
		)
		{
			return (new Result())->addError(new Error('Migration consent status provider is unavailable.'));
		}

		return (new \Bitrix\MailService\Public\Provider\MigrationConsentStatusProvider())
			->grantedDomains($canonicalDomains)
		;
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
	 * @param array<int, array{id: int, serviceName: string, email: string}> $mailboxes
	 * @param array<int, true> $problem
	 * @return array{candidates: array<int, array{id: int, isProblem: bool}>}
	 */
	private function selectFromMailboxes(array $mailboxes, array $problem): array
	{
		$nothing = ['candidates' => []];
		$considered = $this->considerByAddressAndProblem($mailboxes, $problem);
		if ($considered === [])
		{
			return $nothing;
		}

		$transferred = $this->getTransferredMailboxIds(array_column($considered, 'id'));

		$broken = [];
		$working = [];
		foreach ($considered as $mailbox)
		{
			if (isset($transferred[$mailbox['id']]))
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
		];
	}

	private function decideForMailboxSettings(
		int $userId,
		NoticeSurface $surface,
		?int $mailboxId,
	): NoticeDecision
	{
		$problem = $this->getProblemMailboxIds($userId);
		if ($mailboxId !== null)
		{
			foreach ($this->getMailboxSettingsCandidatePages([$mailboxId => true]) as $mailboxes)
			{
				$selection = $this->selectFromMailboxes($mailboxes, $problem);
				$ordered = $this->orderCandidatesAsMailboxes($selection['candidates'], $mailboxes);
				$filtered = $this->withoutGrantedDomains($ordered);
				if ($filtered === null || $filtered === [])
				{
					return NoticeDecision::none();
				}

				return $this->buildConsentPanel($userId, $surface, $filtered[0]['id']);
			}

			return NoticeDecision::none();
		}

		if ($problem !== [])
		{
			foreach ($this->getMailboxSettingsCandidatePages($problem) as $mailboxes)
			{
				$selection = $this->selectFromMailboxes($mailboxes, $problem);
				$ordered = $this->orderCandidatesAsMailboxes($selection['candidates'], $mailboxes);
				$filtered = $this->withoutGrantedDomains($ordered);
				if ($filtered === null)
				{
					return NoticeDecision::none();
				}
				if ($filtered !== [])
				{
					return $this->buildConsentPanel($userId, $surface, $filtered[0]['id']);
				}
			}
		}

		foreach ($this->getMailboxSettingsCandidatePages() as $mailboxes)
		{
			if ($problem !== [])
			{
				$mailboxes = array_values(array_filter(
					$mailboxes,
					static fn (array $candidate): bool => !isset($problem[$candidate['id']]),
				));
			}
			$selection = $this->selectFromMailboxes($mailboxes, $problem);
			$ordered = $this->orderCandidatesAsMailboxes($selection['candidates'], $mailboxes);
			$filtered = $this->withoutGrantedDomains($ordered);
			if ($filtered === null)
			{
				return NoticeDecision::none();
			}

			foreach ($filtered as $candidate)
			{
				if (!$candidate['isProblem'])
				{
					return $this->buildConsentPanel($userId, $surface, $candidate['id']);
				}
			}
		}

		return NoticeDecision::none();
	}

	/**
	 * @param array<int, array{id: int, isProblem: bool}> $candidates
	 * @param array<int, array{id: int, serviceName: string, email: string}> $mailboxes
	 * @return array<int, array{id: int, isProblem: bool}>
	 */
	private function orderCandidatesAsMailboxes(array $candidates, array $mailboxes): array
	{
		$byId = [];
		foreach ($candidates as $candidate)
		{
			$byId[$candidate['id']] = $candidate;
		}

		$ordered = [];
		foreach ($mailboxes as $mailbox)
		{
			if (isset($byId[$mailbox['id']]))
			{
				$ordered[] = $byId[$mailbox['id']];
			}
		}

		return $ordered;
	}

	/**
	 * @param array<int, array{id: int, isProblem: bool}> $candidates
	 * @return array<int, array{id: int, isProblem: bool}>|null Null means the consent source is unavailable.
	 */
	private function withoutGrantedDomains(array $candidates): ?array
	{
		if ($candidates === [])
		{
			return [];
		}

		$domainsByMailbox = [];
		$domains = [];
		$resolved = $this->resolveMailboxDomains(array_column($candidates, 'id'));
		if (!$resolved->isSuccess())
		{
			return null;
		}

		foreach (($resolved->getData()['mailboxes'] ?? []) as $mailboxId => $data)
		{
			if (!is_array($data))
			{
				continue;
			}

			$domain = (string)($data['domain'] ?? '');
			if (
				($data['eligible'] ?? false) !== true
				|| $domain === ''
				|| ConsentExcludedDomains::isExcludedDomain($domain)
			)
			{
				continue;
			}

			$domainsByMailbox[(int)$mailboxId] = $domain;
			$domains[$domain] = $domain;
		}

		if ($domains === [])
		{
			return [];
		}

		$read = $this->getGrantedMigrationDomains(array_values($domains));
		if (!$read->isSuccess())
		{
			return null;
		}

		$granted = $read->getData()['domains'] ?? [];
		if (!is_array($granted))
		{
			return null;
		}

		return array_values(array_filter(
			$candidates,
			static fn (array $candidate): bool => isset($domainsByMailbox[$candidate['id']])
				&& !isset($granted[$domainsByMailbox[$candidate['id']]]),
		));
	}

	private function buildConsentPanel(int $userId, NoticeSurface $surface, int $mailboxId): NoticeDecision
	{
		return $this->build(NoticeCode::MigrationVariantA, $userId, $surface, $mailboxId);
	}

	/**
	 * @param array<int, true>|null $onlyIds
	 * @return iterable<int, array<int, array{id: int, serviceName: string, email: string}>>
	 */
	protected function getMailboxSettingsCandidatePages(?array $onlyIds = null): iterable
	{
		return $this->mailboxCandidates->iterateForMailboxSettings($onlyIds);
	}

	/**
	 * The provider of a mailbox is not asked: the panel is due to every mailbox the New mail can move.
	 * A mailbox on a domain of a public mail service is the one it cannot move, so that mailbox stays out.
	 *
	 * @param array<int, array{id: int, serviceName: string, email: string}> $mailboxes
	 * @param array<int, true> $problem
	 * @return array<int, array{id: int, isProblem: bool}>
	 */
	private function considerByAddressAndProblem(array $mailboxes, array $problem): array
	{
		$considered = [];
		foreach ($mailboxes as $mailbox)
		{
			if (ConsentExcludedDomains::isExcludedAddress($mailbox['email']))
			{
				continue;
			}

			$considered[] = [
				'id' => $mailbox['id'],
				'isProblem' => isset($problem[$mailbox['id']]),
			];
		}

		return $considered;
	}

	private function build(
		NoticeCode $code,
		int $userId,
		NoticeSurface $surface,
		?int $mailboxId,
	): NoticeDecision
	{
		$migrationToken = '';
		$buttons = $this->getButtons($code);
		if (
			$mailboxId !== null
			&& $code === NoticeCode::MigrationVariantA
		)
		{
			$domain = $this->resolveMailboxDomain($mailboxId);
			$domainData = $domain->isSuccess() ? $domain->getData() : [];
			if (($domainData['eligible'] ?? false) === true && (string)($domainData['domain'] ?? '') !== '')
			{
				$migrationToken = $this->migrationTokens->issue(
					$userId,
					$mailboxId,
					(string)$domainData['domain'],
					$surface,
					$code,
				);
			}
			else
			{
				$buttons = array_values(array_filter(
					$buttons,
					static fn (array $button): bool => $button['id'] !== NoticeDecision::TRANSFER_MAIL,
				));
			}
		}

		return new NoticeDecision(
			code: $code,
			mailboxId: $mailboxId,
			title: $this->getTitle($code),
			text: $this->getText($code),
			buttons: $buttons,
			dismissHint: '',
			reactionToken: $code === NoticeCode::Announcement
				? $this->issueReactionToken($userId, $code, $surface, $mailboxId)
				: '',
			migrationToken: $migrationToken,
		);
	}

	private function getTitle(NoticeCode $code): string
	{
		return match ($code)
		{
			NoticeCode::Announcement => (string)Loc::getMessage('MAIL_MIGRATION_NOTICE_ANN_1_TITLE'),
			NoticeCode::MigrationVariantA => (string)Loc::getMessage('MAIL_MIGRATION_NOTICE_MIG_A_TITLE_MSGVER_1'),
			NoticeCode::None => '',
		};
	}

	private function getText(NoticeCode $code): string
	{
		return match ($code)
		{
			NoticeCode::Announcement => (string)Loc::getMessage('MAIL_MIGRATION_NOTICE_ANN_1_TEXT'),
			NoticeCode::MigrationVariantA => (string)Loc::getMessage('MAIL_MIGRATION_NOTICE_MIG_A_TEXT_MSGVER_1'),
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

	/** @return string[] Ordered as the clients render them. */
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
				NoticeDecision::DETAILS,
			],
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
			NoticeDecision::CREATE_COMPANY_MAIL => $this->getControlPanelUrl(),
			NoticeDecision::DETAILS => $this->getHelpdeskUrl(),
			NoticeDecision::CONNECT_EXTERNAL => $this->getMailboxConnectUrl(),
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

	/** A storage failure may repeat the announcement, but must not prevent the page from rendering. */
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
