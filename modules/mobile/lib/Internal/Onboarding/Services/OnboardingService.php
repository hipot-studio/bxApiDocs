<?php

namespace Bitrix\Mobile\Internal\Onboarding\Services;

use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Onboarding\Activation\ActivationResolver;
use Bitrix\Mobile\Internal\Onboarding\Audience\CampaignAudience;
use Bitrix\Mobile\Internal\Onboarding\Audience\PortalOwnerAudience;
use Bitrix\Mobile\Internal\Onboarding\Checkers\Eligibility\PushEligibility;
use Bitrix\Mobile\Internal\Onboarding\Checkers\LastPushSentChecker;
use Bitrix\Mobile\Internal\Onboarding\Checkers\PortalAgeChecker;
use Bitrix\Mobile\Internal\Onboarding\Checkers\QualificationChecker;
use Bitrix\Mobile\Internal\Onboarding\Checkers\StepCheckerRegistry;
use Bitrix\Mobile\Internal\Onboarding\Conditions\PortalAgeCondition;
use Bitrix\Mobile\Internal\Onboarding\Conditions\PushSendConditionChain;
use Bitrix\Mobile\Internal\Onboarding\Config\CampaignConfigProvider;
use Bitrix\Mobile\Internal\Onboarding\Config\FinalCondition;
use Bitrix\Mobile\Internal\Onboarding\Config\FinalPushConfig;
use Bitrix\Mobile\Internal\Onboarding\Config\StepConfig;
use Bitrix\Mobile\Internal\Onboarding\Dto\DueFinalPush;
use Bitrix\Mobile\Internal\Onboarding\Dto\LastPushInfo;
use Bitrix\Mobile\Internal\Onboarding\Dto\PassSchedule;
use Bitrix\Mobile\Internal\Onboarding\Industry;
use Bitrix\Mobile\Internal\Onboarding\Providers\ActionTypeProvider;
use Bitrix\Mobile\Internal\Onboarding\Providers\PushContentProvider;
use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionDetector;
use Bitrix\Mobile\Internal\Onboarding\Result\ProcessResult;
use Bitrix\Mobile\Internal\Onboarding\Result\PushDeliveryResult;
use Bitrix\Mobile\Internal\Onboarding\Result\PushSendResult;
use Bitrix\Mobile\Internal\Onboarding\Schedule\ScheduleFactory;
use Bitrix\Mobile\Internal\Onboarding\Schedule\ScheduleInterface;
use Bitrix\Mobile\Internal\Onboarding\Schedule\SeriesCalendar;
use Bitrix\Mobile\Internal\Onboarding\Schedule\SeriesRhythm;
use Bitrix\Mobile\Push\Message;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The push pipeline: it selects the portal and its users, decides whether today is
 * a send day of the series, picks the push and sends it.
 *
 * A campaign-driven region resolves the push through the activation engine (path +
 * first uncompleted step) and reads texts, deeplinks, analytics and the attempt
 * limits from the campaign document. Its series anchor is the portal creation date -
 * one anchor for every user of the portal, mobile activity only selects the users - but
 * the send day is each user's own: the series has no calendar, and every push after the
 * first is counted from the one that actually went out to that user (`schedule.repeat`).
 *
 * A legacy region keeps the fixed "offset -> push type" day map with its region-local
 * texts, and counts it from the first mobile activity of each user, as it always has:
 * a colleague who opens the application on the eighth day of the portal still gets the
 * series from that day on.
 *
 * On a day the series sends nothing, the pipeline plans the final pushes - meetup and
 * implementation help - from the last push of the series actually sent.
 */
class OnboardingService
{
	/**
	 * Id of the verbose trace of the campaign, both in the logger registry of the portal -
	 * which is its switch - and in the `loggers` section of `.settings.php`, which decides
	 * where it writes. A portal that enabled the id and configured nothing gets the default
	 * file logger of the core (LOG_FILENAME).
	 */
	public const TRACE_LOGGER_ID = 'mobile.onboarding';

	public const ERROR_NO_SCHEDULE_TODAY = 'No push scheduled for today';
	public const ERROR_TYPE_NOT_CONFIGURED = 'Push type not configured for day';
	public const ERROR_SERIES_COMPLETED = 'Activation series completed';
	public const ERROR_ATTEMPT_LIMIT = 'Step attempt limit reached';
	public const ERROR_SERIES_STOPPED = 'Activation series stopped by a spent step';
	public const ERROR_CAMPAIGN_OVER = 'Campaign has nothing left for this user';
	public const ERROR_ENTRY_CLOSED = 'Portal is past the window for entering the campaign';
	public const ERROR_ALREADY_SENT = 'Already sent';
	public const ERROR_EMPTY_CONTENT = 'Empty push content';
	public const ERROR_VISITED_AFTER_MEETUP = 'App opened after the meetup push';
	public const ERROR_PULL_UNAVAILABLE = 'Pull module not available';
	public const ERROR_NO_ACTIVITY = 'No mobile activity';
	public const ERROR_NOT_ELIGIBLE = 'The portal does not let this user do what the push asks for';

	/**
	 * Codes of the send-failure protocol. The messages above are for humans reading the
	 * log and the dev harness; whoever reacts to an outcome matches the code, so
	 * rewording a message cannot break a decision.
	 */
	public const CODE_NO_SCHEDULE_TODAY = 'no_schedule_today';
	public const CODE_TYPE_NOT_CONFIGURED = 'type_not_configured';
	public const CODE_SERIES_COMPLETED = 'series_completed';
	public const CODE_ATTEMPT_LIMIT = 'attempt_limit';
	public const CODE_SERIES_STOPPED = 'series_stopped';
	public const CODE_CAMPAIGN_OVER = 'campaign_over';
	public const CODE_ENTRY_CLOSED = 'entry_closed';
	public const CODE_ALREADY_SENT = 'already_sent';
	public const CODE_EMPTY_CONTENT = 'empty_content';
	public const CODE_VISITED_AFTER_MEETUP = 'visited_after_meetup';
	public const CODE_PULL_UNAVAILABLE = 'pull_unavailable';
	public const CODE_NO_ACTIVITY = 'no_activity';
	public const CODE_NOT_ELIGIBLE = 'not_eligible';
	public const CODE_NOT_IN_AUDIENCE = 'not_in_audience';

	/**
	 * Outcome of a send the channels refused: the errors of Sender come from outside the
	 * campaign and carry no code of this protocol, so the pass counts them under one.
	 */
	public const CODE_DELIVERY_FAILED = 'delivery_failed';

	/**
	 * Outcome of a user the pass threw on. The throw is isolated so that one user cannot end
	 * the pass, but a user counted in `processed` and in no outcome makes the summary of the
	 * pass report a number of skips it cannot explain - and a portal where every user throws
	 * then looks exactly like a portal with nothing to send.
	 */
	public const CODE_FAILED = 'failed';

	/**
	 * Outcomes almost every user of almost every pass ends on - the series has nothing for
	 * today, the push of the day is already out, or the campaign is not being run for this
	 * user at all. They stay in the counters of the pass and out of its log line, which would
	 * otherwise report the same numbers hourly.
	 */
	private const ROUTINE_OUTCOME_CODES = [
		self::CODE_NO_SCHEDULE_TODAY,
		self::CODE_ALREADY_SENT,
		self::CODE_ENTRY_CLOSED,
		self::CODE_CAMPAIGN_OVER,
		self::CODE_NOT_IN_AUDIENCE,
	];

	/**
	 * Outcomes on which the series leaves the day to a final: the series is over - every step
	 * completed, or a step out of attempts and the series stopped for good - or the day is
	 * closed by a push already out.
	 *
	 * A series that is only waiting for its next interval is deliberately not among them, and
	 * neither is a push the channels refused. In both cases the series is alive and the day is
	 * still its own: nothing went out, so the next pass of the send window tries again, while a
	 * final slipping in meanwhile would close that day for good (the "at most one push per day"
	 * invariant covers the finals too) and send "let us meet in two days" ahead of a push of the
	 * series still owed. The day a final falls due is counted from the same push the next send
	 * of the series is, so the two really do compete for it. A final that waits loses nothing -
	 * it has its own catch-up window.
	 */
	private const SERIES_IDLE_CODES = [
		self::CODE_SERIES_COMPLETED,
		self::CODE_ATTEMPT_LIMIT,
		self::CODE_SERIES_STOPPED,
		self::CODE_ALREADY_SENT,
	];

	private const LEGACY_ANALYTICS_TYPE = '1-8d';
	private const ANALYTICS_TOOL = 'mobile';
	private const SERIES_PLACEHOLDER = '{series}';

	/**
	 * How long a final push stays available after the date it was planned for, counted in
	 * send days of the region. A final is due on one calendar date, and a single pass that
	 * misses it - the agent did not run, the sender reached nobody, the campaign was
	 * switched off for a day - would otherwise lose the push for good, the meetup taking
	 * the implementation help down with it (the help is gated by a sent meetup).
	 *
	 * Two send days give the campaign three chances: the planned day plus the two send days
	 * after it, which covers a skipped pass and the weekend in between. Longer would send
	 * "let us meet in two days" long after the series it belongs to.
	 *
	 * The age of the portal does not bound this chain. It is the window for entering the
	 * campaign, and a user with a series behind them is long past the entrance
	 * (canEnterCampaign), so the finals of a series that started on the last day of the window
	 * keep their three chances however old the portal has become by then.
	 */
	private const FINAL_CATCHUP_SEND_DAYS = 2;

	private UserSelectorService $userSelector;
	private MobileActivityService $activityService;
	private RegionDetector $regionDetector;
	private PortalAgeChecker $portalAgeChecker;
	private OnboardingPushService $pushStorage;
	private LastPushSentChecker $lastPushChecker;
	private QualificationChecker $qualificationChecker;
	private ?ActivationResolver $activationResolver;
	private ?DateTime $now;
	private SeriesCalendar $calendar;
	private PushDeliveryService $pushDelivery;
	private PushEligibility $pushEligibility;
	private CampaignAudience $campaignAudience;
	private ?LoggerInterface $traceLogger = null;

	/** @var array<string, ?FinalPushConfig> the `finals` section of the pass, per push type */
	private array $finalConfigs = [];

	/**
	 * $now fixes "today" for the whole pass - the send day of the series and the due
	 * date of a final are both calendar decisions, and a test (or a dev harness replay)
	 * must be able to state the day instead of bending the schedule around the day the
	 * suite happens to run on. $calendar is the timezone those days are read in wherever a
	 * campaign document is in charge; the legacy day map keeps the server zone it always had.
	 */
	public function __construct(
		?UserSelectorService $userSelector = null,
		?MobileActivityService $activityService = null,
		?RegionDetector $regionDetector = null,
		?PortalAgeChecker $portalAgeChecker = null,
		?OnboardingPushService $pushStorage = null,
		?LastPushSentChecker $lastPushChecker = null,
		?QualificationChecker $qualificationChecker = null,
		?ActivationResolver $activationResolver = null,
		?DateTime $now = null,
		?SeriesCalendar $calendar = null,
		?PushDeliveryService $pushDelivery = null,
		?PushEligibility $pushEligibility = null,
		?CampaignAudience $campaignAudience = null,
	)
	{
		$this->activityService = $activityService ?? new MobileActivityService();
		$this->userSelector = $userSelector ?? new UserSelectorService($this->activityService);
		$this->portalAgeChecker = $portalAgeChecker ?? new PortalAgeChecker();
		$this->regionDetector = $regionDetector ?? new RegionDetector($this->portalAgeChecker);
		$this->pushStorage = $pushStorage ?? new OnboardingPushService();
		$this->calendar = $calendar ?? new SeriesCalendar();
		$this->lastPushChecker = $lastPushChecker ?? new LastPushSentChecker($this->pushStorage);
		$this->qualificationChecker = $qualificationChecker ?? new QualificationChecker();
		$this->activationResolver = $activationResolver;
		$this->now = $now;
		$this->pushDelivery = $pushDelivery ?? new PushDeliveryService();
		// one per pass: the permissions it reads are memoised per user, and the pass asks about
		// each user once
		$this->pushEligibility = $pushEligibility ?? new PushEligibility();
		// one per pass as well: the owner of the portal is one answer for the whole walk
		$this->campaignAudience = $campaignAudience ?? new PortalOwnerAudience();
	}

	public function processAllUsers(): ProcessResult
	{
		$result = new ProcessResult();
		$regionConfig = $this->regionDetector->detect();
		$validation = $regionConfig->validate($this->now, $this->calendar);
		if (!$validation->isSuccess())
		{
			$this->logPortalDateIssue($validation);
			$this->trace('pass stopped by the conditions of the portal - ' . $this->describeErrors($validation));
			$result->addErrors($validation->getErrors());

			return $result;
		}

		$activityTimestamps = [];
		$anchorDate = null;

		if ($regionConfig->isCampaignDriven())
		{
			$anchorDate = $this->portalAgeChecker->getPortalCreatedAt();
			if ($anchorDate === null)
			{
				return $result->addError(
					new Error(PortalAgeCondition::ERROR_DATE_UNDEFINED, PortalAgeCondition::CODE_DATE_UNDEFINED),
				);
			}

			$userIds = $this->userSelector->filterValidUserIds($this->activityService->getUserIdsWithActivity());
		}
		else
		{
			// The day map counts from the activity of each user, so the pass keeps the
			// timestamps it selected the users by instead of reading them again per user.
			$activityTimestamps = $this->activityService->getActivityTimestampsByUser();
			$userIds = $this->userSelector->filterValidUserIds(array_keys($activityTimestamps));
		}

		$selection = $userIds;
		if ($selection === [])
		{
			return $result;
		}

		$this->pushStorage->preloadState($selection);
		$userIds = $regionConfig->isCampaignDriven()
			? $this->admitToCampaign($selection, $regionConfig, $result)
			: $selection
		;

		if ($userIds !== [])
		{
			// on the day the implementation help comes due its visit gate is asked for every
			// candidate of the pass; the read itself happens only if someone actually asks
			$this->activityService->preloadLastVisits($userIds);
		}

		$campaignConfig = $regionConfig->getCampaignConfig();
		if ($userIds !== [] && $anchorDate !== null && $campaignConfig !== null)
		{
			// The activation checks are the last per-user reads of the pass: without this the
			// walk costs a query into tasks/crm/intranet/im for every step every candidate has
			// already closed, and none of those tables has an index starting with the author, so
			// each of those queries re-reads the same date range of the campaign.
			//
			// The announcement itself reads nothing, which is why it is not gated by the send day
			// of the series: on a day the series has nothing to send no check is ever asked, so
			// nothing is read - and on a day a final is due the gates keep the selection they
			// would otherwise lose to such a gate.
			$this->getActivationResolver($campaignConfig)->preloadSelection($userIds, $anchorDate);
		}
		$this->tracePassStart($regionConfig, $anchorDate, count($userIds), count($selection));
		$sent = 0;

		foreach ($userIds as $userId)
		{
			try
			{
				$userSchedule = $this->resolveUserSchedule(
					$userId,
					$regionConfig,
					$anchorDate,
					$activityTimestamps,
				);
				if ($userSchedule === null)
				{
					// Unreachable on the normal path - the users of the day map come from the
					// same activity map, and a campaign region without a portal date never
					// reaches the loop - a guard in case the selection and the map diverge.
					$result->addOutcome(self::CODE_NO_ACTIVITY);
					if ($this->isTraceEnabled())
					{
						$this->trace('user ' . $userId . ': ' . self::CODE_NO_ACTIVITY);
					}

					continue;
				}

				$sendResult = $this->sendScheduledPushForRegion($userId, $regionConfig, $userSchedule);
				if ($sendResult->wasSent())
				{
					$sent++;
					if ($this->isTraceEnabled())
					{
						$this->trace('user ' . $userId . ': sent ' . ($sendResult->getPushType() ?? '?'));
					}

					continue;
				}

				$outcome = $this->resolveOutcomeCode($sendResult);
				$result->addOutcome($outcome);
				if ($this->isTraceEnabled())
				{
					$this->trace('user ' . $userId . ': ' . $outcome . $this->describePushTypeOf($sendResult));
				}
			}
			catch (\Throwable $e)
			{
				$result->addOutcome(self::CODE_FAILED);
				$this->log('Onboarding push: failed for user ' . $userId . ' - ' . $e->getMessage());
			}
		}

		$result
			->setProcessed(count($selection))
			->setSent($sent)
		;

		$this->logPassOutcomes($result);

		return $result;
	}

	/**
	 * Candidates of a campaign pass, with everyone outside the audience of the campaign and
	 * everyone the campaign has nothing left for taken out of it - and counted. All three
	 * answers are terminal and all three are free: the audience is one question about the
	 * portal and the state of the whole selection is already in memory, while everything
	 * downstream of here spends reads.
	 *
	 * Taking them out before the reads rather than inside the walk is the point: the last
	 * visits and the announcement of the selection to the checks are then made about the users
	 * that are actually left. Counting them is the other half of the point - a user in
	 * `processed` and in no outcome makes the summary of the pass state a number of skips it
	 * cannot explain, and past the entry window that is most of the portal.
	 *
	 * The walk keeps its own guards for both: a single-user send never goes through here.
	 *
	 * @param int[] $selection
	 * @return int[]
	 */
	private function admitToCampaign(array $selection, RegionConfig $regionConfig, ProcessResult $result): array
	{
		$admitted = [];

		foreach ($selection as $userId)
		{
			$outcome = match (true) {
				!$this->campaignAudience->includes($userId) => self::CODE_NOT_IN_AUDIENCE,
				$this->isCampaignOver($userId, $regionConfig) => self::CODE_CAMPAIGN_OVER,
				!$this->canEnterCampaign($userId, $regionConfig) => self::CODE_ENTRY_CLOSED,
				default => null,
			};

			if ($outcome === null)
			{
				$admitted[] = $userId;

				continue;
			}

			$result->addOutcome($outcome);
			if ($this->isTraceEnabled())
			{
				$this->trace('user ' . $userId . ': ' . $outcome);
			}
		}

		return $admitted;
	}

	public function getScheduledDayForToday(DateTime $anchorDate, RegionConfig $regionConfig): ?int
	{
		return $this->getScheduledDayForDate(
			$anchorDate,
			$regionConfig,
			$this->getToday($regionConfig)->format('Y-m-d'),
		);
	}

	/**
	 * Which of the declared day offsets falls on $targetDate, if any.
	 *
	 * This is the series of a legacy region - a list of absolute offsets from the anchor. A
	 * campaign series does not walk them: it counts every send from the push that went out
	 * before it (createRhythmSchedule), and the offsets a campaign document still declares
	 * are read by the dev harness alone.
	 *
	 * $anchorDate is a moment - the creation of the portal or the first activity of the
	 * user - while $targetDate is a calendar day of the series, the way
	 * SeriesCalendar reads one.
	 */
	public function getScheduledDayForDate(DateTime $anchorDate, RegionConfig $regionConfig, string $targetDate): ?int
	{
		$schedule = $this->getScheduleForRegion($regionConfig);

		// A day the region does not send on carries no offset, whatever the arithmetic of the
		// offsets says. Only day 0 can land there - it sits on the anchor of the portal, and
		// shiftWeekendToMonday, the rule that moves it off, is off by default - so without this
		// a schedule declaring workdaysOnly would send on a Saturday. The finals of the same
		// pass already ask this (isFinalDue); the invariant is one for both halves.
		if (!$schedule->isValidSendDay(new DateTime($targetDate, 'Y-m-d')))
		{
			return null;
		}

		foreach ($regionConfig->getSendDayOffsets() as $day)
		{
			$scheduledDate = $schedule
				->calculateSendDate($anchorDate, $day)
				->format('Y-m-d')
			;

			if ($scheduledDate === $targetDate)
			{
				return $day;
			}
		}

		return null;
	}

	/**
	 * Send days of the series for one user, from the anchor of its region.
	 *
	 * A legacy region walks its whole day map: absolute offsets from the first activity of the
	 * user, with the push type of each day fixed in the descriptor.
	 *
	 * A campaign region has no calendar to walk. Its series lives as long as the user keeps
	 * completing steps, and every send is counted from the previous one, so the only send day
	 * that exists yet is the next one - the push type of which is resolved at send time and
	 * stays null here. `day` is then the ordinal of that send rather than an offset.
	 *
	 * Diagnostic view for the dev harness.
	 *
	 * @return array<int, array{day: int, date: string, weekday: string, pushType: ?string}>
	 */
	public function getSeriesSchedule(int $userId): array
	{
		$regionConfig = $this->regionDetector->detect();
		$anchorDate = $this->resolveAnchorDate($regionConfig, $userId);
		if ($anchorDate === null)
		{
			return [];
		}

		return $regionConfig->isCampaignDriven()
			? $this->describeNextSend($userId, $regionConfig, $anchorDate)
			: $this->describeDayMap($regionConfig, $anchorDate)
		;
	}

	/**
	 * @return array<int, array{day: int, date: string, weekday: string, pushType: ?string}>
	 */
	private function describeDayMap(RegionConfig $regionConfig, DateTime $anchorDate): array
	{
		$schedule = $this->getScheduleForRegion($regionConfig);
		$result = [];

		foreach ($regionConfig->getSendDayOffsets() as $day)
		{
			$result[] = $this->describeSend(
				$day,
				$schedule->calculateSendDate($anchorDate, $day),
				$regionConfig->getPushTypeForDay($day)?->value,
			);
		}

		return $result;
	}

	/**
	 * The next send of a campaign series, or nothing at all when the document declares no
	 * rhythm to count it by.
	 *
	 * @return array<int, array{day: int, date: string, weekday: string, pushType: ?string}>
	 */
	private function describeNextSend(int $userId, RegionConfig $regionConfig, DateTime $anchorDate): array
	{
		$rhythm = $regionConfig->getSeriesRhythm();
		if ($rhythm === null)
		{
			return [];
		}

		$schedule = $this->getScheduleForRegion($regionConfig);
		$state = $this->pushStorage->getLastSentPush($userId);

		return [
			$this->describeSend(
				$state?->getSeriesSendCount() ?? 0,
				$this->resolveDueSendDate($state, $regionConfig, $schedule, $rhythm, $anchorDate),
				null,
			),
		];
	}

	/**
	 * @return array{day: int, date: string, weekday: string, pushType: ?string}
	 */
	private function describeSend(int $day, DateTime $sendDate, ?string $pushType): array
	{
		return [
			'day' => $day,
			'date' => $sendDate->format('Y-m-d'),
			'weekday' => $sendDate->format('l'),
			'pushType' => $pushType,
		];
	}

	/**
	 * Send date of a final push for the given anchor - the meetup counts from the last
	 * push of the series, the implementation help from the meetup. Null when the region
	 * runs no campaign document or the document declares no usable offset for the final.
	 */
	public function getFinalSendDate(PushType $pushType, DateTime $anchorDate): ?DateTime
	{
		$regionConfig = $this->regionDetector->detect();
		$campaignConfig = $regionConfig->getCampaignConfig();
		if ($campaignConfig === null)
		{
			return null;
		}

		return $this->calculateFinalSendDate(
			$this->getFinalConfig($campaignConfig, $pushType),
			$anchorDate,
			$this->getScheduleForRegion($regionConfig),
		);
	}

	/**
	 * Diagnostic view of one user for the dev harness.
	 *
	 * @return array{
	 *     hasMobileActivity: bool,
	 *     activityDate: ?string,
	 *     daysSinceActivity: ?int,
	 *     region: string,
	 *     isValidUser: bool,
	 *     portalEligible: bool,
	 *     portalZoneSupported: bool,
	 *     campaignDriven: bool,
	 *     seriesAnchor: ?string,
	 * }
	 */
	public function getUserInfo(int $userId): array
	{
		$activityDate = $this->activityService->getActivityDate($userId);
		$daysSince = $this->activityService->getDaysSinceActivity($userId);
		$regionConfig = $this->regionDetector->detect();

		return [
			'hasMobileActivity' => $activityDate !== null,
			'activityDate' => $activityDate?->format('Y-m-d H:i:s'),
			'daysSinceActivity' => $daysSince,
			'region' => $regionConfig->getCode(),
			'isValidUser' => $this->userSelector->isValidUser($userId),
			'portalEligible' => $this->portalAgeChecker->isEligible($regionConfig->getMaxPortalAgeDays()),
			'portalZoneSupported' => $regionConfig->isSupported(),
			'campaignDriven' => $regionConfig->isCampaignDriven(),
			'seriesAnchor' => $this->resolveAnchorDate($regionConfig, $userId)?->format('Y-m-d H:i:s'),
		];
	}

	public function sendScheduledPush(int $userId): PushSendResult
	{
		$regionConfig = $this->regionDetector->detect();
		$validation = $regionConfig->validate($this->now, $this->calendar);
		if (!$validation->isSuccess())
		{
			$this->logPortalDateIssue($validation);

			return (new PushSendResult())->addErrors($validation->getErrors());
		}

		return $this->sendScheduledPushForRegion($userId, $regionConfig);
	}

	public function sendForcePush(int $userId): PushSendResult
	{
		$regionConfig = $this->regionDetector->detect();
		$validation = PushSendConditionChain::createForceChain($this->portalAgeChecker)->check($regionConfig);
		if (!$validation->isSuccess())
		{
			$this->logPortalDateIssue($validation);

			return (new PushSendResult())->addErrors($validation->getErrors());
		}

		return $this->sendScheduledPushForRegion($userId, $regionConfig, force: true);
	}

	/**
	 * Diagnostic view of the stored campaign state (DTO-01), or null when the user has
	 * no record yet. Dates are formatted for the dev harness.
	 *
	 * @return array{
	 *     type: string,
	 *     date: string,
	 *     stepAttempts: array<string, int>,
	 *     lastSeriesPushDate: ?string,
	 *     meetupSent: bool,
	 *     meetupPushDate: ?string,
	 *     helpSent: bool,
	 * }|null
	 */
	public function getLastPushInfo(int $userId): ?array
	{
		$lastPush = $this->pushStorage->getLastSentPush($userId);

		if ($lastPush === null)
		{
			return null;
		}

		return [
			'type' => $lastPush->type,
			'date' => DateTime::createFromTimestamp($lastPush->timestamp)->format('Y-m-d H:i:s'),
			'stepAttempts' => $lastPush->stepAttempts,
			'lastSeriesPushDate' => $lastPush->lastSeriesPushDate !== null
				? DateTime::createFromTimestamp($lastPush->lastSeriesPushDate)->format('Y-m-d H:i:s')
				: null,
			'meetupSent' => $lastPush->meetupSent,
			'meetupPushDate' => $lastPush->meetupPushDate !== null
				? DateTime::createFromTimestamp($lastPush->meetupPushDate)->format('Y-m-d H:i:s')
				: null,
			'helpSent' => $lastPush->helpSent,
		];
	}

	private function getScheduleForRegion(RegionConfig $regionConfig): ScheduleInterface
	{
		return ScheduleFactory::forRegion($regionConfig, $this->calendar);
	}

	/**
	 * Calendar of the region, null meaning the server zone. Only a region with a campaign
	 * document reads its days in the portal calendar; the day map keeps the boundary it had
	 * before the campaign, and the factory owns that rule for the schedule as well.
	 */
	private function getCalendarForRegion(RegionConfig $regionConfig): ?SeriesCalendar
	{
		return ScheduleFactory::calendarForRegion($regionConfig, $this->calendar);
	}

	/**
	 * Today as the region counts days, so the send day of the series and the dedup of the
	 * day are decided on one scale.
	 */
	private function getToday(RegionConfig $regionConfig): DateTime
	{
		$now = $this->getNow();

		return $this->getCalendarForRegion($regionConfig)?->toCalendarTime($now) ?? $now;
	}

	/**
	 * Anchor of the series: the portal creation date for a campaign-driven region - one
	 * anchor for the whole portal - and the first mobile activity of the user for a region
	 * on the legacy day map, which has always counted its days from there.
	 */
	private function resolveAnchorDate(RegionConfig $regionConfig, int $userId): ?DateTime
	{
		return $regionConfig->isCampaignDriven()
			? $this->portalAgeChecker->getPortalCreatedAt()
			: $this->activityService->getActivityDate($userId)
		;
	}

	/**
	 * Code the pass counts an unsent push under. Only a code of this protocol names itself:
	 * the errors of the channels come from outside the campaign and carry no code at all -
	 * Error defaults to the integer 0 - so everything else is a refused delivery.
	 */
	private function resolveOutcomeCode(PushSendResult $sendResult): string
	{
		$code = $sendResult->getFailureCode();

		return is_string($code) && $code !== '' ? $code : self::CODE_DELIVERY_FAILED;
	}

	/**
	 * One line per pass: how many users it walked, how many pushes went out, and what
	 * stopped the rest. Every outcome of the loop is counted, the routine ones included,
	 * but only the notable ones are named here - a step out of attempts, a document without
	 * a text, an unavailable pull module, a delivery nobody accepted. Written once per pass
	 * rather than per user: the codes of a whole portal say more than a line each.
	 */
	private function logPassOutcomes(ProcessResult $result): void
	{
		$notable = array_diff_key($result->getOutcomes(), array_flip(self::ROUTINE_OUTCOME_CODES));
		$reasons = [];

		foreach ($notable as $code => $count)
		{
			$reasons[] = $code . ': ' . $count;
		}

		$this->log(
			'Onboarding push: pass over ' . $result->getProcessed() . ' users, sent ' . $result->getSent()
			. ($reasons === [] ? '' : ', outcomes - ' . implode(', ', $reasons)),
		);
	}

	private function logPortalDateIssue(Result $validation): void
	{
		foreach ($validation->getErrors() as $error)
		{
			if ($error->getCode() === PortalAgeCondition::CODE_DATE_UNDEFINED)
			{
				$this->log(
					'Onboarding push: portal creation date is undefined'
					. ' (option main/~controller_date_create is empty) - pushes are blocked for the entire portal',
				);

				return;
			}
		}
	}

	/**
	 * Schedule of one user of the pass. A campaign-driven region shares the anchor of the
	 * portal, which the pass resolved once; a legacy region anchors the series on the first
	 * mobile activity of each user, and the pass carries the timestamps it selected them by.
	 *
	 * Null when the user has no anchor at all, which for a legacy region means no activity.
	 *
	 * @param array<int, int> $activityTimestamps
	 */
	private function resolveUserSchedule(
		int $userId,
		RegionConfig $regionConfig,
		?DateTime $anchorDate,
		array $activityTimestamps,
	): ?PassSchedule
	{
		if (!$regionConfig->isCampaignDriven())
		{
			$activityTimestamp = $activityTimestamps[$userId] ?? null;
			$anchorDate = $activityTimestamp === null ? null : DateTime::createFromTimestamp($activityTimestamp);
		}

		return $this->createUserSchedule($userId, $regionConfig, $anchorDate);
	}

	/**
	 * Send day of one user under the scheme of their region, or null when the region gave the
	 * user no anchor to count from.
	 */
	private function createUserSchedule(int $userId, RegionConfig $regionConfig, ?DateTime $anchorDate): ?PassSchedule
	{
		if ($anchorDate === null)
		{
			return null;
		}

		return $regionConfig->isCampaignDriven()
			? $this->createRhythmSchedule($userId, $regionConfig, $anchorDate)
			: $this->createDayMapSchedule($regionConfig, $anchorDate)
		;
	}

	/**
	 * Send day of a user of a campaign region. The series has no calendar: its first push sits
	 * on the anchor day of the portal, and every following one is due the rhythm's number of
	 * send days after the push that actually went out to this user - which is why the answer is
	 * this user's own even though the anchor is the portal's.
	 *
	 * The due date is compared open, so a day the pass missed still sends. Every send is
	 * counted from the previous one, so a due date left behind would never move again: one pass
	 * that did not run - an agent nobody scheduled, a window that stayed closed, a delivery no
	 * channel accepted - would otherwise freeze the series for good.
	 *
	 * A document that declares no rhythm declares no cadence, and the series then sends nothing:
	 * acceptance refuses such a document, and running the portal on the defaults of SeriesRhythm
	 * would be a cadence nobody authored.
	 */
	private function createRhythmSchedule(int $userId, RegionConfig $regionConfig, DateTime $anchorDate): PassSchedule
	{
		$schedule = $this->getScheduleForRegion($regionConfig);
		$rhythm = $regionConfig->getSeriesRhythm();
		$today = $this->getToday($regionConfig);
		if ($rhythm === null || !$schedule->isValidSendDay($today))
		{
			return new PassSchedule($anchorDate, $schedule, null);
		}

		$dueDate = $this->resolveDueSendDate(
			$this->pushStorage->getLastSentPush($userId),
			$regionConfig,
			$schedule,
			$rhythm,
			$anchorDate,
		);

		return new PassSchedule(
			$anchorDate,
			$schedule,
			$today->format('Y-m-d') >= $dueDate->format('Y-m-d') ? $today : null,
		);
	}

	/**
	 * Date the next push of the series is due for a user whose campaign state is $state.
	 */
	private function resolveDueSendDate(
		?LastPushInfo $state,
		RegionConfig $regionConfig,
		ScheduleInterface $schedule,
		SeriesRhythm $rhythm,
		DateTime $anchorDate,
	): DateTime
	{
		return $rhythm->dueDate(
			$schedule,
			$anchorDate,
			$state?->getSeriesSendCount() ?? 0,
			$this->readInRegionCalendar($state?->lastSeriesPushDate, $regionConfig),
		);
	}

	/**
	 * A stored moment as the calendar of the region reads it. The rhythm counts send days from
	 * the day the previous push went out, and that day has to be read on the same clock as
	 * today - otherwise a portal whose day boundary differs from the server one counts the
	 * interval between two days of different calendars.
	 */
	private function readInRegionCalendar(?int $timestamp, RegionConfig $regionConfig): ?DateTime
	{
		if ($timestamp === null)
		{
			return null;
		}

		$moment = DateTime::createFromTimestamp($timestamp);

		return $this->getCalendarForRegion($regionConfig)?->toCalendarTime($moment) ?? $moment;
	}

	/**
	 * Send day of a user of a legacy region: its day map is a list of absolute offsets from the
	 * anchor, so today either matches one of them or carries nothing.
	 */
	private function createDayMapSchedule(RegionConfig $regionConfig, DateTime $anchorDate): PassSchedule
	{
		return PassSchedule::fromDayOffset(
			$anchorDate,
			$this->getScheduleForRegion($regionConfig),
			$this->getScheduledDayForToday($anchorDate, $regionConfig),
		);
	}

	private function sendScheduledPushForRegion(
		int $userId,
		RegionConfig $regionConfig,
		?PassSchedule $passSchedule = null,
		bool $force = false,
	): PushSendResult
	{
		$passSchedule ??= $this->createUserSchedule(
			$userId,
			$regionConfig,
			$this->resolveAnchorDate($regionConfig, $userId),
		);
		if ($passSchedule === null)
		{
			return (new PushSendResult())->addError($regionConfig->isCampaignDriven()
				? new Error(PortalAgeCondition::ERROR_DATE_UNDEFINED, PortalAgeCondition::CODE_DATE_UNDEFINED)
				: new Error(self::ERROR_NO_ACTIVITY, self::CODE_NO_ACTIVITY),
			);
		}

		$campaignConfig = $regionConfig->getCampaignConfig();
		if ($campaignConfig !== null && !$force && $this->isCampaignOver($userId, $regionConfig))
		{
			return (new PushSendResult())->addError(
				new Error(self::ERROR_CAMPAIGN_OVER, self::CODE_CAMPAIGN_OVER),
			);
		}

		$seriesResult = $this->sendSeriesPush($userId, $regionConfig, $campaignConfig, $passSchedule, $force);
		if ($campaignConfig === null || $seriesResult->wasSent() || !$this->isSeriesIdle($seriesResult))
		{
			return $seriesResult;
		}

		// The finals are counted from the last push actually sent, so one may
		// be due on a day the series itself has nothing left to send.
		return $this->sendFinalPush($userId, $regionConfig, $campaignConfig, $passSchedule->schedule, $force)
			?? $seriesResult
		;
	}

	/**
	 * Whether the campaign has nothing left for this user, which is the campaign itself ending.
	 * Deliberately not the outcome of a completed series - that one is still owed a meetup - and
	 * deliberately not among SERIES_IDLE_CODES, which exist to hand the day to a final.
	 *
	 * Both finals behind them is the plain case. The other one is a campaign that ran out of
	 * time: the series will never send again - a spent step stopped it, or every step of the path
	 * is closed - and the final it still owed can no longer come due, its catch-up window having
	 * lapsed. Nothing in the state says so on its own: a window closes by the calendar, while a
	 * flag is raised only by a send. Without asking the horizon of the finals such a user stays a
	 * candidate of every pass of the portal for good.
	 *
	 * A series still able to send is deliberately not asked: every push of it moves the anchor of
	 * the meetup along, so a window that reads as lapsed between two pushes says nothing.
	 */
	private function isCampaignOver(int $userId, RegionConfig $regionConfig): bool
	{
		$state = $this->pushStorage->getLastSentPush($userId);
		if ($state !== null && $state->meetupSent && $state->helpSent)
		{
			return true;
		}

		if (!$this->isSeriesOverForGood($userId))
		{
			return false;
		}

		$campaignConfig = $regionConfig->getCampaignConfig();

		return $campaignConfig === null || !$this->hasFinalAhead(
			$state,
			$campaignConfig,
			$this->getScheduleForRegion($regionConfig),
			$this->getToday($regionConfig),
		);
	}

	/**
	 * Whether the series of the user is past its end for good - stopped by a spent step, or
	 * walked to the last step of the path. Both are marks of the state and neither is ever
	 * lifted; a series merely waiting for its next interval is neither.
	 */
	private function isSeriesOverForGood(int $userId): bool
	{
		return $this->pushStorage->isSeriesCompleted($userId) || $this->pushStorage->isSeriesStopped($userId);
	}

	/**
	 * Whether a final can still go out for this state - the one next in line is due today, is
	 * still ahead, or is inside the catch-up window that follows its date.
	 */
	private function hasFinalAhead(
		?LastPushInfo $state,
		CampaignConfigProvider $campaignConfig,
		ScheduleInterface $schedule,
		DateTime $today,
	): bool
	{
		if ($state === null)
		{
			return false;
		}

		[$pushType, $anchorTimestamp] = $this->nextFinalInLine($state);
		if ($pushType === null || $anchorTimestamp === null)
		{
			return false;
		}

		$sendDate = $this->calculateFinalSendDate(
			$this->getFinalConfig($campaignConfig, $pushType),
			DateTime::createFromTimestamp($anchorTimestamp),
			$schedule,
		);

		return $sendDate !== null && $today->format('Y-m-d') <= $this->lastCatchUpDay($sendDate, $schedule);
	}

	/**
	 * Whether the campaign is still open to this user.
	 *
	 * The age of the portal is a condition of entering the campaign, not a condition of every
	 * send: the series lasts as long as the user keeps completing steps (AC-022), so a gate
	 * asked on every pass would cut a running series in the middle. A user who has already had
	 * a push of the series is past the entrance, and the age of the portal decides nothing for
	 * them any more; a user who has not is admitted only while the window of the document is
	 * open (`schedule.maxPortalAgeDays`).
	 */
	private function canEnterCampaign(int $userId, RegionConfig $regionConfig): bool
	{
		if ($this->pushStorage->getLastSentPush($userId)?->lastSeriesPushDate !== null)
		{
			return true;
		}

		return $this->portalAgeChecker->isEligible(
			$regionConfig->getMaxPortalAgeDays(),
			$this->getNow(),
			$this->getCalendarForRegion($regionConfig),
		);
	}

	/**
	 * Whether the series had nothing to send today, which is what leaves the day to a final.
	 * Anything else - an empty text, an unavailable pull module, a delivery nobody accepted -
	 * means the series wanted the day and keeps it.
	 */
	private function isSeriesIdle(PushSendResult $seriesResult): bool
	{
		return in_array($this->resolveOutcomeCode($seriesResult), self::SERIES_IDLE_CODES, true);
	}

	private function sendSeriesPush(
		int $userId,
		RegionConfig $regionConfig,
		?CampaignConfigProvider $campaignConfig,
		PassSchedule $passSchedule,
		bool $force,
	): PushSendResult
	{
		$result = new PushSendResult();

		$scheduledDay = $passSchedule->scheduledDay;
		$scheduledDate = $passSchedule->scheduledDate;
		if ($scheduledDate === null)
		{
			return $result->addError(new Error(self::ERROR_NO_SCHEDULE_TODAY, self::CODE_NO_SCHEDULE_TODAY));
		}

		// A stopped series is terminal, so it is asked first and costs nothing: the state is
		// already preloaded, while everything below spends queries. The legacy day map knows
		// no steps and no attempts, so nothing there can stop.
		if ($campaignConfig !== null && !$force && $this->pushStorage->isSeriesStopped($userId))
		{
			return $result->addError(new Error(self::ERROR_SERIES_STOPPED, self::CODE_SERIES_STOPPED));
		}

		// Asked next, and for the same reason: a portal past the entrance carries most of its
		// users, and none of them may cost a query.
		if ($campaignConfig !== null && !$force && !$this->canEnterCampaign($userId, $regionConfig))
		{
			return $result->addError(new Error(self::ERROR_ENTRY_CLOSED, self::CODE_ENTRY_CLOSED));
		}

		// The dedup of a campaign region needs no push type, so it comes before the
		// resolver: a day that already carried a push must not spend a cross-module
		// query per step of the path for every user of the portal.
		if ($campaignConfig !== null && !$force && $this->isSentOnScheduledDate($userId, $regionConfig, $scheduledDate))
		{
			return $result->addError(new Error(self::ERROR_ALREADY_SENT, self::CODE_ALREADY_SENT));
		}

		// A series already known to be over answers from the state, and for the same reason the
		// dedup above does: the steps of a user who closed them all cannot open again, so asking
		// the checkers again spends a cross-module query per step on an answer that cannot change.
		if ($campaignConfig !== null && !$force && $this->pushStorage->isSeriesCompleted($userId))
		{
			return $result->addError(new Error(self::ERROR_SERIES_COMPLETED, self::CODE_SERIES_COMPLETED));
		}

		// a region on a day map always brings the offset of its send day along with the date
		$pushType = $campaignConfig !== null
			? $this->getActivationResolver($campaignConfig)->resolvePush($userId, $passSchedule->anchorDate)
			: ($scheduledDay === null ? null : $regionConfig->getPushTypeForDay($scheduledDay))
		;

		if ($pushType === null)
		{
			if ($campaignConfig === null)
			{
				return $result->addError(new Error(self::ERROR_TYPE_NOT_CONFIGURED, self::CODE_TYPE_NOT_CONFIGURED));
			}

			// The walk of the resolver has just ended, and its answer is the one that cannot
			// change back: writing it down is what keeps every following pass from walking it
			// again. A forced pass of the harness states nothing about the campaign of a real
			// user, here as everywhere else.
			if (!$force)
			{
				$this->pushStorage->registerSeriesCompleted($userId, $this->stateTimestamp());
			}

			return $result->addError(new Error(self::ERROR_SERIES_COMPLETED, self::CODE_SERIES_COMPLETED));
		}

		$result->setPushType($pushType->value);

		$attempt = $this->getNextAttempt($userId, $pushType);
		if ($this->isTraceEnabled())
		{
			$this->trace(
				'user ' . $userId . ': ' . $this->describeSendDay($scheduledDay, $scheduledDate)
				. ' resolves to ' . $pushType->value
				. ', attempt ' . $attempt
				. ($campaignConfig === null ? '' : ' of ' . $this->getMaxAttempts($campaignConfig, $pushType)),
			);
		}

		if ($campaignConfig !== null && !$force && $attempt > $this->getMaxAttempts($campaignConfig, $pushType))
		{
			// This send is the moment of the interruption: the step is still uncompleted and its
			// pushes are spent. Writing it down is what makes the stop irreversible - completing
			// the step afterwards leaves a state that looks exactly like completing it in time.
			$this->pushStorage->registerSeriesStopped($userId, $this->stateTimestamp());
			$this->traceSeriesStopped($userId, $pushType);

			return $result->addError(new Error(self::ERROR_ATTEMPT_LIMIT, self::CODE_ATTEMPT_LIMIT));
		}

		if (
			$campaignConfig === null
			&& !$force
			&& $this->isAlreadySentToday($userId, $regionConfig, $pushType, $scheduledDate)
		)
		{
			return $result->addError(new Error(self::ERROR_ALREADY_SENT, self::CODE_ALREADY_SENT));
		}

		// The last question before the send, and the only one asked of both schedules: a push
		// that calls the user to an action the portal does not open to him arrives as a dead
		// end. The step of a campaign path has already answered it for its own steps - this is
		// what answers it for the legacy day map, which knows no steps at all, and for anything
		// that resolves a push type later on. A forced run of the dev harness passes, as it
		// passes the window and the attempt counters.
		if (!$force && !$this->pushEligibility->isAllowed($userId, $pushType))
		{
			return $result->addError(new Error(self::ERROR_NOT_ELIGIBLE, self::CODE_NOT_ELIGIBLE));
		}

		$sendResult = $campaignConfig !== null
			? $this->sendActivationPush($userId, $campaignConfig, $pushType, $attempt, $force)
			: $this->sendLegacyPush($userId, $regionConfig, $scheduledDay, $pushType)
		;

		if ($sendResult->isSuccess())
		{
			return $result;
		}

		$result->addErrors($sendResult->getErrors());

		return $result;
	}

	/**
	 * Meetup and implementation help, planned from the last push of the
	 * activation series. Returns null when no final is due today, leaving the outcome
	 * of the series attempt as the answer of the pipeline.
	 *
	 * The implementation help is counted from the moment the meetup was actually sent, not
	 * from the date it was planned for: its own gate asks whether the user opened the
	 * application after receiving the meetup, and a help push planned from a date the
	 * meetup missed could fall on the day of the meetup itself.
	 *
	 * The send window is already granted by the condition chain of the caller; the
	 * "at most one push per day" invariant covers the finals as well. A final exists only
	 * where a campaign document does, so its days are the portal ones.
	 */
	private function sendFinalPush(
		int $userId,
		RegionConfig $regionConfig,
		CampaignConfigProvider $campaignConfig,
		ScheduleInterface $schedule,
		bool $force,
	): ?PushSendResult
	{
		$state = $this->pushStorage->getLastSentPush($userId);
		if ($state === null || $state->lastSeriesPushDate === null)
		{
			return null;
		}

		// the calendar of the region, not the portal one by default: the series, its window and
		// its dedup all follow schedule.sendWindow.timezone, and a final counting another day
		// would close a day the series never closed
		$today = $this->getToday($regionConfig);
		$due = $this->resolveDueFinal($state, $campaignConfig, $schedule, $today);
		if ($due === null)
		{
			return null;
		}

		// The same switch both branches of the series read: one flag cannot mean "at most one
		// push a day" for the series and "always" for the finals. And the day may have been
		// closed by a push of the series, so this answer carries no push type - naming the
		// final here would report a push that was never considered today.
		if (
			!$force
			&& $regionConfig->isDedupPerDay()
			&& $this->lastPushChecker->isSentOnDate($userId, $today, $this->getCalendarForRegion($regionConfig))
		)
		{
			return (new PushSendResult())->addError(new Error(self::ERROR_ALREADY_SENT, self::CODE_ALREADY_SENT));
		}

		$result = (new PushSendResult())->setPushType($due->pushType->value);

		if ($due->pushType === PushType::IMPLEMENTATION_HELP && $this->hasVisitAfterMeetup($userId, $due->config, $state))
		{
			// The help has a single attempt, and marking it spent is a decision of the campaign pass:
			// a forced pass of the harness reports the reason without taking the attempt away.
			if (!$force)
			{
				$this->pushStorage->skipHelpPush($userId);
			}

			return $result->addError(new Error(self::ERROR_VISITED_AFTER_MEETUP, self::CODE_VISITED_AFTER_MEETUP));
		}

		$sendResult = $this->sendFinalMessage($userId, $campaignConfig, $due->pushType, $force);
		if (!$sendResult->isSuccess())
		{
			$result->addErrors($sendResult->getErrors());
		}

		return $result;
	}

	/**
	 * The final next in line for this state and the moment it is counted from, both null when
	 * the user is past them all. The finals run strictly one after another: while the meetup is
	 * pending, the implementation help is not considered at all.
	 *
	 * @return array{0: ?PushType, 1: ?int}
	 */
	private function nextFinalInLine(LastPushInfo $state): array
	{
		return match (true) {
			!$state->meetupSent => [PushType::MEETUP, $state->lastSeriesPushDate],
			!$state->helpSent => [PushType::IMPLEMENTATION_HELP, $state->meetupPushDate],
			default => [null, null],
		};
	}

	/**
	 * The final that may go out today, if any, together with the document values it was
	 * planned from.
	 */
	private function resolveDueFinal(
		LastPushInfo $state,
		CampaignConfigProvider $campaignConfig,
		ScheduleInterface $schedule,
		DateTime $today,
	): ?DueFinalPush
	{
		[$pushType, $anchorTimestamp] = $this->nextFinalInLine($state);

		if ($pushType === null)
		{
			return null;
		}

		$finalConfig = $this->getFinalConfig($campaignConfig, $pushType);

		return $finalConfig !== null && $this->isFinalDue($finalConfig, $anchorTimestamp, $schedule, $today)
			? new DueFinalPush($pushType, $finalConfig)
			: null
		;
	}

	/**
	 * Values of one final from the `finals` section of the document, parsed once per pass:
	 * the section is the same for every user of the portal, and every user of a pass past
	 * its series asks for it.
	 */
	private function getFinalConfig(CampaignConfigProvider $campaignConfig, PushType $pushType): ?FinalPushConfig
	{
		if (!array_key_exists($pushType->value, $this->finalConfigs))
		{
			$this->finalConfigs[$pushType->value] = FinalPushConfig::fromFinals(
				$campaignConfig->getFinals(),
				$pushType,
			);
		}

		return $this->finalConfigs[$pushType->value];
	}

	/**
	 * Whether the final may go out today: on the date it was planned for or within the
	 * catch-up window after it (FINAL_CATCHUP_SEND_DAYS), and only on a day the region
	 * sends on at all. The push itself stays a one-time event - the flags of the
	 * state decide that - and the day it lands on is still subject to the "at most one push
	 * per day" invariant of the caller.
	 */
	private function isFinalDue(
		FinalPushConfig $finalConfig,
		?int $anchorTimestamp,
		ScheduleInterface $schedule,
		DateTime $today,
	): bool
	{
		if ($anchorTimestamp === null || !$schedule->isValidSendDay($today))
		{
			return false;
		}

		$sendDate = $this->calculateFinalSendDate(
			$finalConfig,
			DateTime::createFromTimestamp($anchorTimestamp),
			$schedule,
		);
		if ($sendDate === null)
		{
			return false;
		}

		$day = $today->format('Y-m-d');

		return $day >= $sendDate->format('Y-m-d') && $day <= $this->lastCatchUpDay($sendDate, $schedule);
	}

	/**
	 * Last day a final planned for $sendDate may still go out on.
	 */
	private function lastCatchUpDay(DateTime $sendDate, ScheduleInterface $schedule): string
	{
		return $schedule->addSendDays($sendDate, self::FINAL_CATCHUP_SEND_DAYS)->format('Y-m-d');
	}

	private function calculateFinalSendDate(
		?FinalPushConfig $finalConfig,
		DateTime $anchorDate,
		ScheduleInterface $schedule,
	): ?DateTime
	{
		return $finalConfig === null
			? null
			: $schedule->calculateSendDate($anchorDate, $finalConfig->offsetWorkdays)
		;
	}

	/**
	 * Visit signal of the implementation help: the campaign document declares the
	 * condition (`finals.implementation_help.condition`), the backend only evaluates it
	 * against the last mobile visit of the user.
	 */
	private function hasVisitAfterMeetup(int $userId, FinalPushConfig $finalConfig, LastPushInfo $state): bool
	{
		if ($finalConfig->condition !== FinalCondition::APP_NOT_OPENED_AFTER_MEETUP || $state->meetupPushDate === null)
		{
			return false;
		}

		$lastVisit = $this->activityService->getLastVisitDate($userId);

		return $lastVisit !== null && $lastVisit->getTimestamp() > $state->meetupPushDate;
	}

	/**
	 * Dedup of a campaign region: the activation series may resolve a different step
	 * within the same send day once the user completes the current one, so a day that
	 * carried any push of the campaign is closed regardless of the type.
	 */
	private function isSentOnScheduledDate(int $userId, RegionConfig $regionConfig, DateTime $scheduledDate): bool
	{
		if (!$regionConfig->isDedupPerDay())
		{
			return false;
		}

		return $this->lastPushChecker->isSentOnDate(
			$userId,
			$scheduledDate,
			$this->getCalendarForRegion($regionConfig),
		);
	}

	/**
	 * Dedup of a legacy region: its day map fixes one type per day, so the same type on
	 * the same day is what must not repeat.
	 */
	private function isAlreadySentToday(
		int $userId,
		RegionConfig $regionConfig,
		PushType $pushType,
		DateTime $scheduledDate,
	): bool
	{
		if (!$regionConfig->isDedupPerDay())
		{
			return false;
		}

		return $this->lastPushChecker->isAlreadySent(
			$userId,
			$pushType,
			$scheduledDate,
			$this->getCalendarForRegion($regionConfig),
		);
	}

	private function getNextAttempt(int $userId, PushType $pushType): int
	{
		return ($this->pushStorage->getLastSentPush($userId)?->getAttempts($pushType) ?? 0) + 1;
	}

	private function getMaxAttempts(CampaignConfigProvider $campaignConfig, PushType $pushType): int
	{
		return $campaignConfig->findStepByPushType($pushType)?->maxAttempts ?? StepConfig::DEFAULT_MAX_ATTEMPTS;
	}

	/**
	 * The resolver, its checker registry and the document behind them are the same for
	 * every user of the pass, so they are built once rather than per user of the loop.
	 *
	 * The registry gets the storage of the pipeline: the gate dates of the steps are part
	 * of the campaign state the pass has already preloaded, and a second storage would
	 * read it again per user.
	 */
	private function getActivationResolver(CampaignConfigProvider $campaignConfig): ActivationResolver
	{
		$this->activationResolver ??= new ActivationResolver(
			$campaignConfig,
			StepCheckerRegistry::createDefault($campaignConfig, $this->pushStorage),
			$this->qualificationChecker,
		);

		return $this->activationResolver;
	}

	private function getNow(): DateTime
	{
		return $this->now === null ? new DateTime() : clone $this->now;
	}

	/**
	 * Moment the state of a send is written at: the one the run stated, null when nothing was
	 * stated and the storage stamps the clock on the wall itself.
	 *
	 * A run judged at a stated moment has to leave a state that agrees with it. The finals are
	 * counted from the moment the meetup was actually sent, and the gates compare facts against
	 * the first send of a step - so a state stamped "now" under a stated run plans the next push
	 * from a day nothing happened on.
	 */
	private function stateTimestamp(): ?int
	{
		return $this->now?->getTimestamp();
	}

	private function sendActivationPush(
		int $userId,
		CampaignConfigProvider $campaignConfig,
		PushType $pushType,
		int $attempt,
		bool $force = false,
	): Result
	{
		if (!Loader::includeModule('pull'))
		{
			return (new Result())->addError(new Error(self::ERROR_PULL_UNAVAILABLE, self::CODE_PULL_UNAVAILABLE));
		}

		$industry = Industry::fromCode($this->qualificationChecker->getIndustryValue());
		$content = (new PushContentProvider($campaignConfig))->getContent($pushType, $attempt, $industry);
		if ($content === null)
		{
			return (new Result())->addError(new Error(self::ERROR_EMPTY_CONTENT, self::CODE_EMPTY_CONTENT));
		}

		$message = new Message(
			(new ActionTypeProvider($userId))->getCampaignActionType($pushType),
			$content->title,
			$content->text,
			$this->clickAnalyticsPayload(
				$campaignConfig,
				$campaignConfig->findStepByPushType($pushType)?->analyticsClickEvent,
			),
		);

		$delivery = $this->deliver($userId, $pushType, $message);
		if (!$delivery->wasDelivered())
		{
			return $delivery;
		}

		if ($userId > 0)
		{
			// The state is what the hourly pass runs on - the attempt counter of the step and
			// the date that anchors both finals - so a forced pass of the harness reports what
			// the pipeline would do without spending the campaign of a real user. Same decision
			// as the single attempt of the help above.
			if (!$force)
			{
				$this->pushStorage->registerSeriesPush($userId, $pushType, $this->stateTimestamp());
				$this->traceStateWritten($userId, $pushType);
			}

			$this->sendCampaignAnalytics($campaignConfig, $pushType);
		}

		return new Result();
	}

	/**
	 * The interruption is invisible from the outside - no push goes out and none ever will
	 * again - so the pass says so once, naming the step that spent the series.
	 */
	private function traceSeriesStopped(int $userId, PushType $pushType): void
	{
		if (!$this->isTraceEnabled())
		{
			return;
		}

		$this->trace(
			'user ' . $userId . ': series stopped for good on ' . $pushType->value
			. ' - its attempts are spent and the step is not completed',
		);
	}

	/**
	 * The write that closes the day of the user: the state is what every following pass
	 * reads, so a trace of a send without it explains nothing about the next pass.
	 */
	private function traceStateWritten(int $userId, PushType $pushType): void
	{
		if (!$this->isTraceEnabled())
		{
			return;
		}

		$stated = $this->stateTimestamp();

		$this->trace(
			'user ' . $userId . ': state written for ' . $pushType->value . ' at '
			. ($stated === null
				? 'the clock of the storage'
				: DateTime::createFromTimestamp($stated)->format('Y-m-d H:i:s') . ' (stated by the run)'),
		);
	}

	private function sendFinalMessage(
		int $userId,
		CampaignConfigProvider $campaignConfig,
		PushType $pushType,
		bool $force = false,
	): Result
	{
		if (!Loader::includeModule('pull'))
		{
			return (new Result())->addError(new Error(self::ERROR_PULL_UNAVAILABLE, self::CODE_PULL_UNAVAILABLE));
		}

		$industry = Industry::fromCode($this->qualificationChecker->getIndustryValue());
		$content = (new PushContentProvider($campaignConfig))->getFinalContent($pushType, $industry);
		if ($content === null)
		{
			return (new Result())->addError(new Error(self::ERROR_EMPTY_CONTENT, self::CODE_EMPTY_CONTENT));
		}

		$finalConfig = $this->getFinalConfig($campaignConfig, $pushType);
		$message = new Message(
			(new ActionTypeProvider($userId))->getActionType($pushType),
			$content->title,
			$content->text,
			$this->clickAnalyticsPayload($campaignConfig, $finalConfig?->analyticsClickEvent)
			+ self::deeplinkPayload($finalConfig),
		);

		$delivery = $this->deliver($userId, $pushType, $message);
		if (!$delivery->wasDelivered())
		{
			return $delivery;
		}

		if ($userId > 0)
		{
			// The flags of the finals are one-shot: written by a forced pass, one press of the
			// button on the stand closes the meetup of that user for good and re-anchors the
			// help on the day of the press.
			if (!$force)
			{
				$this->registerFinalPush($userId, $pushType);
			}

			$this->sendFinalAnalytics($campaignConfig, $pushType);
		}

		return new Result();
	}

	private function registerFinalPush(int $userId, PushType $pushType): void
	{
		if ($pushType === PushType::MEETUP)
		{
			$this->pushStorage->registerMeetupPush($userId, $this->stateTimestamp());

			return;
		}

		$this->pushStorage->registerHelpPush($userId, $this->stateTimestamp());
	}

	private function sendLegacyPush(int $userId, RegionConfig $regionConfig, int $day, PushType $pushType): Result
	{
		if (!Loader::includeModule('pull'))
		{
			return (new Result())->addError(new Error(self::ERROR_PULL_UNAVAILABLE, self::CODE_PULL_UNAVAILABLE));
		}

		$dayConfig = $regionConfig->getDay($day);
		$title = $dayConfig?->getTitle() ?? '';
		$body = $dayConfig?->getText() ?? '';
		if ($title === '' || $body === '')
		{
			return (new Result())->addError(
				new Error(self::ERROR_EMPTY_CONTENT . ' for day ' . $day, self::CODE_EMPTY_CONTENT),
			);
		}

		$message = new Message(
			(new ActionTypeProvider($userId))->getActionType($pushType),
			$title,
			$body,
		);

		$delivery = $this->deliver($userId, $pushType, $message);
		if (!$delivery->wasDelivered())
		{
			return $delivery;
		}

		if ($userId > 0)
		{
			$this->pushStorage->setLastSentPush($userId, $pushType, $this->stateTimestamp());
			$this->sendAnalytics($pushType);
		}

		return new Result();
	}

	/**
	 * Sends the push and answers what the channels did. A push at least one channel
	 * delivered is a sent push: the caller writes the campaign state and closes the day,
	 * because the user has the notification and a second copy of it is worse than a
	 * missing system banner (an offline device gets it anyway - the platform delivers
	 * later). A channel that failed while another one delivered is reported to the log
	 * only; nothing delivered leaves the state unwritten and the day open, so the next
	 * pass of the send window tries again.
	 */
	private function deliver(int $userId, PushType $pushType, Message $message): PushDeliveryResult
	{
		$delivery = $this->pushDelivery->deliver($userId, $message);

		if ($delivery->wasDelivered() && $delivery->hasFailedChannel())
		{
			$this->log(
				'Onboarding push: partial delivery of ' . $pushType->value . ' to user ' . $userId
				. ', failed channels: ' . $delivery->getFailureSummary(),
			);
		}

		$this->traceDelivery($userId, $pushType, $delivery);

		return $delivery;
	}

	/**
	 * What the channels answered, whatever the outcome: a stand where one channel is down
	 * or where nobody accepted the push is indistinguishable from a working one by the
	 * result of the pipeline alone - a push at least one channel took is a sent push.
	 */
	private function traceDelivery(int $userId, PushType $pushType, PushDeliveryResult $delivery): void
	{
		if (!$this->isTraceEnabled())
		{
			return;
		}

		$this->trace(
			'user ' . $userId . ': delivery of ' . $pushType->value
			. ' - delivered: ' . ($delivery->wasDelivered() ? $delivery->getDeliverySummary() : 'nobody')
			. ($delivery->hasFailedChannel() ? '; failed: ' . $delivery->getFailureSummary() : ''),
		);
	}

	protected function log(string $message): void
	{
		AddMessage2Log($message, 'mobile');
	}

	/**
	 * Whether the pass reports every step it takes. The trace goes to the logger registered
	 * under TRACE_LOGGER_ID, and the switch is the logger registry of the portal: while the
	 * id is not enabled there, the factory answers with a NullLogger and the trace costs
	 * nothing. This is the single place that asks - so a test states a logger of its own
	 * instead of depending on the settings of whatever portal it runs against.
	 */
	protected function isTraceEnabled(): bool
	{
		return !($this->getTraceLogger() instanceof NullLogger);
	}

	/**
	 * Logger of the verbose trace, resolved once per pass. Where it writes is decided in
	 * `.settings.php` by the id; a portal that configured nothing gets the default file
	 * logger of the core once the id is enabled.
	 */
	protected function getTraceLogger(): LoggerInterface
	{
		return $this->traceLogger ??= (new LoggerFactory())->createById(self::TRACE_LOGGER_ID) ?? new NullLogger();
	}

	/**
	 * One step of the pass, written only while the trace logger is on. The trace is what a
	 * stand needs and a working portal must not pay for, so every call is a bare string
	 * concatenation behind the check - nothing is formatted until the logger says it will
	 * be written.
	 */
	private function trace(string $message): void
	{
		if ($this->isTraceEnabled())
		{
			$this->getTraceLogger()->debug('Onboarding push [dev]: ' . $message);
		}
	}

	/**
	 * What the pass decided for the whole portal before walking anyone: the region and its
	 * scheme, the anchor of the series and the size of the selection. A pass that sent nothing
	 * looks the same as a pass that had nobody to send to without this line.
	 *
	 * The send day itself is not here, because it is not a portal-wide decision: a campaign
	 * region counts it from the last push of each user, a legacy one from the activity of each
	 * user, and every user of the loop is traced with the outcome it ended on.
	 */
	private function tracePassStart(
		RegionConfig $regionConfig,
		?DateTime $anchorDate,
		int $userCount,
		?int $selectionSize = null,
	): void
	{
		if (!$this->isTraceEnabled())
		{
			return;
		}

		$manifest = $regionConfig->getCampaignConfig()?->describeAppliedManifest() ?? '';
		$scheme = $regionConfig->isCampaignDriven() ? 'campaign document' : 'day map';

		$this->trace(
			'pass starts - region ' . $regionConfig->getCode() . ', ' . $scheme
			. ($manifest === '' ? '' : ' (' . $manifest . ')')
			. ', anchor ' . ($anchorDate?->format('Y-m-d') ?? 'of each user')
			. ', send day per user, users: ' . $userCount
			. ($selectionSize === null || $selectionSize === $userCount ? '' : ' of ' . $selectionSize),
		);
	}

	/**
	 * The send day of a user for the trace: a day map names the offset it matched, a campaign
	 * series has no offset to name - only the day it fell due on.
	 */
	private function describeSendDay(?int $scheduledDay, DateTime $scheduledDate): string
	{
		$day = $scheduledDay === null ? 'the series' : 'day ' . $scheduledDay . ' of the series';

		return $day . ' on ' . $scheduledDate->format('Y-m-d');
	}

	/**
	 * Codes of a failed check, for the trace: the messages are for a human reading the log,
	 * the codes are what a decision is matched on, and a stopped pass has to name both.
	 */
	private function describeErrors(Result $validation): string
	{
		$parts = [];

		foreach ($validation->getErrors() as $error)
		{
			$parts[] = (string)$error->getCode() . ' (' . $error->getMessage() . ')';
		}

		return implode(', ', $parts);
	}

	private function describePushTypeOf(PushSendResult $sendResult): string
	{
		$pushType = $sendResult->getPushType();

		return $pushType === null ? '' : ' (' . $pushType . ')';
	}

	private function sendCampaignAnalytics(CampaignConfigProvider $campaignConfig, PushType $pushType): void
	{
		$this->sendConfiguredAnalytics(
			$campaignConfig,
			$pushType,
			$campaignConfig->findStepByPushType($pushType)?->analyticsSendEvent,
		);
	}

	private function sendFinalAnalytics(CampaignConfigProvider $campaignConfig, PushType $pushType): void
	{
		$finalConfig = $this->getFinalConfig($campaignConfig, $pushType);

		$this->sendConfiguredAnalytics($campaignConfig, $pushType, $finalConfig?->analyticsSendEvent);
	}

	/**
	 * Analytics of a campaign push: the document owns the event code and the series
	 * token substituted into its `{series}` placeholder. A document without the token
	 * degrades to the legacy event of the push type.
	 */
	private function sendConfiguredAnalytics(
		CampaignConfigProvider $campaignConfig,
		PushType $pushType,
		?string $code,
	): void
	{
		$series = self::seriesToken($campaignConfig);
		if ($series === '')
		{
			$this->sendAnalytics($pushType);

			return;
		}

		$event = $code !== null && $code !== ''
			? str_replace(self::SERIES_PLACEHOLDER, $series, $code)
			: $pushType->getAnalyticsEvent()
		;

		$this->dispatchAnalytics($event, $series);
	}

	/**
	 * The single way an event of this pipeline leaves it. Both the code and the series are
	 * decided above, so the seam carries them rather than a built event: AnalyticsEvent is
	 * final and reads nothing back, and a test overriding this method has to see what was
	 * counted, not that something was.
	 *
	 * The other half is the transport. Sending for real from a test is not a side effect a
	 * test may have: the debug logger of a stand turns send() into a message in a chat of the
	 * portal plus a pull push, so a suite that sends writes into the portal it measures.
	 */
	protected function dispatchAnalytics(string $event, string $category): void
	{
		(new AnalyticsEvent($event, self::ANALYTICS_TOOL, $category))->send();
	}

	/**
	 * The card of the section a final aims at, empty while the document names none. Only the
	 * document knows which card the campaign meant, so it travels with the push: a tap that
	 * merely brings the section up leaves the user looking for the card themselves.
	 *
	 * @return array<string, mixed>
	 */
	private static function deeplinkPayload(?FinalPushConfig $finalConfig): array
	{
		if ($finalConfig?->deeplinkTarget === null)
		{
			return [];
		}

		return [
			'deeplink' => [
				'menuItemId' => $finalConfig->deeplinkTarget,
				'highlight' => $finalConfig->highlightTarget,
			],
		];
	}

	/**
	 * Click analytics carried to the device inside the push. The click is the other half of
	 * the funnel the server opens with the send event, and only the document knows both codes:
	 * it owns the series token substituted into `{series}` and it names the steps its own way -
	 * the help final is `help` there and `implementation_help` in the code. A client left to
	 * assemble the code itself reports clicks nobody can join to their sends.
	 *
	 * An empty payload is the legacy series and any document without a series token: the client
	 * then falls back to the event of its own handler, the way it always did.
	 *
	 * @return array<string, mixed>
	 */
	private function clickAnalyticsPayload(CampaignConfigProvider $campaignConfig, ?string $code): array
	{
		$series = self::seriesToken($campaignConfig);
		if ($series === '' || $code === null || $code === '')
		{
			return [];
		}

		return [
			'analytics' => [
				'event' => str_replace(self::SERIES_PLACEHOLDER, $series, $code),
				'category' => $series,
			],
		];
	}

	/**
	 * The series token of the document, empty when it declares none. Both halves of the
	 * analytics of a push - the event it is sent under and the one its click reports - are
	 * counted under it, so where it lives in the document is one fact.
	 */
	private static function seriesToken(CampaignConfigProvider $campaignConfig): string
	{
		return (string)($campaignConfig->getAnalytics()['series'] ?? '');
	}

	private function sendAnalytics(PushType $type): void
	{
		$this->dispatchAnalytics($type->getAnalyticsEvent(), self::LEGACY_ANALYTICS_TYPE);
	}
}
