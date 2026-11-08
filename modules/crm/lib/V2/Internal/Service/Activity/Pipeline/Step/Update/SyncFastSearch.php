<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\Activity\FastSearch\Sync\ActivityChangeSet;
use Bitrix\Crm\Activity\FastSearch\Sync\Monitor;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityFieldMap;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmActivityType;

/**
 * Brings the denormalized table the interface finds the nearest activities of an object by into line with the
 * update: row 36 of the owner table STEP-UPDATE (crm_activity.php:972-974), checklist item C7.10.
 *
 * THE ROW IS UNCONDITIONAL AND EVERY GATE IS INSIDE THE MONITOR. It is handed a change of two sides and decides for
 * itself what to do with it: an activity that has become one the table does not keep is removed from it, one that
 * still belongs is written again, and an activity of a provider taking no part in the search of activities never
 * reaches the table at all. Lifting any of those questions up here would be a second copy of a rule the monitor
 * owns - and one of them cannot be asked here in the first place, because "was it in the table before" is a
 * comparison of the two sides.
 *
 * BOTH SIDES ARE STATES. The previous one is the state read before the write, the current one the re-read state;
 * neither is the input, exactly as row D1 of the registry requires. The builder of the monitor derives what it needs
 * off each side on its own, so both arrays carry the same keys: the identifier and the person responsible, which it
 * reads unguarded; the moment of creation and the deadline, which it defaults where they are absent; the marker of
 * the incoming channel, which decides the kind of the row; and the three columns identifying the provider plus the
 * direction, out of which the code of the type is composed.
 *
 * THE DEADLINE OF EACH SIDE IS THE VALUE ITS COLUMN CARRIES, THE SENTINEL INCLUDED
 * ({@see DeadlineSentinelGuard::columnOf()}): legacy hands the builder the two rows it fetched, and the builder
 * falls back to that very date for a side that carries none - so the sentinel and an absent deadline are one answer
 * to it, and the column is handed over because it is the shape the state really has.
 *
 * THE TEXTS OF THE ACTIVITY TAKE NO PART HERE, which is worth saying because row EMOJI-DECODED-AFTER-RELOAD of the
 * registry of intentional deviations names this row among the consumers seeing them unescaped. Nothing of the change
 * is a text: the table keeps moments, a person, a completion, a code of a type and a kind. The deviation, whatever
 * its consequences elsewhere, has none here.
 *
 * @internal
 */
final class SyncFastSearch implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. The row of
	 * the table is keyed by the activity, so there is nothing to write.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	/**
	 * The run reached this step without the state the activity was in. That state is one side of the change, and
	 * every decision of the monitor is a comparison of the two.
	 */
	public const ERROR_PREVIOUS_STATE_NOT_LOADED = 'CRM_ACTIVITY_PREVIOUS_STATE_NOT_LOADED';

	private const COLUMN_TYPE_ID = 'TYPE_ID';

	public function getName(): string
	{
		return 'SyncFastSearch';
	}

	/**
	 * Every update is handed to the monitor. See the note of the class on why none of its gates is asked here.
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

		if (!$context->hasPreviousState())
		{
			return $result->addError(new Error(
				'The previous state of the activity was not loaded.',
				self::ERROR_PREVIOUS_STATE_NOT_LOADED,
			));
		}

		Monitor::getInstance()->onActivityUpdate(ActivityChangeSet::build(
			self::legacyFieldsOf($context->getPreviousActivity(), $context->getPreviousTransit(), $activityId),
			self::legacyFieldsOf($context->getActivity(), $context->getTransit(), $activityId),
		));

		return $result;
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
	 * One state of the activity in the legacy form the builder of the monitor reads it in.
	 *
	 * @return array<string, mixed>
	 */
	private static function legacyFieldsOf(Activity $activity, StorageFieldTransit $transit, int $activityId): array
	{
		$fields = [
			'ID' => $activityId,
			'RESPONSIBLE_ID' => (int)$activity->getResponsibleId(),
			'COMPLETED' => $activity->isCompleted() === true ? 'Y' : 'N',
			'IS_INCOMING_CHANNEL' => $activity->isIncomingChannel() === true ? 'Y' : 'N',
			'TYPE_ID' => $transit->has(self::COLUMN_TYPE_ID)
				? (int)$transit->get(self::COLUMN_TYPE_ID)
				: CCrmActivityType::Provider,
			'DIRECTION' => (int)ActivityFieldMap::toColumnValue('DIRECTION', $activity->getDirection()),
			'PROVIDER_ID' => (string)$activity->getProviderId(),
			'PROVIDER_TYPE_ID' => (string)$activity->getProviderTypeId(),
			DeadlineSentinelGuard::COLUMN => DeadlineSentinelGuard::columnOf($activity),
		];

		if ($activity->getAuthorId() !== null)
		{
			$fields['AUTHOR_ID'] = $activity->getAuthorId();
		}

		if ($activity->getCreatedTime() !== null)
		{
			$fields['CREATED'] = $activity->getCreatedTime();
		}

		return $fields;
	}
}
