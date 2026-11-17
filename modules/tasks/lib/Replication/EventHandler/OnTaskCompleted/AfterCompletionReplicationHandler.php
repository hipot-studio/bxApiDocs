<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Replication\EventHandler\OnTaskCompleted;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Feature;
use Bitrix\Tasks\Feature\RegularTaskTypesFeature;
use Bitrix\Tasks\Internals\Log\LogFacade;
use Bitrix\Tasks\Internals\Task\Template\TemplateObject;
use Bitrix\Tasks\Internals\Task\TemplateTable;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\Replication\Agent\AfterCompletionAgent;
use Bitrix\Tasks\Replication\Repository\TemplateRepository;
use Bitrix\Tasks\Replication\Template\AfterCompletionTransitionService;
use Bitrix\Tasks\Replication\Template\Enum\OffsetDayType;
use Bitrix\Tasks\Replication\Template\Enum\RegularityType;
use Bitrix\Tasks\Replication\Template\Repetition\Parameter\ReplicateParameter;
use Bitrix\Tasks\Replication\Template\Time\Service\AfterCompletionRunTimeService;
use Bitrix\Tasks\UI;
use Bitrix\Tasks\Util\User;
use Bitrix\Tasks\V2\Internal\Entity\Task;

/**
 * Event-driven posting for the after_completion regular type (ALG-02).
 *
 * Reacts to the previous instance transitioning into "Completed" (before ≠ Completed → after =
 * Completed) and arms a one-shot agent (AGENT-01) for the computed fire time. Inert when the release
 * flag is off and for schedule / deadline. Only the trigger check happens inside the completion
 * request; arming is deferred to a background job, and the instance itself is created by
 * {@see AfterCompletionAgent} - so task completion keeps NFR P95 < 500ms.
 */
final class AfterCompletionReplicationHandler
{
	public static function onTaskUpdate(Task $before, Task $after): void
	{
		// This runs inside task completion (onTaskUpdateInternal): a failure to arm the next instance
		// must never break the completion itself (NFR P95 / stability). Swallow and log.
		try
		{
			self::handle($before, $after);
		}
		catch (\Throwable $throwable)
		{
			LogFacade::logThrowable($throwable);
		}
	}

	private static function handle(Task $before, Task $after): void
	{
		// Only the transition into "Completed" is a trigger ("Ожидает контроля" is not — AC-T-009).
		if ($after->status !== Task\Status::Completed || $after->status === $before->status)
		{
			return;
		}

		// Flag off ≡ today's behavior.
		if (!Feature::isEnabled(RegularTaskTypesFeature::class))
		{
			return;
		}

		$taskId = (int)$after->getId();
		$hydratedTemplateId = (int)($after->forkedByTemplate?->id ?? 0);
		if ($taskId <= 0 && $hydratedTemplateId <= 0)
		{
			return;
		}
		$completionTs = (int)($after->closedTs ?? $after->statusChangedTs ?? 0);
		if ($completionTs <= 0)
		{
			$completionTs = time();
		}

		// Everything past the trigger - the template lookup, the calendar math, the agent
		// search and its write - runs after the response is sent, so completion keeps NFR
		// P95 < 500ms and a mass completion does not pay for it per task. The one-shot stays
		// deduplicated by AfterCompletionAgent::exists().
		Application::getInstance()->addBackgroundJob(
			static function () use ($hydratedTemplateId, $taskId, $completionTs): void {
				try
				{
					$templateId = $hydratedTemplateId > 0 ? $hydratedTemplateId : self::resolveTemplateIdByTask($taskId);
					if ($templateId > 0)
					{
						self::scheduleNext($templateId, $taskId, $completionTs);
					}
				}
				catch (\Throwable $throwable)
				{
					LogFacade::logThrowable($throwable);
				}
			},
		);
	}

	public static function scheduleNext(
		int $templateId,
		int $predecessorTaskId,
		?int $completionTs = null,
	): void
	{
		$repository = TemplateRepository::getInstance($templateId);
		$repository->drop();
		$template = $repository->getEntity();
		if ($template === null)
		{
			return;
		}

		if (RegularityType::fromValue($template->getReplicateType()) !== RegularityType::AfterCompletion)
		{
			return;
		}

		// Pause overrides the trigger (AC-S-074): REPLICATE != 'Y' → do not post, no backfill on resume.
		if (!$template->getReplicate())
		{
			return;
		}

		$runAtTs = self::computeRunTime($template, $completionTs ?? time());

		// Stop conditions REPEAT_TILL / TIMES end the chain (ERR-T-003).
		$transitionService = new AfterCompletionTransitionService();
		if ($transitionService->isChainFinished($template, $runAtTs))
		{
			return;
		}

		if (!$transitionService->isReady($template, $predecessorTaskId))
		{
			return;
		}

		if (AfterCompletionAgent::exists($templateId, $predecessorTaskId))
		{
			return;
		}

		AfterCompletionAgent::arm($templateId, $predecessorTaskId, $runAtTs);
	}

	/**
	 * The completed task's regular template, via either of the two regularity linkages (as the list
	 * filter does — ADR §8):
	 *  1. instance: b_tasks.FORKED_BY_TEMPLATE_ID → template.ID (a forked instance);
	 *  2. source: b_tasks_template.TASK_ID → task.ID (the task the rule was created from — Part I from
	 *     the card, where the source instance keeps FORKED_BY_TEMPLATE_ID = 0).
	 *
	 * EVENT-01 names only the FORKED_BY_TEMPLATE_ID scalar (the ?Template relation may be un-hydrated
	 * in the update payload); the source linkage is added so completing the first (card) instance
	 * actually starts the chain. Type/REPLICATE gating is applied by the caller.
	 */
	private static function resolveTemplateIdByTask(int $taskId): int
	{
		$row = TaskTable::getList([
			'select' => ['FORKED_BY_TEMPLATE_ID'],
			'filter' => ['=ID' => $taskId],
			'limit' => 1,
		])->fetch();

		$templateId = (int)($row['FORKED_BY_TEMPLATE_ID'] ?? 0);
		if ($templateId > 0)
		{
			return $templateId;
		}

		return self::resolveSourceTemplateId($taskId);
	}

	private static function resolveSourceTemplateId(int $taskId): int
	{
		$row = TemplateTable::getList([
			'select' => ['ID'],
			'filter' => ['=TASK_ID' => $taskId, '=REPLICATE' => 'Y', '=ZOMBIE' => 'N'],
			'order' => ['ID' => 'ASC'],
			'limit' => 1,
		])->fetch();

		return (int)($row['ID'] ?? 0);
	}

	/**
	 * Fire time of the one-shot. Resolves the author-timezone-dependent inputs (preferred HH:MM,
	 * delay, day-type) here — as ExecutionService does for the schedule strategy — and delegates
	 * the pure day arithmetic to {@see AfterCompletionRunTimeService}.
	 */
	private static function computeRunTime(TemplateObject $template, int $completionTs): int
	{
		$parameter = new ReplicateParameter(TemplateRepository::getInstance((int)$template->getId()));
		$creatorTimeZoneOffset = self::resolveCreatorTimeZoneOffset($parameter, $template);

		// Preferred HH:MM as server seconds-of-day — mirrors ExecutionService::assembleExecutionContext.
		$preferredSecondsOfDay = 0;
		$time = (string)$parameter->get('TIME');
		if ($time !== '')
		{
			$preferredSecondsOfDay = (int)UI::parseTimeAmount(
				date('H:i', strtotime($time) - $creatorTimeZoneOffset),
				'HH:MI',
			);
		}

		// Day-kind follows the unified working-days flag (WORKDAY_ONLY); OFFSET_DAY_TYPE is a legacy
		// fallback only for templates that never stored WORKDAY_ONLY (not re-saved yet).
		$workdayOnly = $parameter->get('WORKDAY_ONLY');
		$offsetDayType = $workdayOnly !== null
			? OffsetDayType::fromWorkdayFlag($workdayOnly)
			: OffsetDayType::fromValue($parameter->get('OFFSET_DAY_TYPE'));

		return (new AfterCompletionRunTimeService())->calculate(
			$completionTs,
			(int)$parameter->get('COMPLETION_DELAY'),
			$preferredSecondsOfDay,
			$offsetDayType,
		);
	}

	/**
	 * Creator timezone offset for the time math, mirroring ExecutionService::assembleExecutionContext:
	 * new templates persist TIMEZONE_OFFSET = 0 (the preferred time is already server-normalised), so
	 * only legacy templates without the parameter fall back to the creator's live offset. Reading the
	 * live offset unconditionally double-shifts the fire time — and User::getTimeZoneOffset() even
	 * varies with the request context (\CTimeZone), which is exactly why the schedule strategy prefers
	 * the stored parameter.
	 */
	private static function resolveCreatorTimeZoneOffset(ReplicateParameter $parameter, TemplateObject $template): int
	{
		$timeZoneOffset = $parameter->get('TIMEZONE_OFFSET');

		return (int)($timeZoneOffset ?? User::getTimeZoneOffset((int)$template->getCreatedBy()));
	}

}
