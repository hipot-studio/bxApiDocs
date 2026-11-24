<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Onboarding\Rule;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Entity\Marketing\Choice;
use Bitrix\Mobile\Internal\Entity\Marketing\Condition;
use Bitrix\Mobile\Internal\Entity\Marketing\ConditionGroup;
use Bitrix\Mobile\Internal\Entity\Marketing\Operator;
use Bitrix\Mobile\Internal\Entity\Marketing\RuleDefinition;
use Bitrix\Mobile\Internal\Entity\Marketing\Verdict;
use Bitrix\Mobile\Internal\Integration\Bitrix24\License;
use Bitrix\Mobile\Internal\Onboarding\Activation\ActivationPath;
use Bitrix\Mobile\Internal\Onboarding\Activation\ActivationStep;
use Bitrix\Mobile\Internal\Onboarding\Config\CampaignConfigProvider;
use Bitrix\Mobile\Internal\Onboarding\Config\FinalCondition;
use Bitrix\Mobile\Internal\Onboarding\Config\FinalPushConfig;
use Bitrix\Mobile\Internal\Onboarding\Config\GateCondition;
use Bitrix\Mobile\Internal\Onboarding\Fact\CampaignHistoryFacts;
use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Internal\Service\Marketing\Fact\ActionFacts;
use Bitrix\Mobile\Internal\Service\Marketing\Fact\PermissionFacts;
use Bitrix\Mobile\Internal\Service\Marketing\Fact\PortalFacts;
use Bitrix\Mobile\Internal\Service\Marketing\RuleSet;
use Bitrix\Mobile\Internal\Service\Marketing\Time\TimeWindow;

/**
 * Rules of the push channel, built from the campaign document.
 *
 * Without the document the rules of the campaign stand alone:
 * - "onboarding.portal" - the portal has a creation date to count the series from;
 * - "onboarding.candidates" - a working employee; the pack asked about is the users with mobile activity;
 * - "onboarding.audience" - the owner of the portal, nobody while the owner cannot be read;
 * - "onboarding.eligibility.<push type>" - the right the push asks the user to use, granted while it
 *   cannot be read.
 *
 * With a document the pass can run on, its rules follow:
 * - "onboarding.entry" - the series of the user has started, or the portal is not older than the
 *   document lets a newcomer in, the age counted on the clock of the window;
 * - "onboarding.window" - "now" is inside the send window of the document, on the clock of the zone the
 *   document declares it in: the portal calendar, or the clock of the server - the one the schedule
 *   counts its days and its dedup on for such a document;
 * - "onboarding.activation" - the path by the tools of the qualification and their priority, the default
 *   path when none of its tools is named or the qualification cannot be read, in it the first step not
 *   closed, and the push that step sends; a path walked to its end matches nothing;
 * - "onboarding.final.implementation_help" - the help final is not held back by a visit after the meetup.
 *
 * The deeds of the steps count from the creation of the portal. What an unknown fact meant to the pass
 * before the rules is written into each condition: no module is a deed not done, an unreadable right lets
 * the user act, an unknown web login leaves the web step open.
 */
final class CampaignRuleSet implements RuleSet
{
	public const CHANNEL = CampaignHistoryFacts::CHANNEL;

	public const PORTAL = 'onboarding.portal';
	public const CANDIDATES = 'onboarding.candidates';
	public const AUDIENCE = 'onboarding.audience';
	public const ENTRY = 'onboarding.entry';
	public const WINDOW = 'onboarding.window';
	public const ACTIVATION = 'onboarding.activation';

	public const CHOICE_PATH = 'path';
	public const CHOICE_STEP = 'step';
	public const CHOICE_PUSH = 'push';

	private const ELIGIBILITY_PREFIX = 'onboarding.eligibility.';
	private const FINAL_PREFIX = 'onboarding.final.';

	private const DEFAULT_PATH = 'default';

	/** The gate of the message step while the document names none - a product requirement, never dropped. */
	private const DEFAULT_MESSAGE_GATE = GateCondition::COWORKER_JOINED_BY_INVITE_OR_AFTER_PUSH;

	/** No login the platform stamps falls on this day or before it, so a login up to it is none. */
	private const NO_LOGIN_BEFORE = '1970-01-01';

	private const MINUTES_PER_HOUR = 60;

	private const PORTAL_AGE_DAYS = 'portal.ageDays';
	private const MINUTE_OF_DAY = 'time.minuteOfDay';
	private const QUALIFICATION_TOOLS = 'qualification.tools';
	private const WORKING_EMPLOYEE = 'user.workingEmployee';
	private const FIRST_ADMIN = 'user.firstAdmin';
	private const HAS_ACTIVE_COLLEAGUE = 'user.hasActiveColleague';

	/**
	 * @param CampaignConfigProvider|null $config the document of the campaign, null where none is in charge
	 * @param DateTime|null $countedSince the moment the deeds of the steps count from; the creation of the
	 *     portal when not given
	 */
	public function __construct(
		private readonly ?CampaignConfigProvider $config = null,
		private readonly ?DateTime $countedSince = null,
	)
	{
	}

	/**
	 * The rule a push of this type needs to be allowed by, null for a push nobody gates.
	 */
	public static function eligibilityRuleOf(PushType $pushType): ?string
	{
		return isset(self::getRights()[$pushType->value]) ? self::ELIGIBILITY_PREFIX . $pushType->value : null;
	}

	/**
	 * The rule a final push has to match to go out on its date, null for a final that goes out on the date
	 * alone.
	 */
	public static function finalRuleOf(PushType $pushType): ?string
	{
		return $pushType === PushType::IMPLEMENTATION_HELP ? self::FINAL_PREFIX . $pushType->value : null;
	}

	public function getRules(): array
	{
		$rules = [
			self::rule(self::PORTAL, ConditionGroup::allOf(
				new Condition(self::PORTAL_AGE_DAYS, Operator::Range, [Condition::PARAM_FROM => 0]),
			)),
			self::rule(self::CANDIDATES, ConditionGroup::allOf(
				self::isTrue(self::WORKING_EMPLOYEE, Verdict::NotMatched),
			)),
			self::rule(self::AUDIENCE, ConditionGroup::allOf(
				self::isTrue(self::FIRST_ADMIN, Verdict::NotMatched),
			)),
		];

		foreach (self::getRights() as $pushType => $factCode)
		{
			$rules[] = self::rule(
				self::ELIGIBILITY_PREFIX . $pushType,
				ConditionGroup::allOf(self::isTrue($factCode, Verdict::Matched)),
			);
		}

		return [...$rules, ...$this->createDocumentRules()];
	}

	/**
	 * @return RuleDefinition[] none for a document the pass cannot run on
	 */
	private function createDocumentRules(): array
	{
		if ($this->config === null)
		{
			return [];
		}

		$schedule = $this->config->getScheduleConfig();
		$window = TimeWindow::tryFrom($schedule->sendWindowStart, $schedule->sendWindowHours);
		$paths = $this->config->getPaths();
		$since = $this->countedSince ?? (new License())->getCreatedAt();

		$isRunnable = $window !== null
			&& $schedule->maxPortalAgeDays >= 0
			&& isset($paths[self::DEFAULT_PATH])
			&& $since !== null
		;
		if (!$isRunnable)
		{
			return [];
		}

		$clock = $schedule->isPortalTimezoneWindow ? [] : [PortalFacts::PARAM_CLOCK => PortalFacts::CLOCK_SERVER];

		return [
			self::rule(self::ENTRY, ConditionGroup::anyOf(
				new Condition(
					self::PORTAL_AGE_DAYS,
					Operator::Range,
					[Condition::PARAM_FROM => 0, Condition::PARAM_TO => $schedule->maxPortalAgeDays, ...$clock],
					Verdict::NotMatched,
				),
				self::isTrue(CampaignHistoryFacts::SERIES_STARTED, Verdict::NotMatched),
			)),
			self::rule(self::WINDOW, ConditionGroup::allOf(
				self::createWindowCondition($schedule->sendWindowStart, $schedule->sendWindowHours, $clock),
			)),
			self::rule(self::ACTIVATION, ConditionGroup::allOf(), $this->createPathChoice($paths, $since)),
			self::rule(self::FINAL_PREFIX . PushType::IMPLEMENTATION_HELP->value, $this->createHelpConditions()),
		];
	}

	/**
	 * The minute of the day inside the window: its start counts, its end does not. The start is one the
	 * window has already accepted.
	 *
	 * @param array<string, string> $clock the clock of the zone the document declares its window in
	 */
	private static function createWindowCondition(string $start, int $lengthHours, array $clock): Condition
	{
		[$hours, $minutes] = array_map('intval', explode(':', $start));
		$from = $hours * self::MINUTES_PER_HOUR + $minutes;
		$to = $from + $lengthHours * self::MINUTES_PER_HOUR - 1;

		return new Condition(
			self::MINUTE_OF_DAY,
			Operator::Range,
			[Condition::PARAM_FROM => $from, Condition::PARAM_TO => $to, ...$clock],
		);
	}

	/**
	 * Paths named by the tools go first, the higher priority first and the order of the document among
	 * equals, and the default path takes whoever is left.
	 *
	 * @param array<string, ActivationPath> $paths
	 */
	private function createPathChoice(array $paths, DateTime $since): Choice
	{
		$byTools = array_filter(
			$paths,
			static fn(ActivationPath $path): bool => $path->getCode() !== self::DEFAULT_PATH
				&& $path->getToolCodes() !== [],
		);
		uasort(
			$byTools,
			static fn(ActivationPath $a, ActivationPath $b): int => $b->getPriority() <=> $a->getPriority(),
		);

		$alternatives = [];
		$steps = [];
		foreach ($byTools + [self::DEFAULT_PATH => $paths[self::DEFAULT_PATH]] as $code => $path)
		{
			$alternatives[$code] = $code === self::DEFAULT_PATH
				? ConditionGroup::allOf()
				: ConditionGroup::allOf(new Condition(
					self::QUALIFICATION_TOOLS,
					Operator::InSet,
					[Condition::PARAM_VALUES => $path->getToolCodes()],
					Verdict::NotMatched,
				))
			;
			$steps[$code] = $this->createStepChoice($path, $since);
		}

		return new Choice(self::CHOICE_PATH, $alternatives, $steps);
	}

	/**
	 * The steps of the path in order, each open while its deed is not done, and in each the push it sends.
	 * Two steps sending one push stay two alternatives, each in its own place; a step the path names twice
	 * adds nothing, its deed is the same.
	 */
	private function createStepChoice(ActivationPath $path, DateTime $since): Choice
	{
		$alternatives = [];
		$pushes = [];
		foreach ($path->getRules() as $rule)
		{
			$step = $rule->getStep()->value;
			if (isset($alternatives[$step]))
			{
				continue;
			}

			$alternatives[$step] = $this->createStepOpen($rule->getStep(), $since);
			$pushes[$step] = new Choice(self::CHOICE_PUSH, [$rule->getPushType()->value => ConditionGroup::allOf()]);
		}

		return new Choice(self::CHOICE_STEP, $alternatives, $pushes);
	}

	private function createStepOpen(ActivationStep $step, DateTime $since): ConditionGroup
	{
		return match ($step)
		{
			ActivationStep::CREATE_TASK => ConditionGroup::allOf(self::notDone(ActionFacts::TASK_CREATED, $since)),
			ActivationStep::CREATE_DEAL => ConditionGroup::allOf(self::notDone(ActionFacts::DEAL_CREATED, $since)),
			ActivationStep::INVITE => ConditionGroup::allOf(
				self::notDone(ActionFacts::ADDRESSED_INVITATION_SENT, $since),
				self::isTrue(PermissionFacts::INVITE, Verdict::Matched),
			),
			ActivationStep::MESSAGE => $this->createMessageOpen($since),
			ActivationStep::WEB => ConditionGroup::allOf(new Condition(
				ActionFacts::WEB_LOGIN_AT,
				Operator::DateRange,
				[Condition::PARAM_TO => new Date(self::NO_LOGIN_BEFORE, 'Y-m-d')],
				Verdict::Matched,
			)),
		};
	}

	/**
	 * A message to a colleague closes the step only once its gate is open, so the step stays open while
	 * either of the two is missing; the message is not asked of a user whose gate is closed.
	 */
	private function createMessageOpen(DateTime $since): ConditionGroup
	{
		$message = self::notDone(ActionFacts::COLLEAGUE_MESSAGE_SENT, $since);

		return match ($this->config?->getGateCondition(ActivationStep::MESSAGE) ?? self::DEFAULT_MESSAGE_GATE)
		{
			GateCondition::NONE => ConditionGroup::allOf($message),
			GateCondition::COWORKER_JOINED => ConditionGroup::anyOf(
				self::isFalse(self::HAS_ACTIVE_COLLEAGUE),
				$message,
			),
			GateCondition::COWORKER_JOINED_BY_INVITE_OR_AFTER_PUSH => ConditionGroup::anyOf(
				self::notDone(CampaignHistoryFacts::COLLEAGUE_JOINED_AFTER_INVITE_PUSH, $since),
				$message,
			),
		};
	}

	private function createHelpConditions(): ConditionGroup
	{
		$help = FinalPushConfig::fromFinals($this->config?->getFinals() ?? [], PushType::IMPLEMENTATION_HELP);
		if ($help?->condition !== FinalCondition::APP_NOT_OPENED_AFTER_MEETUP)
		{
			return ConditionGroup::allOf();
		}

		return ConditionGroup::allOf(self::isFalse(CampaignHistoryFacts::VISITED_AFTER_MEETUP));
	}

	/**
	 * The fact is false: the deed counted from the moment has not been done, or its source cannot say.
	 */
	private static function notDone(string $factCode, DateTime $since): Condition
	{
		return new Condition(
			$factCode,
			Operator::InSet,
			[Condition::PARAM_VALUES => [false], ActionFacts::PARAM_SINCE => $since],
			Verdict::Matched,
		);
	}

	private static function isTrue(string $factCode, Verdict $unknownAs): Condition
	{
		return new Condition($factCode, Operator::InSet, [Condition::PARAM_VALUES => [true]], $unknownAs);
	}

	/**
	 * The fact is false, or its source cannot say.
	 */
	private static function isFalse(string $factCode): Condition
	{
		return new Condition($factCode, Operator::InSet, [Condition::PARAM_VALUES => [false]], Verdict::Matched);
	}

	/**
	 * The rights a portal may withhold, by the push that asks the user to use one.
	 *
	 * @return array<string, string> push type => fact of the right
	 */
	private static function getRights(): array
	{
		return [PushType::INVITE->value => PermissionFacts::INVITE];
	}

	private static function rule(string $code, ConditionGroup $conditions, ?Choice $choice = null): RuleDefinition
	{
		return new RuleDefinition($code, self::CHANNEL, [], $conditions, $choice);
	}
}
