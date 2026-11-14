<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers;

use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Onboarding\Activation\ActivationStep;
use Bitrix\Mobile\Internal\Onboarding\Checkers\Gate\StepGateFactory;
use Bitrix\Mobile\Internal\Onboarding\Checkers\Gate\StepPushDateProvider;
use Bitrix\Mobile\Internal\Onboarding\Config\CampaignConfigProvider;
use Bitrix\Mobile\Internal\Onboarding\Services\OnboardingPushService;

class StepCheckerRegistry
{
	/** @var array<string, StepChecker> */
	private array $checkers = [];

	/**
	 * The registry the campaign document addresses: every step declared in code has
	 * its check registered here. The document only selects a registered check by id,
	 * it cannot introduce one.
	 *
	 * This is also where the gate of a gated step is wired: the condition comes from the
	 * document, the implementation from StepGateFactory. Without a document the gates
	 * keep their code defaults.
	 *
	 * $pushDates is the campaign state the gates are judged against - the source of the
	 * "when did we ask" dates. The caller passes the storage it works through itself, so a
	 * pass that preloaded the state of its selection has the gates read that state instead
	 * of a second storage going to the user option per user.
	 */
	public static function createDefault(
		?CampaignConfigProvider $campaignConfig = null,
		?StepPushDateProvider $pushDates = null,
	): self
	{
		$messageGate = StepGateFactory::create($campaignConfig?->getGateCondition(ActivationStep::MESSAGE));

		$registry = new self();
		$registry->register(ActivationStep::CREATE_TASK, new TaskCreatedChecker());
		$registry->register(ActivationStep::CREATE_DEAL, new DealCreatedChecker());
		$registry->register(ActivationStep::INVITE, new InvitationSentChecker());
		$registry->register(
			ActivationStep::MESSAGE,
			new ColleagueMessageChecker($messageGate, $pushDates ?? new OnboardingPushService()),
		);
		$registry->register(ActivationStep::WEB, new WebLoginChecker());

		return $registry;
	}

	public function register(ActivationStep $step, StepChecker $checker): void
	{
		$this->checkers[$step->value] = $checker;
	}

	public function hasChecker(ActivationStep $step): bool
	{
		return isset($this->checkers[$step->value]);
	}

	public function getChecker(ActivationStep $step): StepChecker
	{
		if (!$this->hasChecker($step))
		{
			throw new SystemException("No StepChecker registered for step '{$step->value}'");
		}

		return $this->checkers[$step->value];
	}

	/**
	 * Lets every check that can read a whole selection at once do so, before the pass starts
	 * asking about single users. A pass resolves the steps of every candidate until the first
	 * unclosed one, so without this each candidate costs a query per step it has closed.
	 *
	 * The registry is asked rather than the pass: whether reading a selection at once pays off
	 * is the business of each check. The step of the task is the one that stays per-user - its
	 * query already lands on a narrow index, so a grouped read would trade a one-row lookup for
	 * a scan of the date range of the campaign; the rest would re-read that range per candidate
	 * and read their selection once instead.
	 *
	 * @param int[] $userIds the candidates of the pass
	 */
	public function preload(array $userIds, DateTime $sinceDate): void
	{
		if ($userIds === [])
		{
			return;
		}

		foreach ($this->checkers as $checker)
		{
			if ($checker instanceof BulkStepChecker)
			{
				$checker->preload($userIds, $sinceDate);
			}
		}
	}
}
