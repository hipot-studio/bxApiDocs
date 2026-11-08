<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Im;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use CCrmActivityType;
use CCrmOwnerType;
use CCrmUrlUtil;
use CIMNotify;
use Closure;

/**
 * The messenger side of an activity: the notifications about who is responsible for it (LBS-1 sections 9.1
 * and 9.2, checklist items G6, G7 and G9).
 *
 * TWO METHODS, ONE PER NOTIFIED EVENT. An activity that has just been assigned notifies its responsible
 * ({@see notifyAssignment()}); an activity that changed hands notifies the user who lost it and the user who
 * received it ({@see notifyResponsibleChange()}). Both addressees of a change travel in one call because the
 * event is one - and each of them gets a payload of their own, see the note on the component below.
 *
 * FACTS IN, NOTIFICATIONS OUT. The layer decides nothing about the pipeline and nothing about rights: who the
 * sender of the notification is, whether the responsible really changed, and whether an addressee may read an
 * owner of the activity are facts the step hands over. What belongs here is the payload of the messenger - the
 * addressees the payload has a text for, the phrase of every text, the tag, the event and the late resolution
 * of the wording.
 *
 * THE ANSWER IS A COUNT of the notifications handed to the messenger: one or none for an assignment, two, one
 * or none for a change. Whether the messenger accepted them is not inspected, and legacy does not inspect it
 * either.
 *
 * WHAT CROSSES THE BOUNDARY. Scalars of CRM, never a type of the messenger. The type of the activity is the
 * legacy `TYPE_ID` ({@see CCrmActivityType}) because the typed model does not carry it, and
 * {@see supportsActivityType()} answers which types have a notification at all, so that no step has to spell
 * the pair out a second time.
 *
 * THE MESSENGER IS AN OPTIONAL DEPENDENCY. Without the module nothing is sent, nothing is added to the result
 * of the operation and the caller cannot tell the difference: {@see Loader::includeModule()}, never
 * `requireModule()`. THE CLASS IS NOT FINAL for that very branch - {@see isAvailable()} is the seam its test
 * needs, and it is the form the sample of this layer uses (`Internal\Integration\Sale\LocationDictionary`).
 *
 * THE WORDING IS RESOLVED LATE. Every text is a closure over the language of the addressee, and the messenger
 * resolves it once it knows that language (item G9, im_notify.php:45-55). The phrases belong to the legacy
 * pipelines - the notification block moved here, its translations stayed where they are - so every closure
 * loads that language file for the language it was asked about instead of carrying a second copy of the
 * wording, which would fork the two.
 *
 * @internal
 */
class ActivityNotifier
{
	/** Activity the notification is about; it has to be stored already. */
	public const INPUT_ACTIVITY_ID = 'activityId';

	/** Legacy type of the activity: only calls and meetings have a notification (item G6). */
	public const INPUT_ACTIVITY_TYPE_ID = 'activityTypeId';

	/** Subject of the activity, as the addressee is to read it. */
	public const INPUT_SUBJECT = 'subject';

	/** User the notification comes from: the initiator of the operation, as the step resolved it. */
	public const INPUT_SENDER_ID = 'senderId';

	/**
	 * Record of the live feed the notification points at, or zero when there is none. A native pipeline
	 * writes no live feed record at all, so zero is the value it always hands over - and that is the state
	 * legacy is in on a portal with the live feed switched off, where the notification is sent anyway.
	 */
	public const INPUT_LOG_ID = 'logId';

	/** Responsible of the activity: the addressee of an assignment, the new one of a change. */
	public const INPUT_RESPONSIBLE_ID = 'responsibleId';

	/** Whether that user may read at least one owner of the activity; without it the text is simplified. */
	public const INPUT_RESPONSIBLE_HAS_READ_ACCESS = 'responsibleHasReadAccess';

	/** Responsible the activity had before the change. */
	public const INPUT_PREVIOUS_RESPONSIBLE_ID = 'previousResponsibleId';

	/** Whether that user may read at least one owner; without it there is no notification for them at all. */
	public const INPUT_PREVIOUS_RESPONSIBLE_HAS_READ_ACCESS = 'previousResponsibleHasReadAccess';

	private const INPUTS_ASSIGNMENT = [
		self::INPUT_ACTIVITY_ID,
		self::INPUT_ACTIVITY_TYPE_ID,
		self::INPUT_SUBJECT,
		self::INPUT_SENDER_ID,
		self::INPUT_LOG_ID,
		self::INPUT_RESPONSIBLE_ID,
		self::INPUT_RESPONSIBLE_HAS_READ_ACCESS,
	];

	private const INPUTS_RESPONSIBLE_CHANGE = [
		self::INPUT_ACTIVITY_ID,
		self::INPUT_ACTIVITY_TYPE_ID,
		self::INPUT_SUBJECT,
		self::INPUT_SENDER_ID,
		self::INPUT_LOG_ID,
		self::INPUT_RESPONSIBLE_ID,
		self::INPUT_RESPONSIBLE_HAS_READ_ACCESS,
		self::INPUT_PREVIOUS_RESPONSIBLE_ID,
		self::INPUT_PREVIOUS_RESPONSIBLE_HAS_READ_ACCESS,
	];

	/**
	 * The two types of activity a notification is written for, and the part of the phrase code that names
	 * them. Phrases of two more types exist in the language file, and legacy reaches neither: its type
	 * switch has no branch for them (crm_activity.php:7803-7813).
	 */
	private const PHRASE_TYPES = [
		CCrmActivityType::Call => 'CALL',
		CCrmActivityType::Meeting => 'MEETING',
	];

	private const MODULE_ID = 'im';

	private const NOTIFY_EVENT = 'changeAssignedBy';

	private const TAG_PREFIX = 'CRM|ACTIVITY|';

	/**
	 * Whether the messenger is installed. The step is free to ask before it runs; every notification below
	 * asks again, so a step that does not ask still cannot send into a portal without the module.
	 */
	public function isAvailable(): bool
	{
		return Loader::includeModule(self::MODULE_ID);
	}

	/**
	 * Whether an activity of this legacy type is notified about at all.
	 */
	public function supportsActivityType(int $activityTypeId): bool
	{
		return isset(self::PHRASE_TYPES[$activityTypeId]);
	}

	/**
	 * Tells the responsible of an activity that it is theirs.
	 *
	 * @param array<string, mixed> $notification the INPUT_* values of an assignment.
	 * @return int the number of notifications handed to the messenger: one, or none when there was nothing
	 *         to send.
	 * @throws ArgumentException on a key that is not an input of an assignment.
	 */
	public function notifyAssignment(array $notification): int
	{
		self::assertInput($notification, self::INPUTS_ASSIGNMENT);

		$activityId = self::intOf($notification, self::INPUT_ACTIVITY_ID);
		$responsibleId = self::intOf($notification, self::INPUT_RESPONSIBLE_ID);
		$type = $this->phraseTypeOf($notification);
		if ($activityId <= 0 || $responsibleId <= 0 || $type === null || !$this->isAvailable())
		{
			return 0;
		}

		$subject = self::stringOf($notification, self::INPUT_SUBJECT);
		$url = self::urlOf($activityId);

		$payload = self::basePayload($notification, $activityId);
		$payload['TO_USER_ID'] = $responsibleId;
		$payload = array_merge($payload, self::responsibleText($type, $subject, $url));

		if (!self::flagOf($notification, self::INPUT_RESPONSIBLE_HAS_READ_ACCESS))
		{
			// A reader without rights gets the text without the link - and gets it, which is the whole
			// point of item G6. The component of the payload above is left in place, as legacy leaves it.
			$plain = self::plainText(self::phraseCode($type, false), $subject);
			$payload['NOTIFY_MESSAGE'] = $plain;
			$payload['NOTIFY_MESSAGE_OUT'] = $plain;
		}

		CIMNotify::Add($payload);

		return 1;
	}

	/**
	 * Tells the two users an activity changed hands between: the one it is no longer theirs, the one it is
	 * theirs now.
	 *
	 * THE ASYMMETRY IS THE CONTRACT (item G7). The user who lost the activity hears about it only when they
	 * may read an owner of it - there is no wording for the other case - while the user who received it
	 * always hears about it, with the simplified text when they may not read an owner.
	 *
	 * @param array<string, mixed> $notification the INPUT_* values of a change of the responsible.
	 * @return int the number of notifications handed to the messenger: two, one, or none.
	 * @throws ArgumentException on a key that is not an input of a change of the responsible.
	 */
	public function notifyResponsibleChange(array $notification): int
	{
		self::assertInput($notification, self::INPUTS_RESPONSIBLE_CHANGE);

		$activityId = self::intOf($notification, self::INPUT_ACTIVITY_ID);
		$previousResponsibleId = self::intOf($notification, self::INPUT_PREVIOUS_RESPONSIBLE_ID);
		$responsibleId = self::intOf($notification, self::INPUT_RESPONSIBLE_ID);
		$type = $this->phraseTypeOf($notification);
		if (
			$activityId <= 0
			|| $previousResponsibleId === $responsibleId
			|| $type === null
			|| !$this->isAvailable()
		)
		{
			return 0;
		}

		$senderId = self::intOf($notification, self::INPUT_SENDER_ID);
		$subject = self::stringOf($notification, self::INPUT_SUBJECT);
		$url = self::urlOf($activityId);

		/*
		 * A PAYLOAD PER ADDRESSEE (registry row IM-COMPONENT-PER-ADDRESSEE). Legacy builds the payload once
		 * and overwrites the addressee and the texts for the second one (crm_activity.php:8000-8184), so a
		 * new responsible who may not read an owner inherits the CRM entity component - the title, the
		 * address and the content of the activity - assembled for the previous one. That is a leak between
		 * two addressees rather than a behaviour to carry over, so every addressee below is handed a payload
		 * built from the base again: nothing of one notification can reach the other.
		 */
		$sent = 0;

		if (
			$previousResponsibleId > 0
			&& $previousResponsibleId !== $senderId
			&& self::flagOf($notification, self::INPUT_PREVIOUS_RESPONSIBLE_HAS_READ_ACCESS)
		)
		{
			$payload = self::basePayload($notification, $activityId);
			$payload['TO_USER_ID'] = $previousResponsibleId;
			$payload = array_merge($payload, self::previousResponsibleText($type, $subject, $url));
			CIMNotify::Add($payload);
			++$sent;
		}

		if ($responsibleId > 0 && $responsibleId !== $senderId)
		{
			$payload = self::basePayload($notification, $activityId);
			$payload['TO_USER_ID'] = $responsibleId;
			if (self::flagOf($notification, self::INPUT_RESPONSIBLE_HAS_READ_ACCESS))
			{
				$payload = array_merge($payload, self::responsibleText($type, $subject, $url));
			}
			else
			{
				$plain = self::plainText(self::phraseCode($type, false), $subject);
				$payload['NOTIFY_MESSAGE'] = $plain;
				$payload['NOTIFY_MESSAGE_OUT'] = $plain;
			}

			CIMNotify::Add($payload);
			++$sent;
		}

		return $sent;
	}

	/**
	 * The part of the payload both addressees of both events share.
	 *
	 * @param array<string, mixed> $notification
	 * @return array<string, mixed>
	 */
	private static function basePayload(array $notification, int $activityId): array
	{
		return [
			'MESSAGE_TYPE' => IM_MESSAGE_SYSTEM,
			'FROM_USER_ID' => self::intOf($notification, self::INPUT_SENDER_ID),
			'NOTIFY_TYPE' => IM_NOTIFY_FROM,
			'NOTIFY_MODULE' => 'crm',
			'LOG_ID' => self::intOf($notification, self::INPUT_LOG_ID),
			'NOTIFY_EVENT' => self::NOTIFY_EVENT,
			'NOTIFY_TAG' => self::TAG_PREFIX . $activityId,
		];
	}

	/**
	 * The text of a user the activity belongs to, with the link to it and the component of a plain
	 * notification.
	 *
	 * @return array<string, mixed>
	 */
	private static function responsibleText(string $type, string $subject, string $url): array
	{
		$code = self::phraseCode($type, false);

		return [
			'NOTIFY_MESSAGE' => self::linkedText($code, $subject, $url),
			'NOTIFY_MESSAGE_OUT' => self::outgoingText($code, $subject, $url),
			'PARAMS' => [
				'COMPONENT_ID' => 'DefaultEntity',
				'COMPONENT_PARAMS' => [
					'SUBJECT' => self::text($code . '_SUBJECT', [
						'#LINK_START#' => '<a href="' . $url . '">',
						'#LINK_END#' => '</a>',
					]),
				],
			],
		];
	}

	/**
	 * The text of a user the activity no longer belongs to, with the CRM entity component that carries the
	 * activity itself.
	 *
	 * @return array<string, mixed>
	 */
	private static function previousResponsibleText(string $type, string $subject, string $url): array
	{
		$code = self::phraseCode($type, true);

		return [
			'NOTIFY_MESSAGE' => self::linkedText($code, $subject, $url),
			'NOTIFY_MESSAGE_OUT' => self::outgoingText($code, $subject, $url),
			'PARAMS' => [
				'COMPONENT_ID' => 'CrmEntity',
				'COMPONENT_PARAMS' => [
					'SUBJECT' => self::text($code . '_SUBJECT', null),
					'ENTITY' => [
						'TITLE' => htmlspecialcharsbx($subject),
						'HREF' => $url,
						'ENTITY_TYPE' => strtolower($type),
						'CONTENT_TYPE' => 'text',
						'CONTENT' => [
							'VALUE' => self::text($code . '_DESCRIPTION', null),
						],
					],
				],
			],
		];
	}

	/**
	 * Supported phrase codes:
	 * CRM_ACTIVITY_CALL_RESPONSIBLE_IM_NOTIFY
	 * CRM_ACTIVITY_MEETING_RESPONSIBLE_IM_NOTIFY
	 * CRM_ACTIVITY_CALL_NOT_RESPONSIBLE_IM_NOTIFY
	 * CRM_ACTIVITY_MEETING_NOT_RESPONSIBLE_IM_NOTIFY
	 */
	private static function phraseCode(string $type, bool $isLost): string
	{
		return $isLost
			? "CRM_ACTIVITY_{$type}_NOT_RESPONSIBLE_IM_NOTIFY"
			: "CRM_ACTIVITY_{$type}_RESPONSIBLE_IM_NOTIFY"
		;
	}

	private static function linkedText(string $code, string $subject, string $url): Closure
	{
		$title = '<a href="' . $url . '">' . htmlspecialcharsbx($subject) . '</a>';

		return self::text($code, ['#title#' => $title]);
	}

	private static function plainText(string $code, string $subject): Closure
	{
		return self::text($code, ['#title#' => htmlspecialcharsbx($subject)]);
	}

	/**
	 * The text of a notification that leaves the portal: no markup, and the address of the activity spelled
	 * out behind it.
	 */
	private static function outgoingText(string $code, string $subject, string $url): Closure
	{
		$absoluteUrl = CCrmUrlUtil::ToAbsoluteUrl($url);
		$plain = self::plainText($code, $subject);

		return static fn(?string $languageId = null): string => $plain($languageId) . " ({$absoluteUrl})";
	}

	/**
	 * A phrase the messenger resolves when it knows the language of the addressee. The language file of the
	 * legacy pipelines is loaded for that very language, because that is where the wording of every one of
	 * these phrases lives.
	 *
	 * @param array<string, string>|null $replace
	 */
	private static function text(string $code, ?array $replace): Closure
	{
		return static function (?string $languageId = null) use ($code, $replace): string {
			Loc::loadLanguageFile(self::legacyPipelinesFile(), $languageId);

			return (string)Loc::getMessage($code, $replace, $languageId);
		};
	}

	private static function legacyPipelinesFile(): string
	{
		return dirname(__DIR__, 5) . '/classes/general/crm_activity.php';
	}

	private static function urlOf(int $activityId): string
	{
		return (string)CCrmOwnerType::GetEntityShowPath(CCrmOwnerType::Activity, $activityId);
	}

	/**
	 * @param array<string, mixed> $notification
	 */
	private function phraseTypeOf(array $notification): ?string
	{
		$activityTypeId = self::intOf($notification, self::INPUT_ACTIVITY_TYPE_ID);

		return $this->supportsActivityType($activityTypeId) ? self::PHRASE_TYPES[$activityTypeId] : null;
	}

	/**
	 * @param array<string, mixed> $notification
	 */
	private static function intOf(array $notification, string $input): int
	{
		return (int)($notification[$input] ?? 0);
	}

	/**
	 * @param array<string, mixed> $notification
	 */
	private static function stringOf(array $notification, string $input): string
	{
		return (string)($notification[$input] ?? '');
	}

	/**
	 * A fact of rights is only a fact when the caller states it: anything but a true means "not stated", and
	 * the simplified text is what a notification falls back to.
	 *
	 * @param array<string, mixed> $notification
	 */
	private static function flagOf(array $notification, string $input): bool
	{
		return ($notification[$input] ?? false) === true;
	}

	/**
	 * @param array<string, mixed> $notification
	 * @param string[] $inputs
	 * @throws ArgumentException
	 */
	private static function assertInput(array $notification, array $inputs): void
	{
		foreach (array_keys($notification) as $key)
		{
			if (!in_array($key, $inputs, true))
			{
				throw new ArgumentException("'{$key}' is not an input of this notification", 'notification');
			}
		}
	}
}
