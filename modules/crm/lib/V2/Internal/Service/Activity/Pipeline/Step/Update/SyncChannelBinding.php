<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\Integration\Channel\ActivityChannelBinding;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityFieldMap;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Brings the records of the external channels of the activity up to date with its new state: row 30 of the owner
 * table STEP-UPDATE (crm_activity.php:870).
 *
 * WHAT A CHANNEL BINDING IS AND WHY ONLY AN UPDATE HAS THIS ROW. An activity that came in through a channel of its
 * own - a telephony line, an open line, a form, an external channel - is registered against that channel by
 * whoever brought it in, and the record of the registration keeps a copy of two values of the activity: the person
 * responsible and the completion. Nothing registers a channel during a write of the activity, which is why an add
 * has no such row; an update, however, can move either of the two copied values, and this row is where the copies
 * are brought back into line. An activity of no channel has no binding at all and the row does nothing - which is
 * the case for every activity of the pilot.
 *
 * THE ROW IS UNCONDITIONAL AND EVERY GATE IS INSIDE. Legacy asks nothing here (:870): the bindings of the activity
 * are read out of the registry, a binding of an undefined kind is skipped, and the record of a defined one is
 * rewritten only where one of the two copied values really moved - the comparison is the aggregator's own
 * (`ActivityChannelStatisticEntry::register`) and a copy of it here would be a second answer. Asking the set of
 * changes instead would be a third: the set says what the row of the activity did, not what the record of a
 * channel holds, and those two part company for any record written before this operation.
 *
 * NO RECORD IS ACTUALLY REWRITTEN TODAY, AND THAT IS PARITY RATHER THAN AN OMISSION. The predicate legacy skips a
 * binding by - `ChannelType::isDefined()` - compares the identifier of the kind, an integer, against the constant
 * of a NAME ('EMAIL'); under PHP 8 an integer compared with a string that is no number is cast to a string, so the
 * comparison reads '8' >= 'EMAIL' and answers false for every kind of channel there is. The synchronization of
 * legacy is therefore a read of the registry and nothing more, and so is this row. The defect belongs to that
 * predicate and to every caller of it, not to the write path of an activity; repairing it here would make V2 write
 * records legacy does not.
 *
 * THE MARKER OF THE INCOMING CHANNEL TAKES NO PART, however close the two notions sound. That marker is a registry
 * of its own, kept by the rows above; a channel binding is a different registry with a different owner, and this
 * row neither reads nor writes the marker.
 *
 * WHAT IS HANDED OVER IS THE RE-READ STATE, in the legacy shape the record is written out of: the completion and
 * the person responsible, which the record copies, the direction and the moment of creation, which date it. The
 * moment travels as the text of the site, the shape the record parses; a state that carries none leaves the key
 * out, and the record then dates itself by the current day - which is what legacy arrives at through its own
 * fallback.
 *
 * @internal
 */
final class SyncChannelBinding implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. The
	 * bindings of a channel are keyed by the activity, so there is nothing to synchronize.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	public function getName(): string
	{
		return 'SyncChannelBinding';
	}

	/**
	 * Every update asks the registry. See the note of the class on why none of the gates below it is asked here.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return $result->addError(new Error('The activity has no id.', self::ERROR_NO_ID));
		}

		ActivityChannelBinding::synchronize($activityId, self::legacyFieldsOf($context));

		return $result;
	}

	/**
	 * A record of a channel that could not be brought into line does not end an update: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The re-read state in the legacy form the record of a channel is written out of.
	 *
	 * @return array<string, mixed>
	 */
	private static function legacyFieldsOf(PipelineContext $context): array
	{
		$activity = $context->getActivity();

		$fields = [
			'COMPLETED' => $activity->isCompleted() === true ? 'Y' : 'N',
			'RESPONSIBLE_ID' => (int)$activity->getResponsibleId(),
			'DIRECTION' => (int)ActivityFieldMap::toColumnValue('DIRECTION', $activity->getDirection()),
		];

		$created = $activity->getCreatedTime();
		if ($created !== null)
		{
			$fields['CREATED'] = (string)$created;
		}

		return $fields;
	}
}
