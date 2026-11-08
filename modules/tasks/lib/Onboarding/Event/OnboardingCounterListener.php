<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Onboarding\Event;

use Bitrix\Main\EventResult;
use Bitrix\Tasks\Internals\Log\LogFacade;
use Bitrix\Tasks\Onboarding\Counter\OnboardingCounterService;
use Bitrix\Tasks\Onboarding\Counter\TaskCreationSource;
use Bitrix\Tasks\Onboarding\OnboardingFeature;
use Bitrix\Tasks\Promotion\NewUserSegment;
use Throwable;

/**
 * Increments per-user onboarding trigger counters on task creation (ALG-01).
 *
 * Gated by TaskCreationSource::isInteractive(): only web and mobile creations
 * are counted; public REST and system/agent creations are ignored. System
 * creations that run on regular HTTP hits (agent replication, bizproc robots)
 * are cut off by the FROM_AGENT/FROM_WORKFLOW event markers and by the
 * FORKED_BY_TEMPLATE_ID field for replication branches that do not mark
 * themselves (AC-019, ERR-006). Counters are kept for segment users only.
 */
final class OnboardingCounterListener extends AbstractEventListener
{
	private OnboardingCounterService $counterService;
	private NewUserSegment $newUserSegment;

	public function onTaskAdd(int $taskId, array $fields, array $parameters = []): EventResult
	{
		$eventResult = new EventResult(EventResult::SUCCESS);

		// Any failure here - the interactivity gate, the segment read from the DB,
		// or the counter write itself - must neither break task creation nor abort
		// sibling OnTaskAdd handlers. RunAddEvent only wraps the handler loop in a
		// catch for Exception, so a non-Exception Throwable, or an exception raised
		// outside registerTaskCreation, would still escape; guard the whole body,
		// log any failure and return SUCCESS.
		try
		{
			// Early exit: count interactive (web + mobile) creations only.
			if (!TaskCreationSource::isInteractive())
			{
				return $eventResult;
			}

			// Agent replication and bizproc robots run on regular HTTP hits and pass
			// the gate above; the add pipeline marks them explicitly.
			if (($parameters['FROM_AGENT'] ?? false) || ($parameters['FROM_WORKFLOW'] ?? false))
			{
				return $eventResult;
			}

			// Template-forked tasks belong to replication flows even when the
			// producing branch does not mark itself (multitask sub-tasks, legacy
			// replicator); interactive V2 creation from a template does not set it.
			if (!empty($fields['FORKED_BY_TEMPLATE_ID']))
			{
				return $eventResult;
			}

			$createdBy = (int)($fields['CREATED_BY'] ?? 0);

			// ALG-01: both the counters and the isSelf predicate below belong to the
			// acting user (event USER_ID); creating a task on behalf of someone else
			// must not mutate that person's state. They are computed against $userId
			// (not CREATED_BY) so writes and isSelf stay in sync when USER_ID differs
			// from CREATED_BY.
			$userId = (int)($parameters['USER_ID'] ?? 0);
			if ($userId <= 0)
			{
				$userId = $createdBy;
			}

			if ($userId <= 0)
			{
				return $eventResult;
			}

			if (!OnboardingFeature::isAvailable())
			{
				return $eventResult;
			}

			// Counters feed segment-only promotions; skipping non-segment users saves
			// CUserOptions reads/writes in the hot task creation path. The segment
			// predicate is memoized per hit and hits the DB only for new portals.
			if (!$this->newUserSegment->isNewUser($userId))
			{
				return $eventResult;
			}

			$responsibleId = (int)($fields['RESPONSIBLE_ID'] ?? 0);

			$hasDeadline = !empty($fields['DEADLINE']);
			// SC-003 marker: whether the user set the deadline manually. The single
			// OnTaskAdd emission point (RunAddEvent) always sends it: the explicit
			// client marker when the V2 form reported it, otherwise the PrepareFields
			// heuristic captured before the pipeline could substitute the user's
			// default auto deadline (legacy CTaskItem/mobile/REST reach it through the
			// Control\Task bridge). The DEADLINE fallback below only guards against a
			// third-party emitter firing the event without the marker.
			$hasManualDeadline = array_key_exists('MANUAL_DEADLINE', $parameters)
				? (bool)$parameters['MANUAL_DEADLINE']
				: $hasDeadline;
			// Judged against the same acting user as the counters ($userId), so the
			// write target and the isSelf flag never diverge when the task is created
			// on behalf of someone else (USER_ID != CREATED_BY).
			$isSelf = $responsibleId === $userId;

			$this->counterService->registerTaskCreation($userId, $hasDeadline, $isSelf, $hasManualDeadline);
		}
		catch (Throwable $e)
		{
			LogFacade::logThrowable($e);
		}

		return $eventResult;
	}

	protected function init(): void
	{
		parent::init();

		$this->counterService = new OnboardingCounterService();
		$this->newUserSegment = new NewUserSegment();
	}
}
