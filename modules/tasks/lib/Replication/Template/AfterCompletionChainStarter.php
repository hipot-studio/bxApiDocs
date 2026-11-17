<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Replication\Template;

use Bitrix\Main\Config\Feature;
use Bitrix\Tasks\Feature\RegularTaskTypesFeature;
use Bitrix\Tasks\Internals\Log\LogFacade;
use Bitrix\Tasks\Replication\EventHandler\OnTaskCompleted\AfterCompletionReplicationHandler;
use Bitrix\Tasks\Replication\Repository\TemplateRepository;
use Bitrix\Tasks\Replication\Template\Enum\RegularityType;
use Throwable;

/**
 * Bootstraps the after_completion chain when a regular template is created or updated from the section
 * without an active instance (PRD Q-INT-001 / AC-S-035).
 *
 * The event-driven type has no schedule, so — unlike schedule / deadline, whose recurrent agent posts
 * their first instance on its own — an after_completion rule created as "template only" would stay
 * inert: there is no instance whose completion could arm the next one. To make the type functional the
 * chain is started here by arming the same one-shot the completion handler uses ({@see AfterCompletionAgent}),
 * so the first instance is created — and written to b_tasks_syslog — through the one existing production
 * path, exactly as the recurrent types produce theirs when their agent fires.
 *
 * Deferring the actual creation to the agent (rather than producing inline) keeps the template write a
 * light operation and gives the fire-time code a single, already-tested place that (re)checks the type,
 * the pause flag and the tariff before it creates a task.
 */
final class AfterCompletionChainStarter
{
	/**
	 * Arms the bootstrap one-shot iff the template is an after_completion rule that has no instance yet.
	 * A no-op for every other case, so it is safe to call unconditionally from the replication arming step.
	 */
	public function startIfNeeded(int $templateId): void
	{
		if ($templateId <= 0)
		{
			return;
		}

		// Flag off ≡ today's behavior: the type is unavailable, there is nothing to bootstrap.
		if (!Feature::isEnabled(RegularTaskTypesFeature::class))
		{
			return;
		}

		try
		{
			$template = TemplateRepository::getInstance($templateId)->getEntity();
			if ($template === null)
			{
				return;
			}

			// Only the event-driven type needs a bootstrap: schedule / deadline arm a recurrent agent that
			// posts their first instance on its own.
			if (RegularityType::fromValue($template->getReplicateType()) !== RegularityType::AfterCompletion)
			{
				return;
			}

			// Pause (REPLICATE='N') → do not start the chain.
			if (!$template->getReplicate())
			{
				return;
			}

			$transitionService = new AfterCompletionTransitionService();
			$tailTaskId = $transitionService->getTailTaskId($template);

			// A template-only rule has no predecessor and is bootstrapped immediately. An existing incomplete
			// tail is already the live chain instance. A completed tail means the completion happened while the
			// rule was paused or its one-shot was lost; schedule exactly that durable transition on resume/recovery.
			if (
				$tailTaskId === 0
				&& (int)$template->getTparamReplicationCount() > 0
			)
			{
				return;
			}

			AfterCompletionReplicationHandler::scheduleNext($templateId, $tailTaskId);
		}
		catch (Throwable $throwable)
		{
			// A failed bootstrap must never break the template add / update itself.
			LogFacade::logThrowable($throwable);
		}
	}
}
