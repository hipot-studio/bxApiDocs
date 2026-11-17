<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller;

use Bitrix\Mail\Access\MailActionDictionary;
use Bitrix\Mail\Access\MailboxAccessController;
use Bitrix\Mail\Access\Model\MailboxModel;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Integration\MailService\MigrationEntryService;
use Bitrix\Mail\Internal\Service\MigrationNotice\NoticeCode;
use Bitrix\Mail\Internal\Service\MigrationNotice\NoticeDecisionService;
use Bitrix\Mail\Internal\Service\MigrationNotice\NoticeSurface;
use Bitrix\Main;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Intranet;

Loc::loadMessages(__FILE__);

/**
 * Messages about the migration of mail to Bitrix24 Mail. Scope: api.
 *
 *   get   POST {surface, mailboxId?}        -> {decision}  (API-01)
 *   react POST {reactionToken, shown}       -> {decision}  (API-02)
 *
 * The controller carries no rule of the display and knows none: which message is due, and what a
 * reaction spends, belong to {@see NoticeDecisionService} alone. What lives here is the boundary -
 * the rights to the mailbox and the shape of the input.
 *
 * A reaction is counted only for the message the token was issued with, so a client cannot silence a
 * message about a mailbox of somebody else by naming its identifier.
 *
 * The rules service reaches the actions as an argument of theirs: the kernel builds a controller with
 * a request and with nothing else, so a dependency taken any other way would be one this class goes
 * and fetches itself. Which implementation arrives is the registration of the module
 * ({@see \Bitrix\Mail\Internal\Service\MigrationNotice\NoticeDecisionService} in .settings.php),
 * and a caller of an action - a case, a scenario of a stand - hands its own over the same way.
 */
final class MigrationNotice extends Controller
{
	public const ERROR_ACCESS_DENIED = 'access_denied';
	public const ERROR_UNKNOWN_SURFACE = 'unknown_surface';
	public const ERROR_UNKNOWN_REACTION = 'unknown_reaction';
	public const ERROR_INVALID_TOKEN = NoticeDecisionService::ERROR_INVALID_TOKEN;
	public const ERROR_TOKEN_EXPIRED = NoticeDecisionService::ERROR_TOKEN_EXPIRED;
	public const ERROR_INVALID_MIGRATION_TOKEN = 'invalid_migration_token';
	public const ERROR_MIGRATION_TOKEN_EXPIRED = 'migration_token_expired';

	public const REACTION_SHOWN = NoticeDecisionService::REACTION_SHOWN;

	private const ERROR_MESSAGE_KEYS = [
		self::ERROR_ACCESS_DENIED => 'MAIL_MIGRATION_NOTICE_ERROR_ACCESS_DENIED',
		self::ERROR_UNKNOWN_SURFACE => 'MAIL_MIGRATION_NOTICE_ERROR_UNKNOWN_SURFACE',
		self::ERROR_UNKNOWN_REACTION => 'MAIL_MIGRATION_NOTICE_ERROR_UNKNOWN_REACTION',
		self::ERROR_INVALID_TOKEN => 'MAIL_MIGRATION_NOTICE_ERROR_INVALID_TOKEN',
		self::ERROR_TOKEN_EXPIRED => 'MAIL_MIGRATION_NOTICE_ERROR_TOKEN_EXPIRED',
		self::ERROR_INVALID_MIGRATION_TOKEN => 'MAIL_MIGRATION_NOTICE_ERROR_INVALID_TOKEN',
		self::ERROR_MIGRATION_TOKEN_EXPIRED => 'MAIL_MIGRATION_NOTICE_ERROR_TOKEN_EXPIRED',
	];

	protected function getDefaultPreFilters(): array
	{
		return [
			new Main\Engine\ActionFilter\Authentication(),
			new Main\Engine\ActionFilter\HttpMethod([Main\Engine\ActionFilter\HttpMethod::METHOD_POST]),
			new Main\Engine\ActionFilter\Csrf(),
			// The states of the messages live in the database, so the action holds no lock of the session
			new Main\Engine\ActionFilter\CloseSession(),
			new Intranet\ActionFilter\IntranetUser(),
		];
	}

	/**
	 * API-01. POST {surface, mailboxId?} -> {decision}
	 *
	 * A surface that knows no mailbox asks without one; the switch of the feature and the zone answer
	 * with the decision "nothing to show" and not with a refusal.
	 */
	public function getAction(NoticeDecisionService $notices, string $surface, int $mailboxId = 0): ?array
	{
		$noticeSurface = NoticeSurface::tryFrom($surface);
		if ($noticeSurface === null)
		{
			$this->addFailure(422, self::ERROR_UNKNOWN_SURFACE);

			return null;
		}

		if ($mailboxId > 0 && !$this->canAccessMailbox($mailboxId))
		{
			$this->addFailure(403, self::ERROR_ACCESS_DENIED);

			return null;
		}

		$decision = $notices->decide(
			$this->getUserId(),
			$noticeSurface,
			$mailboxId > 0 ? $mailboxId : null,
		);

		return ['decision' => $decision->toArray()];
	}

	/**
	 * API-02. POST {reactionToken, shown} -> {decision}
	 * The general announcement is the only message that reports being shown.
	 */
	public function reactAction(NoticeDecisionService $notices, string $reactionToken, string $reaction): ?array
	{
		if ($reaction !== self::REACTION_SHOWN)
		{
			$this->addFailure(422, self::ERROR_UNKNOWN_REACTION);

			return null;
		}

		$userId = $this->getUserId();
		$verified = $notices->verifyReactionToken($reactionToken, $userId);
		if (!$verified->isSuccess())
		{
			$this->addFailure(422, (string)$verified->getErrors()[0]->getCode());

			return null;
		}

		/** @var NoticeCode $code */
		$code = $verified->getData()['code'];
		/** @var NoticeSurface $surface */
		$surface = $verified->getData()['surface'];
		$mailboxId = $verified->getData()['mailboxId'];

		if ($mailboxId !== null && !$this->canAccessMailbox($mailboxId))
		{
			$this->addFailure(403, self::ERROR_ACCESS_DENIED);

			return null;
		}

		$notices->recordReaction($userId, $code, $mailboxId, $reaction);

		return ['decision' => $notices->decide($userId, $surface, $mailboxId)->toArray()];
	}

	/**
	 * POST {migrationToken} -> {domain, consentGranted, url, error?}
	 */
	public function transferAction(
		NoticeDecisionService $notices,
		string $migrationToken,
		?MigrationEntryService $migrationEntry = null,
	): ?array
	{
		$userId = $this->getUserId();
		$verified = $notices->verifyMigrationToken($migrationToken, $userId);
		if (!$verified->isSuccess())
		{
			$this->addFailure(422, (string)$verified->getErrors()[0]->getCode());

			return null;
		}

		$data = $verified->getData();
		$migrationEntry ??= new MigrationEntryService();
		$result = $migrationEntry->enter(
			(string)$data['domain'],
			$userId,
			(string)$data['textVersion'],
			$this->displayNameOf($userId),
		);

		$answer = $result->getData();
		if (($answer['consentGranted'] ?? false) === true)
		{
			// One press of the panel stands for every domain of the portal, not only the one it was shown for
			$migrationEntry->grantConsents(
				array_values(array_diff($notices->getDomainsAwaitingConsent(), [(string)$data['domain']])),
				$userId,
				(string)$data['textVersion'],
			);
		}

		return $answer;
	}

	/**
	 * The same boundary the neighbouring controller of the migration statuses keeps: a mailbox a person
	 * may not even see is not a mailbox they may react about.
	 */
	private function canAccessMailbox(int $mailboxId): bool
	{
		if ($mailboxId <= 0)
		{
			return false;
		}

		return MailboxAccessController::getInstance($this->getUserId())->check(
			MailActionDictionary::ACTION_MAILBOX_LIST_ITEM_VIEW,
			MailboxModel::createFromId($mailboxId),
		) || MailboxAccess::hasCurrentUserAnyAccessToMailbox($mailboxId);
	}

	private function getUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	private function displayNameOf(int $userId): string
	{
		$user = Main\UserTable::getRow([
			'select' => ['NAME', 'LAST_NAME', 'LOGIN'],
			'filter' => ['=ID' => $userId],
		]);

		if ($user === null)
		{
			return '';
		}

		$name = trim(implode(' ', array_filter([
			(string)($user['NAME'] ?? ''),
			(string)($user['LAST_NAME'] ?? ''),
		])));

		return $name === '' ? trim((string)($user['LOGIN'] ?? '')) : $name;
	}

	private function addFailure(int $status, string $code): void
	{
		Context::getCurrent()->getResponse()->setStatus($status);

		$this->addError(new Error(
			(string)Loc::getMessage(self::ERROR_MESSAGE_KEYS[$code] ?? ''),
			$code,
		));
	}
}

