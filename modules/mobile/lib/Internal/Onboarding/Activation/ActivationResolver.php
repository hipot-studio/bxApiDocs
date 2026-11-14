<?php

namespace Bitrix\Mobile\Internal\Onboarding\Activation;

use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Onboarding\Checkers\QualificationChecker;
use Bitrix\Mobile\Internal\Onboarding\Checkers\StepCheckerRegistry;
use Bitrix\Mobile\Internal\Onboarding\Config\CampaignConfigProvider;
use Bitrix\Mobile\Internal\Onboarding\PushType;

/**
 * The activation engine: picks the path by the tools chosen in the qualification
 * wizard, checks the steps of that path through the checker registry and returns the
 * push type of the first matching rule. Null means every step of the path is
 * completed and no push is sent.
 *
 * Depends on the configuration only through CampaignConfigProvider. The state is
 * recomputed on every call and never cached; a step is checked only when a rule asks
 * about it, so neither the steps of other paths nor the steps behind the first
 * uncompleted one spend a cross-module query.
 */
class ActivationResolver
{
	/**
	 * The path comes from the answers of the qualification wizard, which belong to the
	 * portal - one path for every user of the pass.
	 */
	private ?ActivationPath $path = null;

	public function __construct(
		private readonly CampaignConfigProvider $configProvider,
		private readonly StepCheckerRegistry $registry,
		private readonly QualificationChecker $qualificationChecker,
	)
	{
	}

	/**
	 * $portalCreatedAt is the single lower bound of the counted facts, shared by every checker.
	 * It is not a demo cut-off - see StepChecker::isCompleted().
	 */
	public function resolvePush(int $userId, DateTime $portalCreatedAt): ?PushType
	{
		$path = $this->path ??= $this->configProvider->resolvePath($this->qualificationChecker->getToolValues());
		$state = $this->createState($userId, $portalCreatedAt, $path);

		foreach ($path->getRules() as $rule)
		{
			if ($rule->matches($state))
			{
				return $rule->getPushType();
			}
		}

		return null;
	}

	/**
	 * Announces the candidates a pass is about to resolve, so the checks that can read a whole
	 * selection do it once instead of per user. Optional by design: resolvePush() answers the
	 * same either way, this only decides how many queries it costs.
	 *
	 * @param int[] $userIds
	 */
	public function preloadSelection(array $userIds, DateTime $portalCreatedAt): void
	{
		$this->registry->preload($userIds, $portalCreatedAt);
	}

	/**
	 * A state of its own for every call: a step is checked when a rule asks about it, at
	 * most once per call, and no fact outlives the call.
	 */
	private function createState(int $userId, DateTime $portalCreatedAt, ActivationPath $path): ActivationState
	{
		$checkStep = function (ActivationStep $step) use ($userId, $portalCreatedAt): bool {
			// A step nobody checks is a step nobody has closed. This closure runs inside the
			// user loop of the pass and inside the single-user entry point of the controller,
			// neither of which catches a throw from here: a step added to the enum without a
			// checker would otherwise be a log line per user of the portal and a 500 on the
			// dev page, instead of a series that simply stops at that step.
			if (!$this->registry->hasChecker($step))
			{
				return false;
			}

			return $this->registry->getChecker($step)->isCompleted($userId, $portalCreatedAt);
		};

		return new ActivationState($path->getSteps(), $checkStep);
	}
}
