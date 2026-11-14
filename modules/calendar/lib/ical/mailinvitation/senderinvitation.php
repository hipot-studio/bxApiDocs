<?php

namespace Bitrix\Calendar\ICal\MailInvitation;

use Bitrix\Calendar\Core;
use Bitrix\Calendar\ICal\IcsManager;
use Bitrix\Calendar\Internal\GuestLink\GuestLinkRequisite;
use Bitrix\Calendar\Internal\GuestLink\GuestLinkResolver;
use Bitrix\Calendar\Internals\Log\Logger;
use Bitrix\Calendar\Public;
use Bitrix\Calendar\Sharing;
use Bitrix\Calendar\SerializeObject;
use Bitrix\Calendar\Util;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Mail\Event;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use CEvent;
use \Serializable;

/**
 * Class SenderInvitation
 * @package Bitrix\Calendar\ICal\MailInvitation
 */
abstract class SenderInvitation implements Serializable
{
	use SerializeObject;

	public const CHARSET = 'utf-8';
	public const CONTENT_TYPE = 'text/calendar';
	protected const ATTACHMENT_NAME = 'invite.ics';
	protected const MAIL_TEMPLATE = 'SEND_ICAL_INVENT';
	private const MAX_GUEST_LINK_RESOLVE_ATTEMPTS = 2;
	private const STABLE_GUEST_LINK_ERROR_CODES = [
		GuestLinkResolver::ERROR_FEATURE_OFF,
		GuestLinkResolver::ERROR_EVENT_NOT_SAVED,
		GuestLinkResolver::ERROR_NO_RIGHTS,
	];

	/**
	 * @var int
	 */
	protected int $counterInvitations = 0;
	/**
	 * @var array|null
	 */
	protected ?array $event = null;
	/**
	 * @var Context|null
	 */
	protected ?Context $context = null;
	/**
	 * @var string|null
	 */
	protected ?string $uid = '';

	/**
	 * Per-request cache of resolved guest link URLs keyed by parent event id:
	 * sender instances are created per recipient, the link is per event.
	 *
	 * @var array<int, string|null>
	 */
	private static array $guestLinkUrlCache = [];
	/** @var array<int, int> */
	private static array $guestLinkResolveAttempts = [];

	abstract public function executeAfterSuccessfulInvitation();
	abstract protected function getContent();
	abstract protected function getTemplateParams();
	abstract protected function getSubjectTitle();

	public static function createInstance(array $event, Context $context): SenderInvitation
	{
		return new static($event, $context);
	}

	public function __construct(array $event, Context $context)
	{
		$this->event = $event;
		$this->context = $context;
	}

	public function setCounterInvitations(?int $counterInvitations): static
	{
		$this->counterInvitations = $counterInvitations ?? 0;

		return $this;
	}

	public function setEvent(?array $event): static
	{
		$this->event = $event;

		return $this;
	}

	/**
	 * @return bool
	 * @throws LoaderException
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws NotImplementedException
	 */
	public function send(): bool
	{
		if ($this->event === null || $this->context === null)
		{
			return false;
		}

		$this->prepareEventFields();

		$content = $this->getContent();
		if (!$content)
		{
			return false;
		}

		$fields = $this->getMailEventFields();
		$params = array_merge($this->getBaseTemplateParams(), $this->getTemplateParams());

		$status = CEvent::sendImmediate(
			self::MAIL_TEMPLATE,
			SITE_ID,
			array_merge($fields, $params),
			'Y',
			'',
			[],
			'',
			$content,
		);

		return $status === Event::SEND_RESULT_SUCCESS;
	}

	protected function getMailEventFields(): array
	{
		return [
			"=Reply-To" => "{$this->getAddresser()->getFullName()} <{$this->getAddresser()->getEmail()}>",
			"=From" => "{$this->getAddresser()->getFullName()} <{$this->getAddresser()->getEmail()}>",
			"=Message-Id" => $this->getMessageId(),
			"=In-Reply-To" => $this->getMessageReplyTo(),
			'EMAIL_FROM' => $this->getAddresser()->getEmail(),
			'EMAIL_TO' => $this->getReceiver()->getEmail(),
			'MESSAGE_SUBJECT' => $this->getSubjectMessage(),
			'MESSAGE_PHP' => $this->getBodyMessage(),
		];
	}

	private function getBaseTemplateParams(): array
	{
		$detailLink = Public\PublicEvent::getDetailLink(
			$this->getEventId(),
			$this->getEventOwnerId(),
			$this->getEventDateCreateTimestamp(),
		);

		return [
			'EVENT_NAME' => $this->event['NAME'],
			'DATE_FROM' => $this->event['DATE_FROM'],
			'DATE_TO' => $this->event['DATE_TO'],
			'IS_FULL_DAY' => $this->event['DT_SKIP_TIME'] === 'Y',
			'TZ_FROM' =>  $this->event['TZ_FROM'],
			'TZ_TO' =>  $this->event['TZ_TO'],
			'AVATARS' => $this->event['AVATARS'],
			'OWNER_STATUS' => $this->event['OWNER_STATUS'],
			'RRULE' => $this->getRRuleString(),

			'DETAIL_LINK' => $detailLink,
			'ICS_LINK' => $detailLink . Public\PublicEvent::ACTION_ICS,
			'DECISION_YES_LINK' => $detailLink . Public\PublicEvent::ACTION_ACCEPT,
			'DECISION_NO_LINK' => $detailLink . Public\PublicEvent::ACTION_DECLINE,
			'BITRIX24_LINK' => Sharing\Helper::getBitrix24Link(),
		];
	}

	protected function getMessageReplyTo(): string
	{
		return $this->getMessageId();
	}

	/**
	 * @return string
	 */
	protected function getRRuleString(): string
	{
		$rrule = \CCalendarEvent::ParseRRULE($this->event['RRULE']);
		if (is_array($rrule))
		{
			return Helper::getIcalTemplateRRule(
				$rrule,
				[
					'DATE_FROM' => $this->event['DATE_FROM'],
				],
			);
		}

		return '';
	}

	/**
	 * @return string
	 */
	public function getMethod(): string
	{
		return static::METHOD;
	}

	/**
	 * @return array
	 */
	public function getEvent(): array
	{
		return $this->event ?? [];
	}

	/**
	 *
	 */
	public function incrementCounterInvitations(): void
	{
		$this->counterInvitations++;
	}

	/**
	 * @return string
	 */
	public function getUid(): string
	{
		return $this->uid;
	}

	/**
	 * @return int
	 */
	public function getCountAttempsSend(): int
	{
		return $this->counterInvitations;
	}

	/**
	 * @return MailAddresser
	 */
	public function getAddresser():MailAddresser
	{
		return $this->context->getAddresser();
	}

	/**
	 * @return MailReceiver
	 */
	public function getReceiver(): MailReceiver
	{
		return $this->context->getReceiver();
	}

	/**
	 * @return int
	 */
	public function getEventId(): ?int
	{
		return $this->event['ID'] ?? null;
	}

	/**
	 * @return int
	 */
	public function getEventParentId(): int
	{
		return (int) ($this->event['PARENT_ID'] ?? 0);
	}

	/**
	 * @return string
	 */
	protected function getBodyMessage(): string
	{
		//@TODO edit body message
		return 'ical body message';
	}

	/**
	 * @return string
	 */
	protected function getSiteName(): string
	{
		if (!empty($siteName = \COption::GetOptionString("main", "site_name", '', '-')))
		{
			return "[{$siteName}]";
		}

		return '';
	}

	/**
	 * @return string
	 */
	protected function getSubjectMessage(): string
	{
		return $this->getSiteName(). ' ' . $this->getSubjectTitle();
	}

	/**
	 * @return string
	 */
	protected function getMessageId(): string
	{
		$serverName = \COption::GetOptionString("main", "server_name", $GLOBALS["SERVER_NAME"]);

		return "<CALENDAR_EVENT_{$this->getEventParentId()}@{$serverName}>";
	}

	/**
	 * @return int
	 */
	protected function getEventDateCreateTimestamp(): int
	{
		return (int) Util::getTimestamp($this->event['DATE_CREATE']);
	}

	/**
	 * @return int
	 */
	protected function getEventOwnerId(): int
	{
		return (int) $this->event['OWNER_ID'];
	}

	private function getFormattedDate(DateTime $formattedDate, string $dtSkipTime): string
	{
		$timestamp = \CCalendar::Timestamp($formattedDate, false, $dtSkipTime !== 'Y');

		return \CCalendar::Date($timestamp);
	}

	/**
	 * Builds the ICS description for the event.
	 *
	 * The guest invite link is resolved via GuestLinkResolver and added as a separate
	 * requisite, the same way conferenceUrl is. Whether the event is in scope is decided
	 * by the resolver. On resolver failure the description is built without the guest
	 * link and the error is logged.
	 */
	private function buildIcsDescription(Core\Event\Event $event): string
	{
		if ($event->getMeetingDescription()?->getHideGuests())
		{
			$event->setAttendeesCollection(null);
		}

		$descriptionParams = ['eventUrl' => Public\PublicEvent::getDetailLinkFromEvent($event)];

		$guestLinkUrl = $this->prepareGuestLinkForIcs($event);
		if ($guestLinkUrl !== null)
		{
			$descriptionParams['guestLinkUrl'] = $guestLinkUrl;
		}

		$rawDescription = IcsManager::getInstance()->prepareEventDescription($event, $descriptionParams);

		return str_replace("\\n", "\n", $rawDescription);
	}

	/**
	 * Returns the guest invite link to put into the mail, or null, and clears the legacy
	 * link line from the in-memory description when the new requisite replaces it.
	 *
	 * A call_sync event created before the migration, not yet through re-save or the
	 * scrubber, may still carry the legacy line in its DESCRIPTION. The line is dropped
	 * only together with adding the new requisite, the same way the ics channels do it:
	 * an invitation is a one-shot mail to an external guest with no access to the event
	 * card, so a failed resolve must not leave them without any link at all. Clearing the
	 * line in the stored DESCRIPTION is the job of strip-on-save and of
	 * {@see \Bitrix\Calendar\Infrastructure\Agent\GuestLink\DescriptionGuestLinkScrubber}.
	 */
	private function prepareGuestLinkForIcs(Core\Event\Event $event): ?string
	{
		if ($this->isCancellation())
		{
			// A cancellation notice carries no link to join: resolving one would create the
			// meeting chat just to announce that the meeting is off, and the legacy line is
			// as pointless there, so it goes regardless of the resolve.
			GuestLinkRequisite::stripLegacyLink($event);

			return null;
		}

		$guestLinkUrl = $this->resolveGuestLinkUrl($event);
		if ($guestLinkUrl !== null)
		{
			GuestLinkRequisite::stripLegacyLink($event);
		}

		return $guestLinkUrl;
	}

	private function isCancellation(): bool
	{
		return $this->getMethod() === SenderCancelInvitation::METHOD;
	}

	/**
	 * Resolves the guest invite link URL for the event via GuestLinkResolver::getOrCreate().
	 *
	 * The eligible event types are owned by the resolver: for an event out of scope
	 * getOrCreate() returns an error and no link ends up in the description.
	 *
	 * Resolution goes through the organizer's parent event (PARENT_ID): the guest
	 * chat lives there, while attendee child copies may not have CHAT_ID replicated
	 * and the organizer is not guaranteed VIEW_FULL on them.
	 *
	 * Returns null on any error (feature off, event not found, no rights, chat/link failure).
	 * Errors are logged under DEBUG_CALENDAR_EMAIL_NOTIFICATION; they do not block sending.
	 * NO_RIGHTS is the exception, the way {@see GuestLinkRequisite} treats it: an organizer
	 * outside the employee scope, or one without VIEW_FULL on the event, is a regular
	 * outcome of this channel and would otherwise log a line for every event they own.
	 * Successful outcomes and deterministic failures are cached per parent event for
	 * the request. Transient failures get one bounded retry across the whole batch.
	 */
	private function resolveGuestLinkUrl(Core\Event\Event $event): ?string
	{
		$eventId = (int)($this->event['PARENT_ID'] ?? 0) ?: (int)$event->getId();

		if ($eventId <= 0)
		{
			return null;
		}

		if (array_key_exists($eventId, self::$guestLinkUrlCache))
		{
			return self::$guestLinkUrlCache[$eventId];
		}

		$attempt = (self::$guestLinkResolveAttempts[$eventId] ?? 0) + 1;
		self::$guestLinkResolveAttempts[$eventId] = $attempt;

		try
		{
			/** @var GuestLinkResolver $resolver */
			$resolver = ServiceLocator::getInstance()->get(GuestLinkResolver::class);
			$result = $resolver->getOrCreateForTrustedOrganizer($eventId);
			if ($result->isSuccess())
			{
				return self::$guestLinkUrlCache[$eventId] = $result->getData()['url'] ?? null;
			}
			if ($this->isStableGuestLinkFailure($result) || $attempt >= self::MAX_GUEST_LINK_RESOLVE_ATTEMPTS)
			{
				self::$guestLinkUrlCache[$eventId] = null;
			}

			$failures = array_filter(
				$result->getErrors(),
				static fn($e) => $e->getCode() !== GuestLinkResolver::ERROR_NO_RIGHTS,
			);

			if ($failures)
			{
				$logger = new Logger('DEBUG_CALENDAR_EMAIL_NOTIFICATION');
				$logger->log(
					'GuestLinkResolver::getOrCreate failed for event ' . $eventId
					. ': ' . implode(', ', array_map(
						static fn($e) => $e->getCode() . ' - ' . $e->getMessage(),
						$failures,
					))
				);
			}
		}
		catch (\Throwable $e)
		{
			if ($attempt >= self::MAX_GUEST_LINK_RESOLVE_ATTEMPTS)
			{
				self::$guestLinkUrlCache[$eventId] = null;
			}

			$logger = new Logger('DEBUG_CALENDAR_EMAIL_NOTIFICATION');
			$logger->log($e);
		}

		return null;
	}

	private function isStableGuestLinkFailure(\Bitrix\Main\Result $result): bool
	{
		$errors = $result->getErrors();
		if ($errors === [])
		{
			return false;
		}

		foreach ($errors as $error)
		{
			if (!in_array($error->getCode(), self::STABLE_GUEST_LINK_ERROR_CODES, true))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @throws LoaderException
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws NotImplementedException
	 */
	protected function prepareEventFields(): void
	{
		$dtSkipTime = $this->event['DT_SKIP_TIME'];
		$this->event['DATE_FROM'] = $this->getFormattedDate($this->event['DATE_FROM'], $dtSkipTime);
		$this->event['DATE_TO'] = $this->getFormattedDate($this->event['DATE_TO'], $dtSkipTime);
		$this->event['DATE_CREATE'] = $this->event['DATE_CREATE']->toString();
		$this->event['TIMESTAMP_X'] = $this->event['TIMESTAMP_X']->toString();

		$this->event['MEETING'] = unserialize($this->event['MEETING'], ['allowed_classes' => false]);
		$this->event['REMIND'] = unserialize($this->event['REMIND'], ['allowed_classes' => false]);
		$this->event['RRULE'] = \CCalendarEvent::ParseRRULE($this->event['RRULE']);
		$this->event['ATTENDEES_CODES'] = !empty($this->event['ATTENDEES_CODES']) && is_string($this->event['ATTENDEES_CODES'])
			? explode(',', $this->event['ATTENDEES_CODES'])
			: []
		;
		$this->event['ICAL_ORGANIZER'] = Helper::getAttendee($this->event['MEETING_HOST'], $this->event['PARENT_ID'], false);
		$this->event['ICAL_ATTACHES'] = Helper::getMailAttaches(
			null,
			$this->event['MEETING_HOST'],
			$this->event['PARENT_ID']
		);

		$event = (new Core\Mappers\Event())->getByArray($this->event);
		$this->event['DESCRIPTION'] = $this->buildIcsDescription($event);

		$attendees = \CCalendarEvent::GetAttendees([$this->event['PARENT_ID']], false)[$this->event['PARENT_ID']] ?? [];

		$ownerId = (int)$this->event['OWNER_ID'];
		$owner = current(array_filter(
			$attendees,
			static fn($attendee) => (int)$attendee['USER_ID'] === $ownerId,
		));

		$this->event['OWNER_STATUS'] = $owner['STATUS'];

		$this->event['AVATARS'] = [];
		if (!$this->event['MEETING']['HIDE_GUESTS'])
		{
			usort($attendees, static fn($a, $b) => ((int)$b['USER_ID'] === $ownerId) - ((int)$a['USER_ID'] === $ownerId));
			foreach ($attendees as $attendee)
			{
				$this->event['AVATARS'][] = $attendee['AVATAR'];
			}
			$this->event['ICAL_ATTENDEES'] = Helper::getAttendeesByEventParentId($this->event['PARENT_ID']);
		}
	}
}
