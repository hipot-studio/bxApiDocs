<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller;

use Bitrix\Mail\Controller\ActionFilter\VacationAutoReplyAccess;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\Service\VacationAutoReply\VacationAutoReplyService;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

Loc::loadMessages(__FILE__);

/**
 * Vacation auto-reply setting of a mailbox. Scope: api - available as mail.api.vacationautoreply.*
 *
 *   get  GET  {mailboxId}                                             -> {setting, mailboxes: [{id, email}]}
 *   save POST {mailboxId, active, startsAt, endsAt, timezoneId, body} -> {setting}
 *
 * The controller owns access, validation of the input, sanitizing of the body and the shape of the
 * response; the lifecycle of the setting belongs to VacationAutoReplyService.
 */
class VacationAutoReply extends Base
{
	public const ERROR_FEATURE_DISABLED = 'feature_disabled';
	public const ERROR_ACCESS_DENIED = 'access_denied';
	public const ERROR_MAILBOX_NOT_FOUND = 'mailbox_not_found';
	public const ERROR_MAILBOX_UNAVAILABLE = 'mailbox_unavailable';
	public const ERROR_BODY_REQUIRED = 'body_required';
	public const ERROR_BODY_TOO_LONG = 'body_too_long';
	public const ERROR_PERIOD_REQUIRED = 'period_required';
	public const ERROR_PERIOD_ORDER = 'period_order';
	public const ERROR_SAVE_FAILED = 'save_failed';

	private const ERROR_MESSAGE_KEYS = [
		self::ERROR_FEATURE_DISABLED => 'MAIL_VACATION_AUTO_REPLY_ERROR_UNAVAILABLE',
		self::ERROR_ACCESS_DENIED => 'MAIL_VACATION_AUTO_REPLY_ERROR_ACCESS_DENIED',
		self::ERROR_MAILBOX_NOT_FOUND => 'MAIL_VACATION_AUTO_REPLY_ERROR_MAILBOX_NOT_FOUND',
		self::ERROR_MAILBOX_UNAVAILABLE => 'MAIL_VACATION_AUTO_REPLY_ERROR_MAILBOX_UNAVAILABLE',
		self::ERROR_BODY_REQUIRED => 'MAIL_VACATION_AUTO_REPLY_ERROR_BODY_REQUIRED',
		self::ERROR_BODY_TOO_LONG => 'MAIL_VACATION_AUTO_REPLY_ERROR_BODY_TOO_LONG',
		self::ERROR_PERIOD_REQUIRED => 'MAIL_VACATION_AUTO_REPLY_ERROR_PERIOD_REQUIRED',
		self::ERROR_PERIOD_ORDER => 'MAIL_VACATION_AUTO_REPLY_ERROR_PERIOD_ORDER',
		self::ERROR_SAVE_FAILED => 'MAIL_VACATION_AUTO_REPLY_ERROR_SAVE_FAILED',
	];

	/** A mailbox is answered by the auto-reply only while the sync brings its mail in. */
	private const SYNCHRONIZED_SERVER_TYPE = 'imap';

	/**
	 * Characters of the text of the auto-reply, markup included. An away note of a person is a few
	 * sentences, while the column behind it takes megabytes: every save runs two regular expressions and
	 * the sanitizer over the whole text, and every answer carries it out of the portal.
	 */
	private const BODY_MAX_LENGTH = 20000;

	private ?VacationAutoReplyService $service = null;

	/** @var array<int, bool> */
	private array $editableMailboxes = [];

	/**
	 * The gate of the feature is a prefilter of both actions, so it stands behind the authentication and
	 * the CSRF check of the framework instead of ahead of them.
	 */
	public function configureActions(): array
	{
		return [
			'get' => ['+prefilters' => [new VacationAutoReplyAccess()]],
			'save' => ['+prefilters' => [
				new VacationAutoReplyAccess(),
				new HttpMethod([HttpMethod::METHOD_POST]),
			]],
		];
	}

	/**
	 * GET {mailboxId} -> {setting, mailboxes: [{id, email}]}
	 */
	public function getAction(int $mailboxId): ?array
	{
		if ($this->findMailbox($mailboxId) === null)
		{
			$this->addFailure(404, self::ERROR_MAILBOX_NOT_FOUND);

			return null;
		}

		if (!MailboxAccess::hasCurrentUserAnyAccessToMailbox($mailboxId))
		{
			$this->addFailure(403, self::ERROR_ACCESS_DENIED);

			return null;
		}

		return [
			'setting' => $this->buildSetting($mailboxId, $this->getService()->getSetting($mailboxId)),
			'mailboxes' => $this->getAccessibleMailboxes(),
		];
	}

	/**
	 * POST {mailboxId, active, startsAt, endsAt, timezoneId, body} -> {setting}
	 *
	 * Saving changes the setting and nothing else: no letter is sent and no message that has already
	 * been synchronized is looked at again.
	 */
	public function saveAction(
		int $mailboxId,
		bool $active,
		string $startsAt = '',
		string $endsAt = '',
		string $timezoneId = '',
		string $body = '',
	): ?array
	{
		$mailbox = $this->findMailbox($mailboxId);
		if ($mailbox === null)
		{
			$this->addFailure(404, self::ERROR_MAILBOX_NOT_FOUND);

			return null;
		}

		if (!$this->canEditSetting($mailboxId))
		{
			$this->addFailure(403, self::ERROR_ACCESS_DENIED);

			return null;
		}

		$body = $this->readBody($body);
		if (mb_strlen($body) > self::BODY_MAX_LENGTH)
		{
			// The length is asked before the sanitizer, which is one of the passes over the whole text this
			// limit exists to bound
			$this->addFailure(422, self::ERROR_BODY_TOO_LONG);

			return null;
		}

		$body = $this->sanitize($body);
		if ($active && self::isBlankBody($body))
		{
			$this->addFailure(422, self::ERROR_BODY_REQUIRED);

			return null;
		}

		if ($active && !self::isMailboxSynchronized($mailbox))
		{
			$this->addFailure(409, self::ERROR_MAILBOX_UNAVAILABLE);

			return null;
		}

		$current = $this->getService()->getSetting($mailboxId);
		$startsAt = trim($startsAt);
		$endsAt = trim($endsAt);
		$timezoneId = trim($timezoneId);

		if ($startsAt === '' || $endsAt === '')
		{
			if ($active)
			{
				$this->addFailure(422, self::ERROR_PERIOD_REQUIRED);

				return null;
			}

			// Switching off does not ask for the period again: the stored one stays, and with it the
			// senders already answered inside it. A mailbox without a setting has nothing to switch off.
			if ($current === null)
			{
				// There is no row to keep a text in without a period, so a text typed on the initial form
				// would be dropped by a success: the period is asked for instead of losing it in silence
				if (!self::isBlankBody($body))
				{
					$this->addFailure(422, self::ERROR_PERIOD_REQUIRED);

					return null;
				}

				return ['setting' => $this->buildSetting($mailboxId, null)];
			}

			$timezoneId = (string)$current['timezoneId'];
			$startsAt = (string)self::formatBound($current['startsAt'], $timezoneId);
			$endsAt = (string)self::formatBound($current['endsAt'], $timezoneId);
		}
		elseif ($timezoneId === '')
		{
			$timezoneId = (string)($current['timezoneId'] ?? '');
			if (self::createTimezone($timezoneId) === null)
			{
				$timezoneId = self::resolveUserTimezoneId();
			}
		}

		// The setting belongs to the mailbox and its owner, not to whoever edits it.
		$result = $this->getService()->save(
			$mailboxId,
			(int)$mailbox['USER_ID'],
			$active,
			$startsAt,
			$endsAt,
			$timezoneId,
			$body,
		);
		if (!$result->isSuccess())
		{
			$this->addServiceFailure($result);

			return null;
		}

		return ['setting' => $this->buildSetting($mailboxId, $result->getData()['setting'])];
	}

	// Setting

	/**
	 * The body is HTML and comes raw from the request; the bound parameter is the way in for a client
	 * that speaks JSON and has nothing in the POST list.
	 */
	private function readBody(string $body): string
	{
		$raw = (string)$this->getRequest()->getPostList()->getRaw('body');

		return $raw === '' ? $body : $raw;
	}

	/**
	 * An editor that has been emptied still sends markup, so an empty text is not an empty string.
	 * Markup that carries content of its own counts as a text.
	 */
	private static function isBlankBody(string $body): bool
	{
		if (preg_match('/<(img|table|hr|iframe|video|audio)\b/i', $body) === 1)
		{
			return false;
		}

		$text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5);

		return trim(str_replace("\u{00A0}", ' ', $text)) === '';
	}

	/**
	 * A mailbox without a stored setting is answered with the state of a switched off
	 * auto-reply, so the screen has the same shape to fill in either way.
	 *
	 * @param array<string, mixed>|null $setting
	 *
	 * @return array{
	 *     mailboxId: int,
	 *     active: bool,
	 *     startsAt: string|null,
	 *     endsAt: string|null,
	 *     timezoneId: string,
	 *     body: string,
	 *     canEdit: bool,
	 * }
	 */
	private function buildSetting(int $mailboxId, ?array $setting): array
	{
		$timezoneId = self::resolveUserTimezoneId();

		return [
			'mailboxId' => $mailboxId,
			'active' => (bool)($setting['active'] ?? false),
			'startsAt' => self::formatBound($setting['startsAt'] ?? null, $timezoneId),
			'endsAt' => self::formatBound($setting['endsAt'] ?? null, $timezoneId),
			'timezoneId' => $timezoneId,
			'body' => (string)($setting['body'] ?? ''),
			'canEdit' => $this->canEditSetting($mailboxId),
		];
	}

	/**
	 * The bound is stored as the moment it points at; it goes back to the screen in the current user's
	 * timezone, so changing the timezone does not change that moment.
	 */
	private static function formatBound(?DateTime $bound, string $timezoneId): ?string
	{
		if ($bound === null)
		{
			return null;
		}

		$shown = clone $bound;
		$timezone = self::createTimezone($timezoneId);
		if ($timezone !== null)
		{
			$shown->setTimeZone($timezone);
		}

		return $shown->format(DATE_ATOM);
	}

	private static function createTimezone(string $timezoneId): ?\DateTimeZone
	{
		if ($timezoneId === '')
		{
			return null;
		}

		try
		{
			return new \DateTimeZone($timezoneId);
		}
		catch (\Exception)
		{
			return null;
		}
	}

	private static function resolveUserTimezoneId(): string
	{
		global $USER;

		$timezoneId = '';
		if (is_object($USER))
		{
			$timezoneId = (string)$USER->GetParam('TIME_ZONE');
			if ($timezoneId === '' && \CTimeZone::IsAutoTimeZone($USER->GetParam('AUTO_TIME_ZONE')))
			{
				$timezoneId = (string)(\CTimeZone::getTzCookie() ?? '');
			}
		}

		return self::createTimezone($timezoneId) === null ? date_default_timezone_get() : $timezoneId;
	}

	// Mailboxes and access

	/**
	 * Mailboxes of the switcher of the screen. Which mailboxes the user has is decided by the access
	 * facade alone; the addresses are only read for the identifiers it returned.
	 *
	 * @return list<array{id: int, email: string}>
	 */
	private function getAccessibleMailboxes(): array
	{
		$mailboxIds = MailboxAccess::resolveUserMailboxIds(null, $this->getCurrentUserId());
		if ($mailboxIds === [])
		{
			return [];
		}

		$addresses = [];
		$rows = MailboxTable::query()
			->setSelect(['ID', 'EMAIL'])
			->whereIn('ID', $mailboxIds)
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			$addresses[(int)$row['ID']] = (string)$row['EMAIL'];
		}

		$mailboxes = [];
		foreach ($mailboxIds as $mailboxId)
		{
			if (isset($addresses[$mailboxId]))
			{
				$mailboxes[] = ['id' => $mailboxId, 'email' => $addresses[$mailboxId]];
			}
		}

		return $mailboxes;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function findMailbox(int $mailboxId): ?array
	{
		if ($mailboxId <= 0)
		{
			return null;
		}

		$mailbox = MailboxTable::query()
			->setSelect(['ID', 'USER_ID', 'EMAIL', 'ACTIVE', 'SERVER_TYPE'])
			->where('ID', $mailboxId)
			->setLimit(1)
			->fetch()
		;

		return $mailbox ?: null;
	}

	/**
	 * @param array<string, mixed> $mailbox
	 */
	private static function isMailboxSynchronized(array $mailbox): bool
	{
		return ($mailbox['ACTIVE'] ?? '') === 'Y'
			&& ($mailbox['SERVER_TYPE'] ?? '') === self::SYNCHRONIZED_SERVER_TYPE
		;
	}

	/**
	 * The auto-reply of a mailbox is changed by its owner and by whoever may edit the mailbox itself;
	 * shared access to somebody else's mailbox is enough to see the setting, but not to change it.
	 */
	private function canEditSetting(int $mailboxId): bool
	{
		return $this->editableMailboxes[$mailboxId] ??= (
			MailboxAccess::hasCurrentUserAccessToMailbox($mailboxId)
			|| MailboxAccess::hasCurrentUserAccessToEditMailbox($mailboxId)
		);
	}

	private function getCurrentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	private function getService(): VacationAutoReplyService
	{
		return $this->service ??= new VacationAutoReplyService();
	}

	// Errors

	/**
	 * The gate of the feature answers from a prefilter, while the phrase of that answer belongs to the
	 * error contract of the controller together with the rest of them.
	 */
	public static function featureDisabledError(): Error
	{
		return self::createError(self::ERROR_FEATURE_DISABLED);
	}

	private static function createError(string $code): Error
	{
		$messageKey = self::ERROR_MESSAGE_KEYS[$code] ?? null;
		$message = $messageKey === null ? null : Loc::getMessage($messageKey);

		return new Error((string)($message ?? $code), $code);
	}

	private function addFailure(int $status, string $code): void
	{
		Context::getCurrent()->getResponse()->setStatus($status);

		$this->addError(self::createError($code));
	}

	/**
	 * A period the domain could not read is reported on the period fields: that is where the user can
	 * change it, and the timezone it is given in is part of it.
	 */
	private function addServiceFailure(Result $result): void
	{
		foreach ($result->getErrors() as $error)
		{
			[$status, $code] = match ($error->getCode())
			{
				VacationAutoReplyService::ERROR_PERIOD_ORDER => [422, self::ERROR_PERIOD_ORDER],
				VacationAutoReplyService::ERROR_PERIOD_INVALID,
				VacationAutoReplyService::ERROR_TIMEZONE_INVALID => [422, self::ERROR_PERIOD_REQUIRED],
				default => [500, self::ERROR_SAVE_FAILED],
			};

			$this->addFailure($status, $code);
		}
	}
}
