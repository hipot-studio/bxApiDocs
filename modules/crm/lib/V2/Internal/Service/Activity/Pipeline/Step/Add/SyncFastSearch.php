<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\Activity\FastSearch\Sync\ActivityChangeSet;
use Bitrix\Crm\Activity\FastSearch\Sync\Monitor;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityFieldMap;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmActivityType;

/**
 * Puts the activity into the denormalized table the interface finds the nearest activities of an object by: step 27
 * of the owner table of an add (crm_activity.php:350-352), checklist item C7.10.
 *
 * THE STEP IS UNCONDITIONAL AND EVERY GATE IS INSIDE THE MONITOR. Legacy hands it a change on every add, and the
 * monitor decides for itself whether the activity belongs in the table: an activity whose provider does not take
 * part in the search of activities at all, and one of a kind the table does not know, are passed over. Lifting
 * either question up here would be a second copy of a rule the monitor owns.
 *
 * THE CHANGE HAS NO "BEFORE" SIDE, which is what makes it an add: the builder of the monitor is given null for the
 * previous state and the values of the new one. It reads the provider out of the identifier of the provider itself,
 * so the array has to name the provider, its type and the legacy kind of the activity - the code the table stores
 * is composed out of all three plus the direction.
 *
 * WHAT THE ARRAY CARRIES, AND WHY EVERY ONE OF IT IS THERE. The identifier and the person responsible are read
 * unguarded by the builder, so they are always carried; the moment of creation and the deadline are defaulted by it
 * where they are absent, and both of those defaults are the answers legacy arrives with - an activity of an add
 * carries no creation time of its own unless the caller preserved one, and the sentinel of "no deadline" is what
 * the builder falls back to anyway. The marker of an incoming channel decides the kind of the row.
 *
 * @internal
 */
final class SyncFastSearch implements PipelineStepInterface
{
	/**
	 * The row of the table is keyed by the activity, so there is nothing to write before it exists. Legacy cannot
	 * reach this state: it interrupts an add on a failed insert.
	 */
	public const ERROR_NOT_STORED = 'CRM_ACTIVITY_FAST_SEARCH_WITHOUT_ACTIVITY';

	private const COLUMN_TYPE_ID = 'TYPE_ID';

	public function getName(): string
	{
		return 'SyncFastSearch';
	}

	/**
	 * Every add is handed to the monitor. See the note of the class on why none of its gates is asked here.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return (new Result())->addError(new Error('The activity has not been stored.', self::ERROR_NOT_STORED));
		}

		Monitor::getInstance()->onActivityAdd(
			ActivityChangeSet::build(null, self::legacyFieldsOf($context, $activityId)),
		);

		return new Result();
	}

	/**
	 * An activity that did not reach the table of the fast search is stored all the same: legacy checks nothing
	 * here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The activity in the legacy form the builder of the monitor reads it in.
	 *
	 * @return array<string, mixed>
	 */
	private static function legacyFieldsOf(PipelineContext $context, int $activityId): array
	{
		$activity = $context->getActivity();
		$transit = $context->getTransit();

		$fields = [
			'ID' => $activityId,
			'RESPONSIBLE_ID' => (int)$activity->getResponsibleId(),
			'COMPLETED' => $activity->isCompleted() === true ? 'Y' : 'N',
			'IS_INCOMING_CHANNEL' => $activity->isIncomingChannel() === true ? 'Y' : 'N',
			'TYPE_ID' => self::activityTypeOf($transit),
			'DIRECTION' => (int)ActivityFieldMap::toColumnValue('DIRECTION', $activity->getDirection()),
			'PROVIDER_ID' => (string)$activity->getProviderId(),
			'PROVIDER_TYPE_ID' => (string)$activity->getProviderTypeId(),
		];

		if ($activity->getAuthorId() !== null)
		{
			$fields['AUTHOR_ID'] = $activity->getAuthorId();
		}

		if ($activity->getCreatedTime() !== null)
		{
			$fields['CREATED'] = $activity->getCreatedTime();
		}

		$deadline = DeadlineSentinelGuard::readFrom($transit);
		if ($deadline !== null)
		{
			$fields[DeadlineSentinelGuard::COLUMN] = $deadline;
		}

		return $fields;
	}

	private static function activityTypeOf(StorageFieldTransit $transit): int
	{
		return $transit->has(self::COLUMN_TYPE_ID)
			? (int)$transit->get(self::COLUMN_TYPE_ID)
			: CCrmActivityType::Provider
		;
	}
}
