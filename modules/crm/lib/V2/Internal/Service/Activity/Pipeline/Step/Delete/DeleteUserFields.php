<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete;

use Bitrix\Crm\Activity\IncomingChannel;
use Bitrix\Crm\Activity\Provider\Eventable\PingOffset;
use Bitrix\Crm\Activity\Provider\Eventable\PingQueue;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmActivity;
use CUserTypeManager;

/**
 * Removes what is registered against the activity outside its own tables: row 9 of the owner table STEP-DELETE
 * (crm_activity.php:1367-1370).
 *
 * FOUR REGISTRIES, ONE ROW, AND LEGACY HAS THEM IN ONE PLACE TOO. The values of the user fields of the activity, the
 * mark of an unanswered incoming channel, the reminder offsets and the queue of the reminders built out of them: none
 * of the four is a column of an activity, all four are keyed by its identifier, and all four are dropped together
 * right after the row is gone. The user fields go first, as they do in legacy.
 *
 * THE SAME THREE REGISTRATIONS ARE ALSO REMOVED BY ROW 5, AND THAT IS NOT A DUPLICATE. Legacy removes them in two
 * places, and the two are on paths that exclude each other: row 5 removes them where the provider reported the
 * transfer to the recycle bin as a deletion of its own and the run ends there, this row removes them on the ordinary
 * path where the row of the activity is erased. A run performs one of the two, never both.
 *
 * THE USER FIELDS ARE DROPPED WITHOUT BEING READ. The manager is told the type of the entity and the identifier and
 * removes every value stored against it, files included; nothing about the values reaches this step, which is why the
 * carrier the write path is still waiting for
 * ({@see \Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\SaveUserFields}) is of no concern here.
 *
 * @internal
 */
final class DeleteUserFields implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. All four
	 * registries are keyed by the activity, so there is nothing to remove.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	private ?CUserTypeManager $userFieldManager;

	/**
	 * @param CUserTypeManager|null $userFieldManager the manager of the user fields of the portal; the one seam of
	 *        the step. The global instance by default, resolved when the step runs rather than when it is declared.
	 */
	public function __construct(?CUserTypeManager $userFieldManager = null)
	{
		$this->userFieldManager = $userFieldManager;
	}

	public function getName(): string
	{
		return 'DeleteUserFields';
	}

	/**
	 * Every delete that erased its row cleans up after it. Legacy has no path around this.
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

		$this->manager()->Delete(CCrmActivity::UF_ENTITY_TYPE, $activityId);

		IncomingChannel::getInstance()->unregister($activityId);
		PingOffset::getInstance()->unregister($activityId);
		PingQueue::getInstance()->unregister($activityId);

		return $result;
	}

	/**
	 * The activity is already gone by now: legacy checks nothing here and carries on through the rows below.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	private function manager(): CUserTypeManager
	{
		$this->userFieldManager ??= $GLOBALS['USER_FIELD_MANAGER'];

		return $this->userFieldManager;
	}
}
