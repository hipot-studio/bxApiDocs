<?php

namespace Bitrix\Call;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Service\MicroService\BaseSender;
use Bitrix\Call\Call\PlainCall;
use Bitrix\Call\Call\ConferenceCall;

/**
 * @internal
 */
class ControllerClient extends BaseSender
{
	// Per-process circuit breaker for room-finish commands: after the first
	// SLOW failure (transport timeout) further finish requests in the same
	// process are skipped, so mass finish loops (cleanup agents, stuck-call
	// termination) do not accumulate sequential HTTP timeouts when the
	// callcontroller is degraded. Fast business errors cost nothing and must
	// not leave the rest of a mass loop with open rooms.
	private static bool $finishRequestsSuspended = false;

	private const FINISH_SUSPEND_SECONDS = 3.0;

	// Per-process breaker for track list reads: a mass loop must not pay one timeout per call.
	private static bool $tracksRequestsSuspended = false;

	private const TRACKS_SUSPEND_SECONDS = 3.0;

	// Seam of the threshold above: replaced in tests, which prove the breaker without spending it.
	private static ?float $tracksSuspendSecondsOverride = null;

	// Ceiling on track list reads of one process, on top of the breaker above: a service that
	// answers just under the suspend threshold never trips it, and a batch of expectation agents
	// (up to agents_cron_limit = 1000 in one process, or the request-end queue of a hit on an
	// installation without crontab) would then pay that delay once per call. Past the ceiling the
	// process stops asking and leaves those waits to the next run - a skipped read is an
	// unanswered one, so no check is spent on it.
	private static int $tracksRequestsMade = 0;

	private const TRACKS_REQUESTS_PER_PROCESS = 50;

	// Absolute budget of the time the whole follow-up may cost one process in outbound I/O, on top
	// of the limits above: each of them counts its own dimension - a single slow answer, and the
	// number of answers - and none of them adds them up. A service answering just under the suspend
	// threshold trips nothing at all, so the ceiling alone allows 50 x 2.9 seconds of synchronous
	// waiting in one process, and a check pays for three requests, not one: the telemetry of the
	// question, the read itself and the telemetry of the answer. A healthy request costs
	// milliseconds, so half a minute of accumulated waiting is a degraded host and not normal work.
	//
	// One budget for all three, and not one per channel: they go to the same host, so they degrade
	// together, and per-channel budgets would let the process spend each of them in turn. Past the
	// budget the process stops asking and leaves the remaining waits to the next run, exactly as at
	// the ceiling.
	private static float $followUpIoSecondsSpent = 0.0;

	private const FOLLOW_UP_IO_TIME_BUDGET_SECONDS = 30.0;

	// The share of that budget the telemetry may spend. Below one, because the two channels are not
	// worth the same: a read delivers the files of a call to its chat, while a send carries an event
	// nobody waits for - and the check sends before it reads, so an equal share would let the
	// observability of a degraded host consume the budget of the very reads it observes.
	private const FOLLOW_UP_IO_TELEMETRY_SHARE = 0.5;

	// Seam of the budget above: replaced in tests, which prove it without spending it.
	private static ?float $followUpIoTimeBudgetOverride = null;

	// Per-process breaker for follow-up telemetry. Unlike the reads above it takes more than one
	// costly send: this channel carries the observability of the whole follow-up chain, and a
	// single slow send must not blind it for the rest of the process.
	private static bool $telemetryRequestsSuspended = false;

	private static int $telemetryCostlySends = 0;

	private const TELEMETRY_SUSPEND_SECONDS = 3.0;

	// Seam of the threshold above: replaced in tests, which prove the breaker without spending it.
	private static ?float $telemetrySuspendSecondsOverride = null;

	private const TELEMETRY_SUSPEND_SENDS = 3;

	// The same breaker for the track events, counted apart from the follow-up one: a batch of
	// tracks pays several events per call, and a console agent pays them synchronously now that
	// the module runs its deferred work inline there {@see \Bitrix\Call\Background\JobDispatcher}.
	// Its own counter and its own flag, because a track channel that went quiet must not blind the
	// telemetry of the follow-up, nor the other way round - and the time it spends stays out of the
	// shared I/O budget above, which is split with the reads of the follow-up and sized for them.
	private static bool $trackTelemetryRequestsSuspended = false;

	private static int $trackTelemetryCostlySends = 0;

	// Budget of the time the track events may cost one process, the way the follow-up has one for
	// its own I/O. The breaker above only sees a send that was costly by itself, so a host answering
	// just under its threshold trips nothing at all - and a pass of the agent finalizes a batch of
	// tracks, several events each, with no ceiling on their number. Past the budget the channel goes
	// quiet for the rest of the process: an event nobody waits for must not spend the window of the
	// agent runner, which is one window for every module of the portal.
	//
	// Its own budget and not a share of the one above: that one is split with the reads that deliver
	// the files of a call and is sized for them, and spending it here would starve the work of the
	// pass to pay for its observability. Sized like the share the follow-up telemetry is allowed of
	// it, because it answers the same question - how long one process may wait on events.
	//
	// No ceiling on the number of sends next to it: a healthy host answers in milliseconds, so a
	// count would blind the telemetry of a busy process long before it protected a degraded one,
	// while the time of every send is counted here whatever it answered.
	private static float $trackTelemetryIoSecondsSpent = 0.0;

	private const TRACK_TELEMETRY_IO_TIME_BUDGET_SECONDS = 15.0;

	// Seam of the budget above: replaced in tests, which prove it without spending it.
	private static ?float $trackTelemetryIoTimeBudgetOverride = null;

	private const
		TELEMETRY_CHANNEL_FOLLOW_UP = 'followUp',
		TELEMETRY_CHANNEL_TRACK = 'track'
	;

	private const SERVICE_MAP = [
		'ru' => 'https://videocalls.bitrix24.tech',
		'eu' => 'https://videocalls-de.bitrix.info',
		'us' => 'https://videocalls-us.bitrix.info',
	];

	private array $httpClientParameters = [];

	/**
	 * Returns controller service endpoint url.
	 *
	 * @return string
	 * @param string $region Portal region.
	 */
	public function getEndpoint(string $region): string
	{
		$endpoint = Option::get('call', 'call_server_url');

		if (empty($endpoint))
		{
			if (in_array($region, Library::REGION_CIS, true))
			{
				$endpoint = self::SERVICE_MAP['ru'];
			}
			elseif (in_array($region, Library::REGION_EU, true))
			{
				$endpoint = self::SERVICE_MAP['eu'];
			}
			else
			{
				$endpoint = self::SERVICE_MAP['us'];
			}
		}
		elseif (!(mb_strpos($endpoint, 'https://') === 0 || mb_strpos($endpoint, 'http://') === 0))
		{
			$endpoint = 'https://' . $endpoint;
		}

		return $endpoint;
	}

	/**
	 * @return string
	 */
	protected function getClientServerName(): string
	{
		$publicUrl = Library::getPortalPublicUrl();
		if (!empty($publicUrl))
		{
			return $publicUrl;
		}

		return parent::getClientServerName();
	}

	/**
	 * Returns API endpoint for the service.
	 *
	 * @return string
	 */
	public function getServiceUrl(): string
	{
		$region = \Bitrix\Main\Application::getInstance()->getLicense()->getRegion() ?: '';

		return $this->getEndpoint($region);
	}

	/**
	 * @see \Bitrix\CallController\Controller\InternalApi::createCallAction
	 * @param Call $call
	 * @return Result
	 */
	public function createCall(Call $call): Result
	{
		$action = 'callcontroller.InternalApi.createCall';
		$data = [
			'callType' => 'call',
			'roomType' => $call->getType(),
			'uuid' => $call->getUuid(),
			'initiatorUserId' => $call->getInitiatorId(),
			'callId' => $call->getId(),
			'version' => \Bitrix\Main\ModuleManager::getVersion('call'),
			'usersCount' => count($call->getUsers()),
			'enableRecorder' => (int)$call->isAiAnalyzeEnabled(),
		];

		if ($call instanceof ConferenceCall)
		{
			$data['callType'] = 'conference';
		}

		if ($call instanceof PlainCall)
		{
			$action = 'callcontroller.InternalApi.createPlain';
			$data['callType'] = 'plain';
			$this->httpClientParameters = [
				//'waitResponse' => false,
				'socketTimeout' => 5,
				'streamTimeout' => 5,
			];
		}
		else
		{
			$data = array_merge($data, [
				'secretKey' => $call->getSecretKey(),
				'maxParticipants' => Settings::getMaxCallServerParticipants(),
			]);
			$this->httpClientParameters = [
				'waitResponse' => true,
				'socketTimeout' => 10,
				'streamTimeout' => 15,
			];
		}

		return $this->performRequest($action, $data);
	}

	/**
	 * @see \Bitrix\CallController\Controller\InternalApi::finishCallAction
	 * @see \Bitrix\CallController\Controller\InternalApi::finishPlainAction
	 * @param Call $call
	 * @return Result
	 */
	public function finishCall(Call $call): Result
	{
		$data = [
			'uuid' => $call->getUuid(),
			'actionUserId' => $call->getActionUserId() ?? 0,
		];

		$this->httpClientParameters = [
			'socketTimeout' => 5,
			'streamTimeout' => 5,
		];

		$action = 'callcontroller.InternalApi.finishCall';
		if ($call instanceof ConferenceCall)
		{
			$data['callType'] = 'conference';
		}
		if ($call instanceof PlainCall)
		{
			$action = 'callcontroller.InternalApi.finishPlain';
			$data['callType'] = 'plain';
		}

		return $this->performFinishRequest($action, $data);
	}

	/**
	 * Finishes a plain call room by its uuid when there is no call entity
	 * on the portal yet (quick hangup racing the startCall webhook).
	 *
	 * @see \Bitrix\CallController\Controller\InternalApi::finishPlainAction
	 * @param string $uuid
	 * @param int $actionUserId
	 * @return Result
	 */
	public function finishPlainByUuid(string $uuid, int $actionUserId): Result
	{
		$data = [
			'uuid' => $uuid,
			'actionUserId' => $actionUserId,
			'callType' => 'plain',
		];

		$this->httpClientParameters = [
			'socketTimeout' => 5,
			'streamTimeout' => 5,
		];

		return $this->performFinishRequest('callcontroller.InternalApi.finishPlain', $data);
	}

	/**
	 * @param string $action
	 * @param array $data
	 * @return Result
	 */
	private function performFinishRequest(string $action, array $data): Result
	{
		if (self::$finishRequestsSuspended)
		{
			return (new Result())->addError(new Error(
				'Room finish request skipped: previous attempt failed in this process',
				'finish_requests_suspended',
			));
		}

		$startedAt = microtime(true);
		$result = $this->performRequest($action, $data);
		if (!$result->isSuccess() && (microtime(true) - $startedAt) >= self::FINISH_SUSPEND_SECONDS)
		{
			self::$finishRequestsSuspended = true;
		}

		return $result;
	}

	/**
	 * @see \Bitrix\CallController\Controller\InternalApi::startTrackAction
	 * @param Call $call
	 * @return Result
	 */
	public function startTrack(Call $call): Result
	{
		$data = [
			'uuid' => $call->getUuid(),
			'actionUserId' => $call->getActionUserId() ?? 0,
		];

		$this->httpClientParameters = [
			'socketTimeout' => 10,
			'streamTimeout' => 10,
		];

		if ($call instanceof ConferenceCall)
		{
			$data['callType'] = 'conference';
		}
		if ($call instanceof PlainCall)
		{
			$data['callType'] = 'plain';
		}

		return $this->performRequest('callcontroller.InternalApi.startTrack', $data);
	}

	/**
	 * @see \Bitrix\CallController\Controller\InternalApi::stopTrackAction
	 * @param Call $call
	 * @return Result
	 */
	public function stopTrack(Call $call): Result
	{
		$data = [
			'uuid' => $call->getUuid(),
			'actionUserId' => $call->getActionUserId() ?? 0,
		];

		$this->httpClientParameters = [
			'socketTimeout' => 10,
			'streamTimeout' => 10,
		];

		if ($call instanceof ConferenceCall)
		{
			$data['callType'] = 'conference';
		}
		if ($call instanceof PlainCall)
		{
			$data['callType'] = 'plain';
		}

		return $this->performRequest('callcontroller.InternalApi.stopTrack', $data);
	}

	/**
	 * @see \Bitrix\CallController\Controller\InternalApi::destroyTrackAction
	 * @param Call $call
	 * @return Result
	 */
	public function destroyTrack(Call $call): Result
	{
		$data = [
			'uuid' => $call->getUuid(),
			'actionUserId' => $call->getActionUserId() ?? 0,
		];

		$this->httpClientParameters = [
			'socketTimeout' => 10,
			'streamTimeout' => 10,
		];

		if ($call instanceof ConferenceCall)
		{
			$data['callType'] = 'conference';
		}
		if ($call instanceof PlainCall)
		{
			$data['callType'] = 'plain';
		}

		return $this->performRequest('callcontroller.InternalApi.destroyTrack', $data);
	}

	/**
	 * @see \Bitrix\CallController\Controller\InternalApi::dropTrackAction
	 * @param Track $track
	 * @return Result
	 */
	public function dropTrack(Track $track): Result
	{
		$call = $track->fillCall();
		$data = [
			'uuid' => $call->getUuid(),
			'trackId' => $track->getExternalTrackId(),
			'chatId' => $call->getChatId(),
		];

		$this->httpClientParameters = [
			'socketTimeout' => 5,
			'streamTimeout' => 5,
		];

		return $this->performRequest('callcontroller.InternalApi.dropTrack', $data);
	}

	/**
	 * Returns the state of every file the service keeps for the call.
	 *
	 * An empty list means "the call has no files", any error means "the service did not answer":
	 * a caller waiting for a file must tell the two apart.
	 *
	 * @see \Bitrix\CallController\Controller\InternalApi::getTracksAction
	 * @param string $uuid Uuid of the call on the portal.
	 * @return Result Data of a successful result: ['tracks' => list of file states].
	 */
	public function getTracks(string $uuid): Result
	{
		$this->httpClientParameters = [
			'waitResponse' => true,
			'socketTimeout' => 5,
			'streamTimeout' => 5,
		];

		$result = $this->performTracksRequest('callcontroller.InternalApi.getTracks', ['uuid' => $uuid]);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$tracks = $result->getData()['tracks'] ?? null;
		if (!is_array($tracks))
		{
			return (new Result())->addError(new Error(
				'Service response carries no track list',
				'tracks_response_malformed',
			));
		}

		return $result;
	}

	private static function getTracksSuspendSeconds(): float
	{
		return self::$tracksSuspendSecondsOverride ?? self::TRACKS_SUSPEND_SECONDS;
	}

	/**
	 * Why this process would not ask the service for a track list, or null when it still would -
	 * the breaker, the ceiling and the time budget declared above, asked without spending any.
	 *
	 * Public because a caller that reports its questions to telemetry has to know the answer
	 * before it announces one: a check that never reached the service must not be counted as a
	 * request to it {@see \Bitrix\Call\Integration\AI\CallAIService::askServiceForFiles()}.
	 */
	public static function getTracksRequestRefusal(): ?Error
	{
		if (self::$tracksRequestsSuspended)
		{
			return new Error(
				'Track list request skipped: previous attempt failed in this process',
				'tracks_requests_suspended',
			);
		}

		if (self::$tracksRequestsMade >= self::TRACKS_REQUESTS_PER_PROCESS)
		{
			return new Error(
				'Track list request skipped: this process has reached its request ceiling',
				'tracks_requests_exhausted',
			);
		}

		if (self::$followUpIoSecondsSpent >= self::getFollowUpIoTimeBudgetSeconds())
		{
			return new Error(
				'Track list request skipped: this process has spent its time budget on follow-up I/O',
				'tracks_time_budget_exhausted',
			);
		}

		return null;
	}

	private static function getFollowUpIoTimeBudgetSeconds(): float
	{
		return self::$followUpIoTimeBudgetOverride ?? self::FOLLOW_UP_IO_TIME_BUDGET_SECONDS;
	}

	/**
	 * The part of that budget the telemetry stops at, so a degraded host cannot spend on events the
	 * time the reads of the same process need for the files of the calls.
	 */
	private static function getTelemetryTimeBudgetSeconds(): float
	{
		return self::getFollowUpIoTimeBudgetSeconds() * self::FOLLOW_UP_IO_TELEMETRY_SHARE;
	}

	/**
	 * Trips the breaker on a slow answer, whatever it says: a fast business error does not stop the
	 * rest of a mass loop, while a service that is merely slow costs the process the same time
	 * whether it answers with an error or with the files.
	 *
	 * Bounded by the ceiling of the process on top of that, and by the accumulated time of the reads,
	 * for the service that answers slowly but always under the threshold: the time of every read is
	 * counted whatever it answered, because the process paid it either way.
	 */
	private function performTracksRequest(string $action, array $data): Result
	{
		$refusal = self::getTracksRequestRefusal();
		if ($refusal)
		{
			return (new Result())->addError($refusal);
		}

		self::$tracksRequestsMade++;

		$startedAt = microtime(true);
		try
		{
			$result = $this->performRequest($action, $data);
		}
		catch (\Throwable $exception)
		{
			// A broken transport stays an error of the request: the hit must walk the rest of the calls.
			$result = (new Result())->addError(new Error($exception->getMessage(), 'tracks_request_failed'));
		}

		$spent = microtime(true) - $startedAt;
		self::$followUpIoSecondsSpent += $spent;

		if ($spent >= self::getTracksSuspendSeconds())
		{
			self::$tracksRequestsSuspended = true;
		}

		return $result;
	}

	/**
	 * @see \Bitrix\CallController\Controller\Settings::registerKeyAction
	 * @param string $key
	 * @return Result
	 */
	public function registerCallKey(string $key): Result
	{
		$data = [
			'privateKey' => $key,
		];

		$this->httpClientParameters = [
			'waitResponse' => true,
			'socketTimeout' => 5,
			'streamTimeout' => 5,
		];

		return $this->performRequest('callcontroller.Settings.registerKey', $data);
	}

	/**
	 * @see \Bitrix\CallController\Controller\Settings::getRegistrationDataAction
	 * @return Result
	 */
	public function getRegistrationData(): Result
	{
		$this->httpClientParameters = [
			'waitResponse' => true,
			'socketTimeout' => 5,
			'streamTimeout' => 5,
		];

		return $this->performRequest('callcontroller.Settings.getRegistrationData', []);
	}

	/**
	 * @see \Bitrix\CallController\Controller\Settings::unregisterKeyAction
	 * @return Result
	 */
	public function unregisterCallKey(): Result
	{
		$this->httpClientParameters = [
			'waitResponse' => false,
			'socketTimeout' => 5,
			'streamTimeout' => 5,
		];

		return $this->performRequest('callcontroller.Settings.unregisterKey');
	}

	/**
	 * @see \Bitrix\CallController\Controller\Settings::checkPublicUrlAction
	 * @return Result
	 */
	public function checkPublicUrl(string $publicUrl): Result
	{
		$this->httpClientParameters = [
			'waitResponse' => true,
			'socketTimeout' => 10,
			'streamTimeout' => 10,
		];

		return $this->performRequest('callcontroller.Settings.checkPublicUrl', ['publicUrl' => $publicUrl]);
	}

	/**
	 * @see \Bitrix\CallController\Controller\InternalApi::aiFollowUpTelemetryAction
	 * @param array $telemetryData
	 * @return Result
	 */
	public function sendAIFollowUpTelemetry(array $telemetryData): Result
	{
		$this->httpClientParameters = [
			'waitResponse' => false,
			'socketTimeout' => 5,
			'streamTimeout' => 5,
		];

		return $this->performTelemetryRequest(
			'callcontroller.InternalApi.aiFollowUpTelemetry',
			$telemetryData,
			self::TELEMETRY_CHANNEL_FOLLOW_UP,
		);
	}

	private static function getTelemetrySuspendSeconds(): float
	{
		return self::$telemetrySuspendSecondsOverride ?? self::TELEMETRY_SUSPEND_SECONDS;
	}

	/**
	 * The time budget of the track events {@see self::TRACK_TELEMETRY_IO_TIME_BUDGET_SECONDS}.
	 */
	private static function getTrackTelemetryTimeBudgetSeconds(): float
	{
		return self::$trackTelemetryIoTimeBudgetOverride ?? self::TRACK_TELEMETRY_IO_TIME_BUDGET_SECONDS;
	}

	/**
	 * Why this process would not send telemetry of that channel any more, or null while it still
	 * would - the breaker declared above and the time budget of the channel: the share of the
	 * shared follow-up I/O budget for the follow-up {@see self::getTelemetryTimeBudgetSeconds()},
	 * its own for the track events {@see self::getTrackTelemetryTimeBudgetSeconds()}.
	 *
	 * Each limit answers with a code of its own, the way the track reads tell their breaker from
	 * their ceiling {@see self::getTracksRequestRefusal()}: a channel that went quiet has to say
	 * which of the two stopped it, or the logs of a slow host and of an unreachable one read alike.
	 */
	private static function getTelemetryRefusal(string $channel): ?Error
	{
		if ($channel === self::TELEMETRY_CHANNEL_TRACK)
		{
			if (self::$trackTelemetryRequestsSuspended)
			{
				return new Error(
					'Track telemetry skipped: sends of this process were too costly',
					'telemetry_requests_suspended',
				);
			}

			if (self::$trackTelemetryIoSecondsSpent >= self::getTrackTelemetryTimeBudgetSeconds())
			{
				return new Error(
					'Track telemetry skipped: this process has spent its time budget on track events',
					'telemetry_requests_exhausted',
				);
			}

			return null;
		}

		if (self::$telemetryRequestsSuspended)
		{
			return new Error(
				'Follow-up telemetry skipped: sends of this process were too costly',
				'telemetry_requests_suspended',
			);
		}

		if (self::$followUpIoSecondsSpent >= self::getTelemetryTimeBudgetSeconds())
		{
			return new Error(
				'Follow-up telemetry skipped: this process has spent its share of the follow-up I/O budget',
				'telemetry_requests_exhausted',
			);
		}

		return null;
	}

	/**
	 * Suspends the telemetry of the channel once enough sends have cost this process real time.
	 * The reads the follow-up accompanies are protected by a breaker of their own, and two
	 * unprotected sends per read would make that protection pointless: an unreachable telemetry
	 * host costs up to the socket timeout per event even though the answer is never read.
	 *
	 * A send is judged by what it cost and not by whether it succeeded: with waitResponse => false
	 * even a delivered send comes back as EMPTY_SERVER_RESPONSE, so counting failures would blind a
	 * healthy channel. A send the transport could not make at all counts as costly - it will not
	 * arrive on the next try either.
	 *
	 * What every send cost is added up on top of that, costly or not, into the time budget of its
	 * channel: a host answering just under the costliness threshold leaves the breaker asleep, and
	 * the process would keep paying that delay per event to the end of the batch. Past the budget
	 * the channel goes quiet whatever any single send looked like.
	 *
	 * Which budget is what separates the two channels. The follow-up spends the one it shares with
	 * its reads, because what it spent is gone from them as well - it is the same host that made
	 * them both slow. The track events have a budget of their own {@see self::getTelemetryRefusal()}
	 * and share nothing but this code and the thresholds: their pass has no reads to be fair to, and
	 * spending the budget of the follow-up would starve the reads that deliver the files of a call.
	 */
	private function performTelemetryRequest(string $action, array $telemetryData, string $channel): Result
	{
		$refusal = self::getTelemetryRefusal($channel);
		if ($refusal)
		{
			return (new Result())->addError($refusal);
		}

		$startedAt = microtime(true);
		try
		{
			$result = $this->performRequest($action, $telemetryData);
			$unsent = false;
		}
		catch (\Throwable $exception)
		{
			// Telemetry is not worth an exception: it runs from a background job next to real work.
			$result = (new Result())->addError(new Error($exception->getMessage(), 'telemetry_request_failed'));
			$unsent = true;
		}

		$spent = microtime(true) - $startedAt;
		if ($channel === self::TELEMETRY_CHANNEL_TRACK)
		{
			self::$trackTelemetryIoSecondsSpent += $spent;
		}
		else
		{
			self::$followUpIoSecondsSpent += $spent;
		}

		if ($unsent || $spent >= self::getTelemetrySuspendSeconds())
		{
			self::countCostlyTelemetrySend($channel);
		}

		return $result;
	}

	private static function countCostlyTelemetrySend(string $channel): void
	{
		if ($channel === self::TELEMETRY_CHANNEL_TRACK)
		{
			self::$trackTelemetryCostlySends++;
			if (self::$trackTelemetryCostlySends >= self::TELEMETRY_SUSPEND_SENDS)
			{
				self::$trackTelemetryRequestsSuspended = true;
			}

			return;
		}

		self::$telemetryCostlySends++;
		if (self::$telemetryCostlySends >= self::TELEMETRY_SUSPEND_SENDS)
		{
			self::$telemetryRequestsSuspended = true;
		}
	}

	/**
	 * @see \Bitrix\CallController\Controller\InternalApi::trackTelemetryAction
	 * @param array $telemetryData
	 * @return Result
	 */
	public function sendTrackTelemetry(array $telemetryData): Result
	{
		$this->httpClientParameters = [
			'waitResponse' => false,
			'socketTimeout' => 5,
			'streamTimeout' => 5,
		];

		return $this->performTelemetryRequest(
			'callcontroller.InternalApi.trackTelemetry',
			$telemetryData,
			self::TELEMETRY_CHANNEL_TRACK,
		);
	}

	/**
	 * @see \Bitrix\CallController\Controller\InternalApi::cloudRecordTelemetryAction
	 * @param array $telemetryData
	 * @return Result
	 */
	public function sendCloudRecordTelemetry(array $telemetryData): Result
	{
		$this->httpClientParameters = [
			'waitResponse' => false,
			'socketTimeout' => 5,
			'streamTimeout' => 5,
		];

		return $this->performTelemetryRequest(
			'callcontroller.InternalApi.cloudRecordTelemetry',
			$telemetryData,
			self::TELEMETRY_CHANNEL_TRACK,
		);
	}

	public function getHttpClientParameters(): array
	{
		return array_merge(
			parent::getHttpClientParameters(),
			['headers' => [
				'User-Agent' => 'Bitrix Call Client '.\Bitrix\Main\Service\MicroService\Client::getPortalType(),
				'Referer' => \Bitrix\Main\Service\MicroService\Client::getServerName(),
			]],
			$this->httpClientParameters
		);
	}
}
