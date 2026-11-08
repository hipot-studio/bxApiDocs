<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Mapper;

use Bitrix\Crm\Service\Context;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext;
use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\DateTime;
use CCrmPerms;

/**
 * The one projection of the option array of a legacy write pipeline into the typed context of an operation
 * (DTO-02). Its callers are the facade over the legacy entry points and the mass operations that build the
 * same array by hand.
 *
 * ONE ARRAY, ONE PROJECTION, NO OPERATION TO NAME. Legacy has a single option array per call and lets each
 * operation read the keys it cares about; a key another operation would have read is simply inert. That is why
 * there is no projection per operation here: a completion hands its whole array over to an update, and a
 * projection narrowed to the keys of a completion would drop the very value the update needs - the custom
 * creation time, whose only producer is a completion and whose only reader is the timeline of an update.
 *
 * THE COMPARISONS ARE THE LEGACY ONES, DOWN TO THEIR STRICTNESS. Four values are compared strictly with true
 * where they are read - preserving the creation time, registering the record of the live feed, skipping the
 * tasks and skipping the change of a deadline - so an option of "Y" or of 1 means false for those four and
 * true for the rest. Getting that wrong is invisible in a test that passes booleans and changes behaviour for
 * every caller that does not.
 *
 * THE USER OF THE CALLER IS NORMALIZED HERE AND ONLY HERE. Legacy writes the normalized value back into its
 * own copy of the array and hands that copy on, so everything downstream sees the fallback already applied;
 * the typed context does the same by carrying the normalized value as the effective user. The raw value is
 * derived from the very same key, never from an incoming "~CURRENT_USER": legacy overwrites that key
 * unconditionally, so a caller cannot forge it, and the difference between the two - zero against the acting
 * user - is what picks who performs an associated task.
 *
 * THERE IS NO WAY BACK FOR THE PROJECTION, AND THE ARRAY ITSELF TRAVELS BESIDE IT. Nothing rebuilds an option
 * array out of the typed rows: the arrays a legacy provider is given are built by the wrapper of that provider,
 * per call, out of the array of the caller as it was given ({@see ActivityOperationContext::getLegacyOptions()})
 * plus the write-back the operation performs. The array is carried rather than projected because a legacy provider
 * reads keys no rule of the domain reads - taking the tasks of a delete along, leaving the subject or the deadline
 * of a task alone - and a narrowed projection changed what those providers do. The legacy entry points take their
 * array by value, so no caller of theirs ever sees the write-back either.
 *
 * @internal
 */
final class LegacyOptionsMapper
{
	/**
	 * @param array<string, mixed> $options The option array as a legacy entry point received it.
	 * @param ProviderPayload $inputPayload The serialized fields being written, empty when the operation
	 *     writes none - a delete and a completion have no input representation of their own.
	 * @param StorageFieldTransit $inputTransit The storage columns being written, empty for the same reason.
	 * @param string $entrySource Where the operation comes from. No key of the option array stands behind it and
	 *     the facade over the legacy entry points is what the caller of this projection is, so that is the
	 *     default.
	 * @param bool $registerHistoryEvent Whether a record of the history of the CRM is written. A positional
	 *     argument of every legacy write entry point rather than a key of its options.
	 * @param bool $checkPermissions Whether the operation performs the checks of the rights it has. Likewise.
	 * @param array<string, mixed> $userFieldValues The values of the user fields of the activity, empty when the
	 *     caller named none. No key of the option array stands behind them either - they are keys of the FIELD
	 *     array, and the projection of that array hands them over.
	 * @param array<string, mixed> $calendarInput What the caller said about the calendar event alone, likewise out
	 *     of the field array.
	 * @param string[] $incorrectMomentColumns The datetime columns of the field array whose value is no moment,
	 *     likewise out of the field array and stated by the projection of it.
	 */
	public static function createOperationContext(
		array $options,
		ProviderPayload $inputPayload = new ProviderPayload(),
		StorageFieldTransit $inputTransit = new StorageFieldTransit(),
		string $entrySource = ActivityOperationContext::ENTRY_SOURCE_FACADE,
		bool $registerHistoryEvent = true,
		bool $checkPermissions = true,
		array $userFieldValues = [],
		array $calendarInput = [],
		array $incorrectMomentColumns = [],
		bool $calledWithNoField = false,
	): ActivityOperationContext
	{
		return new ActivityOperationContext(
			$inputPayload,
			$inputTransit,
			currentUserId: (int)($options['CURRENT_USER'] ?? CCrmPerms::GetCurrentUserID()),
			rawCurrentUser: (int)($options['CURRENT_USER'] ?? 0),
			executorId: (int)($options['EXECUTOR_ID'] ?? 0),
			isRestoration: (bool)($options['IS_RESTORATION'] ?? false),
			skipCurrentCalendarEvent: (bool)($options['SKIP_CURRENT_CALENDAR_EVENT'] ?? true),
			scope: self::scopeOf($options['CONTEXT'] ?? null),
			initiatedByCalendar: (bool)($options['INITIATED_BY_CALENDAR'] ?? false),
			preserveCreationTime: ($options['PRESERVE_CREATION_TIME'] ?? null) === true,
			customCreationTime: self::userTimeOf($options['CUSTOM_CREATION_TIME'] ?? null),
			skipCalendarEvent: (bool)($options['SKIP_CALENDAR_EVENT'] ?? false),
			registerSonetEvent: ($options['REGISTER_SONET_EVENT'] ?? null) === true,
			moveBindingsMap: self::arrayOf($options['MOVE_BINDINGS_MAP'] ?? null) ?? [],
			skipAssociatedEntity: (bool)($options['SKIP_ASSOCIATED_ENTITY'] ?? false),
			skipDeadlineChange: ($options['SKIP_DEADLINE_CHANGE'] ?? null) === true,
			analyticsEvent: self::analyticsEventOf($options['ANALYTICS_EVENT'] ?? null),
			actualItem: self::arrayOf($options['ACTUAL_ITEM'] ?? null),
			actualBindings: self::arrayOf($options['ACTUAL_BINDINGS'] ?? null),
			movedToRecycleBin: (bool)($options['MOVED_TO_RECYCLE_BIN'] ?? false),
			enableRecycleBin: self::flagOf($options, 'ENABLE_RECYCLE_BIN'),
			recycleBinForceUserId: self::identifierOf($options, 'RECYCLE_BIN_FORCE_USER_ID'),
			skipStatistics: (bool)($options['SKIP_STATISTICS'] ?? false),
			skipBindings: (bool)($options['SKIP_BINDINGS'] ?? false),
			skipCommunications: (bool)($options['SKIP_COMMUNICATIONS'] ?? false),
			skipFiles: (bool)($options['SKIP_FILES'] ?? false),
			skipUserActivitySync: (bool)($options['SKIP_USER_ACTIVITY_SYNC'] ?? false),
			skipTasks: ($options['SKIP_TASKS'] ?? null) === true,
			skipBeforeHandler: (bool)($options['SKIP_BEFORE_HANDLER'] ?? false),
			checkBindingsBeforeAutoComplete: (bool)($options['CHECK_BINDINGS_BEFORE_AUTO_COMPLETE'] ?? false),
			entrySource: $entrySource,
			registerHistoryEvent: $registerHistoryEvent,
			checkPermissions: $checkPermissions,
			userFieldValues: $userFieldValues,
			calendarInput: $calendarInput,
			incorrectMomentColumns: $incorrectMomentColumns,
			legacyOptions: $options,
			calledWithNoField: $calledWithNoField,
		);
	}

	/**
	 * The scope of the legacy context of a call, or null when the caller gave no context or gave something
	 * that is not one - which is what its only reader does with anything else, too.
	 *
	 * A scope legacy never had cannot come back out of a legacy context, and the scope of a background job
	 * covers two of the typed ones, so the way back is the non-interactive of those two. Only the automated
	 * origin is ever compared, so a scope this does not know behaves as an unnamed one; inventing a case for
	 * it would state something the caller never said.
	 */
	private static function scopeOf(mixed $context): ?Scope
	{
		if (!($context instanceof Context))
		{
			return null;
		}

		return match ($context->getScope())
		{
			Context::SCOPE_MANUAL => Scope::Manual,
			Context::SCOPE_REST => Scope::Rest,
			Context::SCOPE_AUTOMATION => Scope::Automation,
			Context::SCOPE_AI => Scope::Ai,
			Context::SCOPE_TASK => Scope::System,
			default => null,
		};
	}

	/**
	 * The moment the caller named, read the way its reader in the timeline reads it: as a local time of the
	 * user, converted with the settings of the time zone.
	 *
	 * A value that is no time at all becomes no value. The reader would raise on it, and it raises inside a
	 * step of the pipeline, where a failure is logged and the operation goes on; raising here instead would
	 * take down the whole operation before it started, which no legacy caller could have caused.
	 */
	private static function userTimeOf(mixed $value): ?DateTime
	{
		if ($value instanceof DateTime)
		{
			return $value;
		}

		if (!is_string($value) || $value === '')
		{
			return null;
		}

		try
		{
			return DateTime::createFromUserTime($value);
		}
		catch (ObjectException)
		{
			return null;
		}
	}

	private static function analyticsEventOf(mixed $value): ?AnalyticsEvent
	{
		return $value instanceof AnalyticsEvent ? $value : null;
	}

	/**
	 * @return array<mixed>|null
	 */
	private static function arrayOf(mixed $value): ?array
	{
		return is_array($value) ? $value : null;
	}

	/**
	 * Told apart from a value nobody gave, because the two are different statements even where they lead to
	 * the same outcome.
	 *
	 * @param array<string, mixed> $options
	 */
	private static function flagOf(array $options, string $key): ?bool
	{
		return isset($options[$key]) ? (bool)$options[$key] : null;
	}

	/**
	 * @param array<string, mixed> $options
	 */
	private static function identifierOf(array $options, string $key): ?int
	{
		return isset($options[$key]) ? (int)$options[$key] : null;
	}
}
