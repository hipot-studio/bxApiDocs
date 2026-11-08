<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepositoryInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Reads the activity back as it now stands and makes that the state of the run: row 17 of the owner table of an
 * update (crm_activity.php:712-716), the second of the steps an update is interrupted on.
 *
 * THE WHOLE SECOND HALF OF AN UPDATE RESTS ON THIS READ. "Write, re-read, then the effects" is the model of the
 * design (row D1 of the registry), and this is the re-read: every difference the rows below compute is the one
 * between the state loaded by row 1 and the state loaded here, and computing any of them from the fields the
 * caller gave is forbidden - the only exception being the predicate of the calendar of row 39, which by contract
 * gets the input. What the caller asked for and what the row now holds are not the same thing: a provider may
 * have changed a value on the way, the storage fills columns of its own, and a write that silently did nothing
 * leaves the row as it was. That last case is also the only place an update finds out its write failed - row 12
 * does not look at the answer of the storage, exactly as legacy does not.
 *
 * A MISSING ROW ENDS THE OPERATION. Legacy answers it with a plain false and performs none of its effects, so the
 * step is aborting - and unlike the interruption of row 1, this one happens AFTER a write, which is the one place
 * an update of V2 can leave the row of an activity written and its effects not performed (checklist item A8).
 * Reproduced as it is: an activity that disappeared between the write and the read is not a state anything below
 * could work on.
 *
 * WHAT THE MODEL OF THE RUN LOSES HERE, and it has to be said plainly: everything the steps above put into it and
 * the row does not hold. The parent resolved by row 8, the columns of the row a subscriber of row 11 changed, the
 * texts as they were given - all of them are replaced by what the table now says. Only the transit keeps its own
 * values (see below).
 *
 * WHAT THE RE-READ CANNOT ANSWER IS NOT DROPPED. Five fields of the model are held by no column of `b_crm_act`:
 * the bindings, the communications and the files live in tables of their own, the incoming channel of an activity
 * is a registry of its own, and the reminder offsets are a table of their own too. A re-read of the row says
 * nothing about any of them, so a replacement that took its word for it would erase what the operation named - and
 * with the bindings gone, row 18 would have nothing left to compare against the snapshot of row 4. They are
 * therefore carried over from the model the run had, which is where legacy has them as well: its re-read snapshot
 * is a second array, and the set of bindings of the operation is assigned into it by hand (:864).
 *
 * THE TRANSIT IS FILLED IN, NOT REPLACED. A column some step of the run put there is that step's answer and stays
 * - the owner of row 6, the chain of row 8 - while a column no step of the run touched is answered by the row that
 * has just been re-read. That is what gives the steps below the columns legacy reads off its re-read snapshot: the
 * type of the activity, which row 3 took out of the transit and row 45 asks for again; the entity a provider
 * associated with the activity; the event of the calendar; the pair of the reminder. The serialized fields are
 * deliberately left alone - legacy hands its providers the ones the operation carried, and merging the stored ones
 * into the run would hand them values legacy replaces with an empty array (:697-698, a line with no row of its own
 * in the owner table because V2 never serializes inside a run).
 *
 * THE TEXTS COME BACK UNESCAPED, and everything below this row reads them that way. The run escaped the emoji of
 * the subject and of the description for the write (row 10) and an update never puts them back; the storage
 * boundary, however, unescapes them on the way out, so past this row the model carries the texts as they were
 * given while the columns keep the escaped shape. Legacy has the opposite: its re-read is a plain fetch, so its
 * snapshot carries what the column holds. The difference belongs to the boundary rather than to this step - both
 * states of an update are read through it, so the differences computed between them are unaffected - but whoever
 * hands one of those texts to a subscriber, to a provider or to the timeline is looking at the unescaped shape
 * (row D14 of the registry of intentional deviations speaks about the escaped one).
 *
 * @internal
 */
final class ReloadCurrentState implements PipelineStepInterface
{
	/**
	 * There is no activity with that id any more. The message is the comment legacy answers its false with.
	 */
	public const ERROR_NOT_FOUND = 'CRM_ACTIVITY_NOT_FOUND';

	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	private readonly ActivityRepositoryInterface $repository;

	/**
	 * @param ActivityRepositoryInterface|null $repository the storage boundary of the activities; the one seam of
	 *        the step.
	 */
	public function __construct(?ActivityRepositoryInterface $repository = null)
	{
		$this->repository = $repository ?? new ActivityRepository();
	}

	public function getName(): string
	{
		return 'ReloadCurrentState';
	}

	/**
	 * Every update reads its row back. Legacy has no path around this.
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

		$state = $this->repository->getStateById($activityId);
		if ($state === null)
		{
			return $result->addError(new Error(
				"Could not find CrmActivity(ID = {$activityId})",
				self::ERROR_NOT_FOUND,
			));
		}

		$context->setActivity(self::withWhatTheRowCannotHold(
			$state[ActivityRepositoryInterface::STATE_ACTIVITY],
			$context->getActivity(),
		));
		self::fillInTheTransit($context->getTransit(), $state[ActivityRepositoryInterface::STATE_TRANSIT]);

		return $result;
	}

	/**
	 * An update over an activity that is not there any more performs none of its effects.
	 */
	public function isAbortingOnFailure(): bool
	{
		return true;
	}

	/**
	 * The five fields of the model no column of the table holds, taken from the state of the run: see the note of
	 * the class. They travel through the hydration path because none of them is a change anybody asked for - they
	 * are the answers the operation already had.
	 */
	private static function withWhatTheRowCannotHold(Activity $reread, Activity $ofTheRun): Activity
	{
		return $reread
			->internalSet(Activity::bindings, $ofTheRun->getBindings())
			->internalSet(Activity::communications, $ofTheRun->getCommunications())
			->internalSet(Activity::files, $ofTheRun->getFiles())
			->internalSet(Activity::isIncomingChannel, $ofTheRun->isIncomingChannel())
			->internalSet(Activity::pingOffsets, $ofTheRun->getPingOffsets())
		;
	}

	private static function fillInTheTransit(StorageFieldTransit $ofTheRun, StorageFieldTransit $ofTheRow): void
	{
		foreach ($ofTheRow->getValues() as $column => $value)
		{
			if (!$ofTheRun->has($column))
			{
				$ofTheRun->set($column, $value);
			}
		}
	}
}
