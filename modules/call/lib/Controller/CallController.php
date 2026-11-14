<?php

namespace Bitrix\Call\Controller;

use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Service\MicroService\BaseReceiver;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Call\Call;
use Bitrix\Call\CallUser;
use Bitrix\Call\CallFactory;
use Bitrix\Call\Recent;
use Bitrix\Call\Error;
use Bitrix\Call\NotifyService;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Track\TrackError;
use Bitrix\Call\Track\TrackService;
use Bitrix\Call\DTO\TrackFileRequest;
use Bitrix\Call\DTO\TrackErrorRequest;
use Bitrix\Call\DTO\PingTracksRequest;
use Bitrix\Call\DTO\ControllerRequest;
use Bitrix\Call\DTO\FollowUpRestartRequest;
use Bitrix\Call\Track\Downloader\DownloadAgent;
use Bitrix\Call\Track\CloudRecordExpectationAgent;
use Bitrix\Call\Call\Registry;
use Bitrix\Call\Model\CallTable;
use Bitrix\Call\Model\CallTrackTable;
use Bitrix\Call\Integration\AI\CallAIError;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Integration\AI\CallAIService;
use Bitrix\Call\Integration\AI\ChatMessage;
use Bitrix\Call\Integration\AI\SenseType;
use Bitrix\Call\Analytics\FollowUpAnalytics;
use Bitrix\Call\Analytics\TrackAnalytics;
use Bitrix\Call\Analytics\CloudRecordAnalytics;
use Bitrix\Call\Controller\Filter\UniqueRequestFilter;

/**
 * @internal
 */
class CallController extends BaseReceiver implements IdempotentReplayable
{
	use IdempotentReplayableTrait;

	protected const LOCK_TTL = 10; // in seconds

	/**
	 * The restart does not queue behind another restart of the same call: it answers that one is
	 * running and lets the operator decide. A wait would end in a second set of paid tasks for the
	 * same call, which is the very thing the lock is there to prevent.
	 */
	protected const RESTART_LOCK_TIMEOUT = 0; // in seconds

	/**
	 * Refusals of the restart of the analysis, as the admin panel of the service reports them to
	 * the operator. The call that is not there and the recording a user destroyed answer with the
	 * codes the intake of a file already answers by.
	 */
	private const
		ERROR_REQUEST_ID_REQUIRED = 'request_id_required',
		ERROR_UNKNOWN_SENSE = 'unknown_sense',
		ERROR_SENSE_NOT_ALLOWED = 'sense_not_allowed',
		ERROR_MODE_NOT_SUPPORTED = 'mode_not_supported',
		ERROR_RESTART_IN_PROGRESS = 'restart_in_progress',
		ERROR_RECORD_DESTROYED = 'record_destroyed'
	;

	/**
	 * The kinds of file a new delivery may carry. Every one of them is a type the intake of a file
	 * already writes onto a row of the call {@see \Bitrix\Call\Track}; anything else names no row
	 * this portal can read, and the list is closed here rather than left to the row, because the
	 * intake creates a row where the call has none and a value it does not understand becomes an
	 * empty TYPE nothing can be done with afterwards.
	 */
	private const DELIVERABLE_TRACK_TYPES = [
		\Bitrix\Call\Track::TYPE_TRACK_PACK,
		\Bitrix\Call\Track::TYPE_RECORD,
		\Bitrix\Call\Track::TYPE_VIDEO_RECORD,
		\Bitrix\Call\Track::TYPE_VIDEO_PREVIEW,
	];

	/**
	 * The shape of the key of a request, the one the sender builds and checks by
	 * {@see \Bitrix\CallController\Idempotence::isValidRequestId()}: the version and variant
	 * nibbles are free, because a key derived from an operation of the panel is not a generated
	 * v4 uuid.
	 */
	private const REQUEST_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	public function getAutoWiredParameters(): array
	{
		return array_merge([
			new ExactParameter(
				TrackFileRequest::class,
				'trackFile',
				function ($className, $params = [])
				{
					return new $className($this->getSourceParametersList()[0]);
				}
			),
			new ExactParameter(
				TrackErrorRequest::class,
				'trackError',
				function ($className, $params = [])
				{
					return new $className($this->getSourceParametersList()[0]);
				}
			),
			new ExactParameter(
				ControllerRequest::class,
				'callRequest',
				function ($className, $params = [])
				{
					return new $className($this->getSourceParametersList()[0]);
				}
			),
			new ExactParameter(
				PingTracksRequest::class,
				'request',
				function ($className, $params = [])
				{
					return new $className($this->getSourceParametersList()[0]);
				}
			),
			new ExactParameter(
				FollowUpRestartRequest::class,
				'restartRequest',
				function ($className, $params = [])
				{
					return new $className($this->getSourceParametersList()[0]);
				}
			),
		], parent::getAutoWiredParameters());
	}

	/**
	 * @return array[]
	 */
	public function configureActions(): array
	{
		return [
			'finishCall' => [
				'+prefilters' => [
					new UniqueRequestFilter(),
				],
			],
			'disconnectUser' => [
				'+prefilters' => [
					new UniqueRequestFilter(),
				],
			],
			'trackReady' => [
				'+prefilters' => [
					new UniqueRequestFilter(),
				],
			],
			'trackError' => [
				'+prefilters' => [
					new UniqueRequestFilter(),
				],
			],
			'restartFollowUp' => [
				'+prefilters' => [
					// The default TTL (24h), the one an operator resend is deduplicated by: the key
					// is the request id as it arrives, and the sender puts the marks of the mode
					// into it, so a repeat of one press is replayed while another ask of the same
					// press arrives with an id of its own and runs
					// {@see UniqueRequestFilter::resolveRequestId()}.
					new UniqueRequestFilter(),
				],
			],
			'pingTracks' => [
				'+prefilters' => [
					// Hot-path TTL (60s): a fire-and-forget wake-up ping carries a
					// fresh requestId every call, so dedup only needs to cover the
					// command-queue redelivery window — not the default 24h, which
					// would pile up idempotency keys across every portal/track/tick.
					new UniqueRequestFilter(UniqueRequestFilter::TTL_HOT_PATH),
				],
			],
		];
	}


	/**
	 * @restMethod call.CallController.finishCall
	 */
	public function finishCallAction(ControllerRequest $callRequest): ?array
	{
		$callUuid = $callRequest->callUuid;

		// Serialize the FINISH paths - this media-backend receiver, JWT finish
		// (Controller\Call) and legacy finish (CallManager) - on the shared per-call
		// call_state lock. The receiver used to finish without it, so a media-backend
		// finish could race a concurrent finish and lose the FINISHED transition,
		// leaving the call stuck in INVITING. This lock does NOT cover every state
		// mutator: legacy CallManager invite holds a different lock (invite_call_* /
		// _call_{id} for PLAIN) and is not serialized against this receiver.
		// call_state is the single outer lock here (no user lock), so there is no
		// lock-order deadlock with the JWT user->call_state path.
		$lockName = "call_state_call_{$callUuid}";
		if (!Application::getConnection()->lock($lockName, self::LOCK_TTL))
		{
			$this->addError(new Error('could_not_lock', 'Could not get exclusive lock'));

			return null;
		}

		$committedHere = false;
		$sendRecoveryFinish = false;
		$call = null;
		try
		{
			// Fresh read under the lock (provider-agnostic) so a transition committed by
			// a concurrent finish while we waited for the lock is observed.
			$call = CallFactory::searchActiveByUuid($callUuid);
			if (!isset($call))
			{
				$this->addError(new Error(Error::CALL_NOT_FOUND));

				return null;
			}

			$userId = $callRequest->userId ?: $call->getInitiatorId();
			$call->setActionUserId($userId);

			if ($call->getState() === Call::STATE_FINISHED)
			{
				// Idempotent replay of an already-finished call: only flag the recovery
				// re-broadcast. Roster load + Pull fan-out are deferred to after unlock
				// so they do not hold the shared lock.
				$sendRecoveryFinish = true;
			}
			else
			{
				// Only the atomic FINISHED transition (updateState CAS) runs under the
				// lock; finishSideEffects() (controller teardown, chat message, Pull)
				// runs off-lock. Never save() here - it would lost-update the STATE.
				$committedHere = $call->commitFinish();
			}
		}
		finally
		{
			Application::getConnection()->unlock($lockName);
		}

		if ($committedHere)
		{
			// Exactly-once side effects; Recent cache rebuild and sibling teardown run
			// off-lock via the call:onCallFinished handler.
			$call->finishSideEffects();
		}
		elseif ($sendRecoveryFinish)
		{
			// Off-lock recovery re-broadcast for stuck clients; self-rate-limited by the
			// recovery idempotence key. Refresh Recent only when it actually broadcast.
			if ($call->getSignaling()->sendFinish(forRecovery: true))
			{
				Recent::scheduleUpdateCallCache($call->getId());
			}
		}
		elseif ($call instanceof Call)
		{
			// Lost the race without a recovery broadcast: just refresh the cache.
			Recent::scheduleUpdateCallCache($call->getId());
		}

		return ['result' => true];
	}

	/**
	 * @restMethod call.CallController.disconnectUser
	 */
	public function disconnectUserAction(ControllerRequest $callRequest): ?array
	{
		$call = CallFactory::searchActiveByUuid($callRequest->callUuid);
		if (!isset($call))
		{
			$this->addError(new Error(Error::CALL_NOT_FOUND));

			return null;
		}

		$callUser = $call->getUser($callRequest->userId);
		if ($callUser)
		{
			$callUser->updateState(CallUser::STATE_IDLE);
		}

		$call->getSignaling()->sendHangup($callRequest->userId, $call->getUsers(), null);

		return ['result' => true];
	}

	/**
	 * @restMethod call.CallController.trackReady
	 */
	public function trackReadyAction(TrackFileRequest $trackFile): ?array
	{
		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
		}

		$call = CallFactory::searchActiveByUuid($trackFile->callUuid);
		if (!isset($call))
		{
			$log && $logger->error("Call uuid:{$trackFile->callUuid} not found");
			$this->addError(new Error(Error::CALL_NOT_FOUND));
			return null;
		}

		if ($call->isRecordDestroyed())
		{
			// This callback makes the row of the file itself, without the intake that answers the
			// deletion for every other path {@see TrackService::intakeServiceFile()}, so the mark is
			// read here too: the service is never told of a destroy in the modern scheme and can
			// deliver a file of the call long after it {@see \Bitrix\Call\Call::isRecordDestroyed()}.
			// The instance is enough here, unlike at the intake: the call was loaded by this very
			// request, not before a request to the service.
			$log && $logger->error(
				"Ignoring track:{$trackFile->url}, track #{$trackFile->trackId}."
				. " The record of call #{$call->getId()} was destroyed by a user."
			);
			$this->addError(new Error('record_destroyed', 'The record of the call was destroyed'));

			return null;
		}

		$needsAiAnalyzeRestore = false;
		if (!$call->isAiAnalyzeEnabled())
		{
			if (!$trackFile->manualRetry)
			{
				$log && $logger->error(
					"Ignoring track:{$trackFile->url}, track #{$trackFile->trackId}."
					. " FollowUp was disabled for call #{$call->getId()}."
				);
				$this->addError(new CallAIError(CallAIError::RECORDING_DISABLED));
				return null;
			}

			// A track error is what usually disables the analysis, and that is exactly the
			// incident an operator resends the event for - so the resend is not refused by the
			// flag it is meant to heal. The flag itself is written further down, only once the
			// request is known to lead to processing of a track pack.
			$needsAiAnalyzeRestore = true;
		}

		$minDuration = CallAISettings::getRecordMinDuration();
		if ($call->getDuration() < $minDuration)
		{
			$log && $logger->error("Ignoring track:{$trackFile->url}, track #{$trackFile->trackId}. Call #{$call->getId()} was too short.");
			$this->addError(new CallAIError(CallAIError::RECORD_TOO_SHORT));

			$call
				->disableAudioRecord()
				->disableAiAnalyze()
				->save();

			CallAIService::getInstance()->removeExpectation($call->getId());

			(new FollowUpAnalytics($call))->addGotEmptyRecord();

			return null;
		}

		// The row is created under a per-track lock shared with the expectation agent
		// {@see TrackService::findOrCreateTrack()}. Only the row is locked: the download and the
		// processing below stay outside it, so two concurrent resends can still cost two AI tasks.
		$intakeResult = TrackService::getInstance()->findOrCreateTrack((int)$call->getId(), $trackFile);
		if (!$intakeResult->isSuccess())
		{
			$this->addErrors($intakeResult->getErrors());

			return null;
		}

		/** @var \Bitrix\Call\Track $track */
		$track = $intakeResult->getData()['track'];

		// 'stored' means the row existed before this callback: a duplicate delivery or an operator resend.
		$storedTrack = ($intakeResult->getData()['stored'] ?? false) ? $track : null;
		if ($storedTrack !== null && !$trackFile->manualRetry)
		{
			$log && $logger->error("Ignoring track:{$trackFile->url}, track #{$trackFile->trackId}. Got duplicate request for call #{$call->getId()}");
			$this->addError(new Error(TrackError::TRACK_DUPLICATE_ERROR));

			return null;
		}

		(new FollowUpAnalytics($call))
			->sendTelemetry(
				source: null,
				status: 'success',
				event: 'track_ready_'.mb_strtolower($track->getType()),
			)
		;

		$trackService = TrackService::getInstance();
		$isDownloaded = $trackService->isDownloaded($track);

		// The fate of the file is decided before any processing, so processing runs once per
		// request: the three states are mutually exclusive and processing a track pack may
		// already have asked the controller to drop the source file. The state that leads to
		// nothing goes first: nothing is restored or processed for an invalid callback.
		if (!$isDownloaded && !$trackService->hasDownloadSource($track))
		{
			$log && $logger->error(
				"Ignoring track:{$trackFile->url}, track #{$trackFile->trackId}."
				. " Nothing to download from for call #{$call->getId()}."
			);
			$this->addError(new TrackError(TrackError::EMPTY_DOWNLOAD_URL, 'Download URL undefined'));

			return null;
		}

		$isTrackPack = $track->getType() === \Bitrix\Call\Track::TYPE_TRACK_PACK;

		// Only the analysis of a track pack lives on the flag and on the expectation agent. A
		// resend of a stored audio record asks for the delivery to chat alone, and the flag would
		// let the expectation agent relaunch the AI chain later and spend a quota nobody asked
		// for; the chat message of that path reads no flag at all.
		if ($needsAiAnalyzeRestore && $isTrackPack)
		{
			CallAIService::getInstance()->restoreAiAnalyze($call);
			$log && $logger->info("Restored FollowUp availability for call #{$call->getId()} on an operator-driven resend.");
		}

		// Only a track the portal already held goes into the restart of the analysis: a track
		// created by this very callback has never been processed, and the regular processing is
		// its whole path - track ready event, mixer cleanup, duration check of the track itself
		// and the chat message about an unavailable analysis.
		$restartAnalysis = $trackFile->manualRetry && $storedTrack !== null;

		if ($isDownloaded)
		{
			if ($isTrackPack)
			{
				$this->enterAiProcessing($call, $track, $restartAnalysis);
			}
			else
			{
				$this->processDownloadedTrack($call, $track);
			}
		}
		else
		{
			// AI starts before the download on purpose: the task takes the mixer url, not the
			// file. Processing of the downloaded file comes from finalizeDownload().
			if ($isTrackPack)
			{
				$this->enterAiProcessing($call, $track, $restartAnalysis);
			}

			$downloadResult = $trackService->downloadTrackFile($track, true);
			if (!$downloadResult->isSuccess())
			{
				$this->addErrors($downloadResult->getErrors());
				return null;
			}
		}

		// Update AI agent expectation time when track is ready. A resend of anything but a track
		// pack stays out of it: the agent relaunches the chain on an empty outcome
		// {@see CallAIService::expectCallAiTask()}, and the operator asked for a delivery to chat.
		if ($call->isAiAnalyzeEnabled() && ($isTrackPack || !$trackFile->manualRetry))
		{
			CallAIService::getInstance()->updateExpectationTime($call->getId());
			$log && $logger->info("Updated AI expectation time for call #{$call->getId()} after receiving track");
		}

		return ['result' => true];
	}

	/**
	 * Entry into the AI analysis for a track pack.
	 *
	 * A resend of a track the portal already held restarts the analysis through the same point
	 * the "continue" button of the chat uses: the regular processing refuses to start a second
	 * task for a track that already has one, and that guard protects every other path from
	 * double quota spending. A track created by the very same callback has no task yet, so it
	 * goes through the regular processing.
	 */
	private function enterAiProcessing(Call $call, \Bitrix\Call\Track $track, bool $restartAnalysis): void
	{
		if ($restartAnalysis)
		{
			$this->reportProcessingResult($call, $this->restartAiAnalysis($call, $track), 'ai_restart_error');

			return;
		}

		$this->reportProcessingResult($call, TrackService::getInstance()->processTrack($track), 'track_processing_error');
	}

	/**
	 * The restart point of the analysis, the only collaborator of the receiver a test cannot let
	 * run: it enters the AI pipeline of the whole call. Overridable for that reason alone.
	 *
	 * The resent pack is named explicitly: left to itself the restart takes the newest pack of
	 * the call, so a resend of an older file would rebuild the analysis of another one - and the
	 * report of the grid would still name the file the operator picked. Naming it also keeps the
	 * chain single: the task lands on the very track the download finalization will process, so
	 * the per-track guard of the regular processing sees it and does not add a second one.
	 */
	protected function restartAiAnalysis(Call $call, \Bitrix\Call\Track $track): Result
	{
		return CallAIService::getInstance()->restartCallAiTask($call->getId(), $track);
	}

	/**
	 * Processing of a file the portal already holds {@see TrackService::processDownloaded()}.
	 *
	 * Only a resend reaches this branch - a regular callback for a stored track is refused as a
	 * duplicate above - and a resend of a record asks for its delivery to chat, nothing else. So
	 * the AI chain is not relaunched here: an empty analysis of the call is the business of the
	 * follow-up event, which has a resend of its own, and relaunching it from a record would spend
	 * a quota the operator never asked for. The message about a record whose analysis is
	 * unavailable is not part of that chain and goes out wherever no analysis is coming to the
	 * chat on its own {@see TrackService::checkAiTasksExecution()}: on this path it is the
	 * delivery, because the finalization of a stored file republishes nothing.
	 */
	private function processDownloadedTrack(Call $call, \Bitrix\Call\Track $track): void
	{
		$this->reportProcessingResult(
			$call,
			TrackService::getInstance()->processDownloaded($track, restoreAiChain: false),
			'track_processing_error'
		);
	}

	/**
	 * Reports a processing failure to both the callback response and telemetry.
	 *
	 * The event tells the kinds of failure apart: processing of a track and the restart of the
	 * analysis of the whole call fail for different reasons.
	 */
	private function reportProcessingResult(Call $call, Result $processResult, string $event): void
	{
		if ($processResult->isSuccess())
		{
			return;
		}

		$this->addErrors($processResult->getErrors());

		(new FollowUpAnalytics($call))
			->sendTelemetry(
				source: null,
				status: 'error',
				event: $event,
				error: $processResult->getError()
			)
		;
	}

	/**
	 * @restMethod call.CallController.restartFollowUp
	 *
	 * A restart of the analysis an operator asked for in the admin panel of the media service.
	 *
	 * The answer carries what became of every type of the set, because that is what the operator
	 * is shown, and keeps the status of the whole call beside it - the contract every other
	 * restart already answers by {@see CallAIService::RESTART_STATUS_STARTED}. A restart that ran
	 * and failed answers with that report and not with an error: the operator needs to see which
	 * type failed and why, and an error collection would replace the whole payload with itself.
	 * A request refused before anything was decided answers with the error alone.
	 *
	 * The `result` field of that report is not a constant: a restart that failed without getting a
	 * single type into the queue is not work done, and says so, or the key of the request would be
	 * marked done for a day over a run that never happened.
	 */
	public function restartFollowUpAction(FollowUpRestartRequest $restartRequest): ?array
	{
		$call = CallFactory::searchActiveByUuid($restartRequest->callUuid);
		if (!isset($call))
		{
			// The one refusal that goes without telemetry: the event of this channel is built from
			// a call, and there is no call here.
			$this->addError(new Error(Error::CALL_NOT_FOUND));

			return null;
		}

		$mode = FollowUpAnalytics::restartMode($restartRequest);

		// The delivery is asked about here, with the rest of the questions, and not inside the lock
		// below: every one of them is a read {@see self::checkRestartDelivery()}, and the reads of
		// a set of files go out over the network - a storage that answers slowly and a second one
		// that never answers hold the lock of the restart, and the handler of the request, for as
		// long as the timeouts of the two questions together, some twenty-six seconds at worst
		// {@see \Bitrix\Call\Track\TrackService::DELIVERY_PROBE_CONNECT_TIMEOUT},
		// {@see \Bitrix\Call\Track\TrackService::DELIVERY_PROBE_READ_TIMEOUT}.
		$refusal = $this->checkRestartRequest($restartRequest)
			?? $this->checkRestartIsAllowed($call)
			?? $this->checkRestartDelivery($call, $restartRequest)
		;
		if ($refusal !== null)
		{
			return $this->refuseRestart($call, $mode, $refusal);
		}

		// One lock for the whole decision - the state of the call, the set to run and its start.
		// It does not stand in for the lock of the decision about a pack
		// {@see TrackService::processAiTrack()}, which the intake inside this request takes on its
		// own: a request holding both takes the call first and the pack second, and the reverse
		// order exists on no path of this module.
		$lockName = "call_followup_restart_{$call->getId()}";
		if (!Application::getConnection()->lock($lockName, self::RESTART_LOCK_TIMEOUT))
		{
			// Another restart of this call is being decided right now. Refused instead of waited
			// out: the wait would end in a second set of paid tasks for the same call.
			return $this->refuseRestart(
				$call,
				$mode,
				new Error(self::ERROR_RESTART_IN_PROGRESS, 'Another restart of this call is in progress')
			);
		}

		try
		{
			(new FollowUpAnalytics($call))
				->addRestartRequested($mode, CallAIService::resolveRequestedSenses($restartRequest))
			;

			$deliveryError = $this->takeRestartDelivery($call, $restartRequest);
			if ($deliveryError !== null)
			{
				// The operator asked for a run on a recording that did not land: running the chain
				// anyway would analyse the file the call had before and report it as the answer to
				// the delivery. The unlock of the finally still happens on the way out.
				return $this->refuseRestart($call, $mode, $deliveryError);
			}

			$restartResult = $this->restartFollowUpAnalysis($call, $restartRequest);

			$senses = $restartResult->getData()['senses'] ?? [];

			$startedSenses = array_column(
				array_filter(
					$senses,
					static fn(array $sense): bool => $sense['outcome'] === CallAIService::OUTCOME_STARTED
				),
				'type'
			);

			$restartStatus = $this->restartStatus($restartResult);

			// A chain that will answer for this call is either one this request queued itself or
			// one it joined: the run that found the analysis already running started nothing and
			// an answer is coming all the same {@see CallAIService::RESTART_STATUS_ALREADY_RUNNING}.
			// Read and decided inside the lock, so the flag of the analysis is raised while the
			// decision about the call is still this request's alone.
			$answerIsComing = $startedSenses !== []
				|| $restartStatus === CallAIService::RESTART_STATUS_ALREADY_RUNNING
			;
			if ($answerIsComing)
			{
				$this->restoreAiAnalyzeForRestart($call);
			}
		}
		finally
		{
			Application::getConnection()->unlock($lockName);
		}

		if ($startedSenses !== [])
		{
			(new FollowUpAnalytics($call))->addRestartStarted($mode, $startedSenses);

			// The server path used to start the analysis without a word in the chat, while both
			// manual paths announce it. A restart asked for by the service has to look to the user
			// of the portal exactly like the one asked for by the button.
			$this->sendAnalysisStartNotice($call);

			// And is watched like it, under the condition every other restart arms the wait by
			// {@see CallAIService::watchRetriedCall()}. The notice above is a promise of an answer,
			// and the only thing that keeps that promise where the callback of the engine is lost
			// is the wait: without it the message "the analysis has started" stands in the chat
			// for good, with no message of failure behind it - the state the intake of a track
			// error calls inadmissible {@see self::trackErrorAction()}.
			CallAIService::getInstance()->watchRetriedCall((int)$call->getId());
		}

		return [
			// A restart that failed and got nothing into the queue says so in the field the
			// idempotency filter reads {@see Filter\UniqueRequestFilter::actionFailed()}: the key
			// of the request is released instead of being marked done for its whole day, and a
			// repeated delivery of the same operator action runs the restart again rather than
			// being answered with a replay of a no-op. The report survives it - the filter looks
			// at this one field and leaves the payload alone, which the error collection would
			// not.
			'result' => $restartResult->isSuccess() || $startedSenses !== [],
			'status' => $restartStatus,
			'senses' => $senses,
		];
	}

	/**
	 * What is wrong with the request itself, or null where nothing is. Checked before the state of
	 * the call is touched at all: a request that is refused must leave no task behind.
	 */
	private function checkRestartRequest(FollowUpRestartRequest $request): ?Error
	{
		if (!preg_match(self::REQUEST_ID_PATTERN, $request->requestId))
		{
			// Unlike the callback of a file, which kept working without a key for the sake of an
			// older sender: a restart without one would run again on every repeated delivery of a
			// single operator action and pay for the call twice.
			return new Error(self::ERROR_REQUEST_ID_REQUIRED, 'The restart request has no valid request id');
		}

		foreach ($request->normalizedSenses() as $sense)
		{
			if (SenseType::tryFrom($sense) === null)
			{
				// The whole request is refused and the value is named: a list whose error the
				// operator cannot see is a list they cannot correct.
				return new Error(self::ERROR_UNKNOWN_SENSE, "Unknown sense type: {$sense}");
			}

			if ($sense === SenseType::TRANSCRIBE->value)
			{
				// The transcription enters the set through an empty list alone: naming it is asking
				// for a full run by the back door, with the marks of a partial one.
				return new Error(
					self::ERROR_SENSE_NOT_ALLOWED,
					'The transcription cannot be named in the list of types'
				);
			}
		}

		return $this->checkRestartMode($request);
	}

	/**
	 * Combinations of the marks that name no mode of the contract.
	 *
	 * Neither is completed silently: the marks are orthogonal, and any default here would glue
	 * back together the dimensions the contract was shaped to keep apart.
	 */
	private function checkRestartMode(FollowUpRestartRequest $request): ?Error
	{
		if (!in_array(
			$request->source,
			[FollowUpRestartRequest::SOURCE_STORED, FollowUpRestartRequest::SOURCE_DELIVERY],
			true
		))
		{
			return new Error(self::ERROR_MODE_NOT_SUPPORTED, "Unknown source: {$request->source}");
		}

		if ($request->purge && $request->source === FollowUpRestartRequest::SOURCE_DELIVERY)
		{
			// A new delivery opens a new generation by itself, and there is nothing for the erasing
			// to add to what the boundary of that generation has already cut off.
			return new Error(self::ERROR_MODE_NOT_SUPPORTED, 'Erasing a new delivery makes no sense');
		}

		if ($request->purge && $request->onlyFailed)
		{
			// Two ways of choosing the set at once: the failed types are chosen by the state of
			// their tasks, the erased ones by the kind of their result.
			return new Error(self::ERROR_MODE_NOT_SUPPORTED, 'Erasing the failed types was never asked for');
		}

		if ($request->source === FollowUpRestartRequest::SOURCE_DELIVERY)
		{
			// A new delivery names one mode of the contract and one only - the full forced run
			// (DTO-01, mode 1) - and the three marks below are the whole of what tells it from the
			// rest. Refused rather than completed, because the intake of the delivery runs BEFORE
			// the analysis and replaces the local copy of the recording for good
			// {@see self::takeRestartDelivery()}: a request that gets past here with `force` down
			// finds the transcription of the old recording contentful and never enters the full
			// run, and one narrowed by `onlyFailed` or by a named list runs a handful of types - or
			// none. Either way the chat is left with a new recording and an analysis of the one it
			// replaced, and the operator is answered `already_complete`.
			if (!$request->force || $request->onlyFailed || $request->normalizedSenses() !== [])
			{
				return new Error(
					self::ERROR_MODE_NOT_SUPPORTED,
					'A new delivery is analysed whole and by force, or not taken at all'
				);
			}

			// The source says the recording travels with the request, and none did. Not completed
			// into a run on the stored copy: that is the other value of this very mark, and the
			// operator asked for the files they sent.
			//
			// Every file of the list answers for itself, and one that names nothing refuses the
			// whole request: the two files of the event are the two halves of one ask - the pack
			// the analysis reads and the record the chat shows - and half a replacement would
			// leave the call with a new analysis of one recording beside the other one in chat.
			if ($request->tracks === [])
			{
				return new Error(self::ERROR_MODE_NOT_SUPPORTED, 'The new delivery carries no file');
			}

			foreach ($request->tracks as $file)
			{
				if ($file->trackId <= 0 || $file->url === '')
				{
					return new Error(self::ERROR_MODE_NOT_SUPPORTED, 'The new delivery carries a file of nothing');
				}

				// And says what it is. The type is not asked about by the row the delivery
				// replaces - that row already knows - but by the row the intake creates where the
				// call has none {@see TrackService::findOrCreateTrack()}: a file of no type would
				// leave a row with an empty TYPE, which no reader of the call can make anything of.
				if (!in_array($file->type, self::DELIVERABLE_TRACK_TYPES, true))
				{
					return new Error(
						self::ERROR_MODE_NOT_SUPPORTED,
						"The new delivery carries a file of an unknown type: {$file->type}"
					);
				}
			}
		}

		return null;
	}

	/**
	 * Whether the call may be analysed again at all.
	 */
	private function checkRestartIsAllowed(Call $call): ?\Bitrix\Main\Error
	{
		if ($call->readRecordDestroyedMark())
		{
			// Read from the table and not from the loaded call: between the loading and this
			// decision lies a request from the service, and a deletion made inside that window is
			// in the table alone {@see Call::readRecordDestroyedMark()}. The intake of a file reads
			// it the same way.
			return new Error(self::ERROR_RECORD_DESTROYED, 'The record of the call was destroyed');
		}

		// The full gate of the portal, and on this path alone: the manual retry, the button in the
		// chat, the expectation agent and the intake of a file are left exactly as they are. The
		// code of the gate travels to the operator as it is.
		$availability = $this->checkAiAvailability();

		return $availability->isSuccess() ? null : $availability->getError();
	}

	/**
	 * Whether the portal may use the AI at all, as the gate of the module answers it.
	 *
	 * The second collaborator of the receiver a test cannot drive, beside the restart itself
	 * {@see self::restartFollowUpAnalysis()}: the answer is assembled from the state of the whole
	 * portal - the switch of the module, the tariff, the settings, the agreement, the subscription
	 * - and its readers memoise what they found in a static for the rest of the process
	 * {@see \Bitrix\Call\Settings::isAIServiceEnabled()}, {@see CallAISettings::isEnableBySettings()},
	 * so a scenario cannot close the gate by writing the option behind it. Overridable for that
	 * reason alone, and read from the guard above and nowhere else - the gate stays on this one
	 * path {@see self::checkRestartIsAllowed()}.
	 */
	protected function checkAiAvailability(): Result
	{
		return CallAISettings::checkAIAvailabilityInCall();
	}

	/**
	 * Asks about every recording that travels with a restart of the `delivery` source, before the
	 * decision about the call is locked and before a single row is written. Null where the request
	 * brings no delivery, or where the whole set answers.
	 *
	 * Everything one file can be refused by is a question and not a write - the type of the row
	 * the delivery names and the address the storage is supposed to serve it from
	 * {@see TrackService::checkDelivery()} - so the whole set is asked about first and only a set
	 * that answers for all of its files is taken {@see self::takeRestartDelivery()}, and a set
	 * that fails the judgement leaves the call exactly as it was.
	 *
	 * Outside the lock of the restart for the same reason it is allowed to be: it writes nothing.
	 * A question of this pass is a HEAD over the network with the timeouts of a question -
	 * ten seconds to connect and three to be answered
	 * {@see \Bitrix\Call\Track\TrackService::DELIVERY_PROBE_CONNECT_TIMEOUT},
	 * {@see \Bitrix\Call\Track\TrackService::DELIVERY_PROBE_READ_TIMEOUT} - not the timeouts
	 * of the download, and a set carries a pack and a record asked one after the other - so a
	 * storage answering slowly and a second one not answering at all still add up to the timeouts
	 * of both, some twenty-six seconds of the handler of the request that the operator waits
	 * through at worst, and that would be spent holding the lock of the restart as well while it
	 * is not needed for asking.
	 *
	 * What the lock does buy is not lost by this: it keeps two restarts of one call from deciding
	 * at once, and the window this pass opens between the question and the answer being used is
	 * closed where it has to be - the type of the row is asked a second time inside the intake,
	 * under the named lock of the row {@see TrackService::takeDeliveredFile()}, and the address is
	 * a question about a storage no lock of this portal holds still anyway.
	 *
	 * What "answers" covers is the taking of the file, not its landing: the download is ordered by
	 * the pass that follows and lands later, by the agent of the track. The one thing asked about
	 * the file itself is that its address answers at all - so a recording swept by the retention
	 * of the service is refused to the operator instead of being taken for a replacement.
	 */
	private function checkRestartDelivery(Call $call, FollowUpRestartRequest $request): ?\Bitrix\Main\Error
	{
		if ($request->source !== FollowUpRestartRequest::SOURCE_DELIVERY)
		{
			return null;
		}

		foreach ($request->tracks as $file)
		{
			$checkResult = TrackService::getInstance()->checkDelivery((int)$call->getId(), $file);
			if (!$checkResult->isSuccess())
			{
				return $checkResult->getError();
			}
		}

		return null;
	}

	/**
	 * Takes the recordings that travel with a restart of the `delivery` source, or null where the
	 * request brings none - every other source works on the copy the portal already holds.
	 *
	 * Each new copy replaces the one the row of its track has, in place: the row, the file of the
	 * chat Disk and the link the chat holds are all kept, and only the content behind them is
	 * written anew {@see TrackService::takeDeliveredFile()} - the pack is simply fetched again and
	 * the record is poured as a new version into the file the chat already shows. Either way the
	 * copy the portal holds stays until the new one lands: the file of the chat Disk keeps the
	 * record, the row itself keeps the pack {@see TrackService::replaceLocalCopy()}, and a
	 * download that gives up leaves the call with the recording it had.
	 *
	 * The type of a file carries three decisions of the intake. A delivery whose type is not the
	 * type of the row it names is refused outright {@see TrackError::DELIVERY_TYPE_MISMATCH}:
	 * taken as it comes, it would empty the copy of a pack to put a record in its place. The file
	 * the replacement pushed out is deleted only for a pack, and only once the new one has landed
	 * {@see TrackService::dropReplacedFile()}: it is addressed by its row alone and would
	 * otherwise be unreachable forever; the file of a record is left to the Disk that holds it.
	 * And a row this intake makes rather than finds is written with the type the delivery names
	 * {@see TrackService::findOrCreateTrack()}.
	 *
	 * Whether the file of the chat Disk is written anew is a decision of the type only as far as
	 * the gate in front of it reaches: the types that live on the chat Disk are the only ones
	 * that can have a file of it to write {@see TrackService::requiresDisk()}. Past that gate it
	 * is no field of the row either - it is the file of the Disk and the file of the row being
	 * different ones, asked at the moment of the writing
	 * {@see TrackService::replacesDiskContent()}.
	 *
	 * The files are taken in the order the request lists them, which is the order the service
	 * collects the files of the event in and the order its existing resend hands them over by
	 * {@see \Bitrix\CallController\Track\EventResender::collectSteps()}. Nothing here depends on
	 * that order, and nothing of the chain is started before every row is replaced.
	 *
	 * The set is taken whole or not at all, and the pass of questions that runs ahead of this one
	 * is how {@see self::checkRestartDelivery()}. A refusal found on the second file of two would
	 * otherwise be found with the first already replaced, and that is the very state the mode
	 * exists not to reach: the pack the analysis reads and the record the chat shows are the two
	 * halves of one ask, and half a replacement leaves the call with a new recording and an
	 * analysis of the one it replaced - or, with the refusal being returned, with neither the old
	 * pack nor a new analysis.
	 *
	 * What the two passes do not cover is a write that fails after the questions were all
	 * answered: the row could not be saved, the named lock of the intake was not granted. Those
	 * are not refusals of the delivery but failures of the portal, they are as rare as any save
	 * failure, and the price of covering them would be an undo of a replacement - a copy of the
	 * old file kept aside and put back - which is a store the module does not have.
	 *
	 * Nothing of the chain is started from here - the transcription is relaunched by the forced
	 * entry into the full run that the request already carries, and a second way in would be a
	 * second answer to the same question.
	 *
	 * The two existing doors of a file of the service - this very callback
	 * {@see self::trackReadyAction()} and the intake of the expectation agent
	 * {@see TrackService::intakeServiceFile()} - are not touched by this: the row is found or made
	 * under the same named lock they take, which is the only thing keeping two paths from inserting
	 * a row each.
	 */
	private function takeRestartDelivery(Call $call, FollowUpRestartRequest $request): ?\Bitrix\Main\Error
	{
		if ($request->source !== FollowUpRestartRequest::SOURCE_DELIVERY)
		{
			return null;
		}

		foreach ($request->tracks as $file)
		{
			// The question is not asked a second time: the answer of the pass above is handed on.
			$deliveryResult = TrackService::getInstance()->takeDeliveredFile($call, $file, true);
			if (!$deliveryResult->isSuccess())
			{
				return $deliveryResult->getError();
			}
		}

		return null;
	}

	/**
	 * Reports a refusal to the operator and to the telemetry of the channel, in that one place.
	 */
	private function refuseRestart(Call $call, string $mode, \Bitrix\Main\Error $error): ?array
	{
		(new FollowUpAnalytics($call))->addRestartRefused($mode, (string)$error->getCode());

		$this->addError($error);

		return null;
	}

	/**
	 * The status of the whole call, derived from a failed restart the same way the public retry
	 * derives it {@see \Bitrix\Call\Controller\TaskAI::restartAction()}: a recording that is gone
	 * is not a launch that failed. Null where the service could not tell a refused launch from a
	 * started one and answered no status at all.
	 */
	private function restartStatus(Result $restartResult): ?string
	{
		if ($restartResult->isSuccess())
		{
			return $restartResult->getData()['status'] ?? null;
		}

		$recordingIsGone = in_array(
			$restartResult->getError()?->getCode(),
			[
				CallAIError::TRACK_PACK_NOT_FOUND_ERROR,
				CallAIService::ERROR_TRACK_PACK_EXPIRED,
				CallAIService::ERROR_RECORD_DESTROYED,
			],
			true,
		);

		return $recordingIsGone
			? CallAIService::RESTART_STATUS_RECORDING_MISSING
			: CallAIService::RESTART_STATUS_LAUNCH_FAILED
		;
	}

	/**
	 * The restart itself: the one collaborator of the receiver a test cannot let run, because it
	 * enters the AI pipeline of the whole call. Overridable for that reason alone, like the
	 * restart of the analysis of a resent file {@see self::restartAiAnalysis()}.
	 */
	protected function restartFollowUpAnalysis(Call $call, FollowUpRestartRequest $request): Result
	{
		return CallAIService::getInstance()->restartCallAiTask((int)$call->getId(), null, $request);
	}

	/**
	 * Brings the AI analysis of the call back where a failure had switched it off.
	 *
	 * The canonical incident this action exists for is a call whose analysis died technically: the
	 * intake of a track error switches the flag off {@see self::trackErrorAction()}, and every
	 * reader of the call takes it from there - the check after a download calls the analysis
	 * unavailable {@see TrackService::checkAiTasksExecution()}, the follow-up reports
	 * `AI_ANALYZED => false`, and the wait closes without an analysis. A restart that leaves the
	 * flag down therefore repairs nothing it was asked to repair.
	 *
	 * Both manual repair paths raise it first and this one raises it last
	 * {@see CallAIService::retryCallAiTask()}, {@see self::trackReadyAction()} - under the lock of
	 * the restart, but at the far end of it, once the run has really put a type into the queue.
	 * Nothing of the restart reads the flag on the way there: the intake of the delivery orders a
	 * download whose landing is asked about by an agent long after this request
	 * {@see TrackService::checkAiTasksExecution()}, and the launch itself never asks. Raised any
	 * earlier, it would outlive every refusal that happens after it - an address of a delivery
	 * that does not answer {@see self::takeRestartDelivery()}, a type of a delivery that names the
	 * wrong row, a launch that got nothing into the queue - and leave the call with the analysis
	 * switched on, no task running, no wait armed and no word in the chat, while the incoming
	 * callback of the service, refused until then by this very flag, would be let through.
	 *
	 * So it is raised on the one condition it means: a chain of this call is running and an answer
	 * is coming. Two runs answer that way and the flag is raised for both. A run that queued a
	 * type of its own, which is the condition the notice in the chat and the wait are raised on
	 * besides. And a run that queued nothing because the analysis was already running
	 * {@see CallAIService::RESTART_STATUS_ALREADY_RUNNING}: there the chain the operator asked for
	 * exists, it will report back, and the flag left down would send that report into the very
	 * refusals this method exists to lift - the callback of the service turned away, the follow-up
	 * answering `AI_ANALYZED => false`, the wait closing without an analysis. The notice and the
	 * wait are not raised with it, and for the same reason: that chain announced itself and armed
	 * its wait when it started, and a second of either would be a second promise of one answer.
	 *
	 * A run that found the analysis already finished raises nothing: there is no answer on its way
	 * to be turned away, and the results the call holds are the ones it already has.
	 *
	 * Only this one field is written {@see CallAIService::restoreAiAnalyze()} - a full save of the
	 * call would write back every other field of an instance loaded before the request.
	 */
	private function restoreAiAnalyzeForRestart(Call $call): void
	{
		if (!$call->isAiAnalyzeEnabled())
		{
			CallAIService::getInstance()->restoreAiAnalyze($call);
		}
	}

	/**
	 * Says in the chat that the analysis has started again.
	 *
	 * Under the same claim as both manual paths - keyed by the attempt
	 * {@see \Bitrix\Call\Controller\TaskAI::restartAction()} - so a repeated delivery of one
	 * request says it once. The claim of the automatic path after the end of the recording is
	 * keyed by the call alone and does not see this one, which is left as it is on purpose: the
	 * two forms belong to different questions.
	 */
	private function sendAnalysisStartNotice(Call $call): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		$callId = (int)$call->getId();
		$chatId = (int)$call->getChatId();
		$chat = Chat::getInstance($chatId);
		if ($chatId <= 0 || (int)$chat->getId() !== $chatId)
		{
			// A call with no chat of its own has nowhere to say it: the analysis of such a call is
			// read through the REST of the follow-up and not through a message.
			return;
		}

		$notifyService = NotifyService::getInstance();
		if (
			$notifyService->isMessageShown($callId, NotifyService::MESSAGE_TYPE_AI_START)
			|| $notifyService->findMessage($chatId, $callId, NotifyService::MESSAGE_TYPE_AI_START, 1) !== null
		)
		{
			return;
		}

		$message = ChatMessage::generateTaskStartMessage($callId, $chat);
		if (
			!$message
			|| !$notifyService->claim(
				$callId,
				NotifyService::MESSAGE_TYPE_AI_START,
				CallAIService::getInstance()->getCurrentAnalysisAttemptId($callId)
			)
		)
		{
			return;
		}

		$sendingConfig = (new SendingConfig())
			->enableSkipCounterIncrements()
			->enableSkipUrlIndex()
		;
		$notifyService->sendMessageDeferred(
			$chat,
			$message,
			$sendingConfig,
			(new Context())->setUser($call->getInitiatorId())
		);
		$notifyService->setMessageShown($callId, NotifyService::MESSAGE_TYPE_AI_START);
	}

	/**
	 * @restMethod call.CallController.trackError
	 */
	public function trackErrorAction(TrackErrorRequest $trackError): ?array
	{
		Loader::includeModule('im');

		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
		}

		$call = CallFactory::searchActiveByUuid($trackError->callUuid);
		if (!isset($call))
		{
			$log && $logger->error("Call uuid:{$trackError->callUuid} not found");
			$this->addError(new Error(Error::CALL_NOT_FOUND));
			return null;
		}

		// Normalize once here: below this point an empty code is impossible, so every consumer
		// (log, chat message, pull event, analytics) reports the failure with the same value.
		$errorCode = TrackError::normalize($trackError->errorCode);

		$log && $logger->error("Got track error: {$errorCode}");

		$wasAiAnalyzeEnabled = $call->isAiAnalyzeEnabled();

		$call
			->disableAudioRecord()
			->disableAiAnalyze()
			->save();

		// The wait stays, and this is the failure it was rebuilt for. The analysis is off from here
		// on, so the check of the call takes the branch that asks the service for the audio record
		// and delivers it to the chat {@see CallAIService::closeExpectationWithoutAnalysis()}: the
		// recorder may well have produced the record before it failed on the pack, and it is exactly
		// the calls whose analysis died technically that the portal rescues the record of. Ending
		// the wait here - as this action used to - left that branch nothing to run on: the flag is
		// down by now, so the end of the call arms no wait of its own either
		// {@see \Bitrix\Call\Integration\AI\EventService::onCallFinished()}.
		//
		// A wait already standing is left alone: it carries its own number of spent checks
		// {@see CallAIService::setupExpectation()}. Nothing is armed for a record a user destroyed -
		// the deletion ends the wait on purpose, and the check would refuse anyway
		// {@see CallAIService::expectCallAiTask()}.
		if (!$call->isRecordDestroyed())
		{
			$service = CallAIService::getInstance();
			if (!$service->hasExpectationAgent($call->getId()))
			{
				$service->setupExpectation($call->getId());
				$log && $logger->info(
					"Armed AI expectation agent for call #{$call->getId()} to rescue the record"
					. " after track error: {$errorCode}"
				);
			}
		}

		// Reply into the chat where the "analysis started" message hangs so it does not stay unresolved.
		if ($wasAiAnalyzeEnabled)
		{
			NotifyService::getInstance()->sendTaskFailedMessage(CallAIError::fromTrackErrorCode($errorCode), $call);
		}

		$call->getSignaling()
			->sendSwitchTrackRecordStatus(0, false, $errorCode);

		(new FollowUpAnalytics($call))->addErrorRecording($errorCode);

		return ['result' => true];
	}

	/**
	 * @restMethod call.CallController.pingTracks
	 *
	 * Fire-and-forget wake-up from callcontroller for tracks of one account. The
	 * wake-up is the HTTP hit ITSELF: it drives CAgent::CheckAgents in the
	 * end-of-request background job of this request (Application::terminate ->
	 * runBackgroundJobs). The already-scheduled DownloadAgent /
	 * CloudRecordExpectationAgent are IS_PERIOD='N', so a plain web hit on a
	 * portal with `main.agents_use_crontab='Y'` would select only IS_PERIOD='Y'
	 * agents and skip them. To make the wake-up work there too, this action
	 * defines BX_CRONTAB for the request: CAgent::OnCron() then reports cron mode
	 * and the shutdown check picks the non-periodic agents. On a default portal
	 * (`agents_use_crontab='N'`, no BX_CRONTAB_SUPPORT) the check already runs
	 * every agent, so the define is a no-op — the point of the feature for
	 * low-traffic portals that otherwise never get a hit.
	 *
	 * The action does NOT return state synchronously (KK does not read the reply).
	 * Instead it PUSHES back, via the normal telemetry channel, the state of tracks
	 * that won't report on their own:
	 *   downloaded                                  -> probe_downloaded (recovers a
	 *                                                  lost agent-success telemetry)
	 *   no_url / no_agent                           -> probe_failed (can't download)
	 *   agent_pending                               -> nothing (the live agent reports)
	 *   track_not_found / call_not_found            -> nothing pushable here (no track
	 *                                                  / no Call); KK's age cutoff or
	 *                                                  orphan handling finalizes it.
	 *
	 * QA: with the `debug_download_only_on_ping` call option enabled, DownloadAgent
	 * runs only within this hit (see DownloadAgent::markWakeUpHit()).
	 */
	public function pingTracksAction(PingTracksRequest $request): ?array
	{
		// Must be the very first statement (before any early return): flags this
		// request as a wake-up ping hit for the debug download-only-on-ping QA mode.
		DownloadAgent::markWakeUpHit();

		// Run the scheduled IS_PERIOD='N' agents (DownloadAgent /
		// CloudRecordExpectationAgent) in this request's shutdown CAgent::CheckAgents
		// even when the portal moved non-periodic agents to cron. Without cron mode
		// the shutdown check would select only IS_PERIOD='Y' agents and skip ours;
		// with agents_use_crontab='N' (default) OnCron() ignores BX_CRONTAB, so this
		// is a no-op there. See the action docblock.
		//
		// NB: on an agents_use_crontab='Y' portal this makes the shutdown check run
		// the whole due IS_PERIOD='N' agent batch (all modules), not just ours —
		// same batch a real cron tick would run, guarded against double-run by the
		// per-agent lock + NEXT_EXEC. Acceptable: the point of the feature is to
		// drive our agents on portals that otherwise never get a cron-mode hit.
		if (!defined('BX_CRONTAB'))
		{
			define('BX_CRONTAB', true);
		}

		$ids = array_values(array_unique(array_map('intval', $request->externalTrackIds)));
		if (!$ids)
		{
			return ['result' => true];
		}

		// Select only the fields used below and work with rows (no entity
		// hydration). DOWNLOADED is a BooleanField('N','Y') — fetch() returns the
		// raw 'Y'/'N' string, so compare against 'Y', not bool.
		$rows = CallTrackTable::query()
			->setSelect(['ID', 'CALL_ID', 'EXTERNAL_TRACK_ID', 'DOWNLOADED', 'DOWNLOAD_URL', 'TYPE'])
			->whereIn('EXTERNAL_TRACK_ID', $ids)
			->fetchAll()
		;

		$byExternalId = [];
		$pendingTrackIds = [];
		$pendingCallIds = [];
		$cloudPendingCallIds = [];
		foreach ($rows as $row)
		{
			$byExternalId[(int)$row['EXTERNAL_TRACK_ID']] = $row;
			if ($row['DOWNLOADED'] !== 'Y')
			{
				$pendingTrackIds[] = (int)$row['ID'];
				$pendingCallIds[(int)$row['CALL_ID']] = true;
				if ($this->isCloudTrackType((string)$row['TYPE']))
				{
					$cloudPendingCallIds[(int)$row['CALL_ID']] = true;
				}
			}
		}

		$agentsByTrackId = $pendingTrackIds
			? DownloadAgent::hasScheduledAgentsForTracks($pendingTrackIds)
			: [];

		// A cloud track whose call still has a live CloudRecordExpectationAgent is
		// in a normal waiting state — must not be finalized as failed on no_url /
		// no_agent. Batched by CALL_ID (one SQL hit for the whole batch).
		$expectationAgentsByCallId = $cloudPendingCallIds
			? CloudRecordExpectationAgent::hasScheduledAgents(array_keys($cloudPendingCallIds))
			: [];

		// One PK-only lookup for call existence instead of building a Call
		// object per track for the classification step.
		$existingCalls = [];
		if ($pendingCallIds)
		{
			$callRows = CallTable::getList([
				'select' => ['ID'],
				'filter' => ['@ID' => array_keys($pendingCallIds)],
			])->fetchAll();
			$existingCalls = array_fill_keys(array_map('intval', array_column($callRows, 'ID')), true);
		}

		foreach ($byExternalId as $row)
		{
			$state = $this->handleTrackProbe($row, $agentsByTrackId, $existingCalls, $expectationAgentsByCallId);
			$this->pushTrackState($row, $state);
		}

		return ['result' => true];
	}

	/**
	 * Whether the track type is a cloud recording surface (video record / preview)
	 * whose download is driven by CloudRecordExpectationAgent.
	 */
	private function isCloudTrackType(string $type): bool
	{
		return in_array(
			$type,
			[\Bitrix\Call\Track::TYPE_VIDEO_RECORD, \Bitrix\Call\Track::TYPE_VIDEO_PREVIEW],
			true
		);
	}

	/**
	 * Read-only classification of a track's current state (cheapest-first; call
	 * existence — the only cross-table lookup — verified last).
	 *
	 * @param array<string,mixed> $track Track row (ID, CALL_ID, DOWNLOADED, DOWNLOAD_URL, TYPE).
	 * @param array<int,bool> $agentsByTrackId Result of DownloadAgent::hasScheduledAgentsForTracks().
	 * @param array<int,bool> $existingCalls Set [callId => true] of calls that still exist.
	 * @param array<int,bool> $expectationAgentsByCallId Result of CloudRecordExpectationAgent::hasScheduledAgents().
	 */
	private function handleTrackProbe(
		array $track,
		array $agentsByTrackId,
		array $existingCalls,
		array $expectationAgentsByCallId
	): string
	{
		// DOWNLOADED is the raw BooleanField value ('Y'/'N') from fetchAll().
		if ($track['DOWNLOADED'] === 'Y')
		{
			return 'downloaded';
		}

		if (!empty($agentsByTrackId[(int)$track['ID']]))
		{
			return 'agent_pending';
		}

		// A cloud track (video record / preview) whose call still has a live
		// CloudRecordExpectationAgent is in a normal intermediate waiting state:
		// that agent resolves it (download success, default-preview fallback, or a
		// final error it pushes itself). Treat as pending — symmetric to
		// agent_pending — so no_url / no_agent below do not prematurely finalize
		// the track as FLAG_FAILED on the KK side and cut off future wake-ups.
		if (
			$this->isCloudTrackType((string)$track['TYPE'])
			&& !empty($expectationAgentsByCallId[(int)$track['CALL_ID']])
		)
		{
			return 'agent_pending';
		}

		if (empty($track['DOWNLOAD_URL']))
		{
			return 'no_url';
		}

		if (!isset($existingCalls[(int)$track['CALL_ID']]))
		{
			return 'call_not_found';
		}

		// Not downloaded and no agent is scheduled — this hit has nothing to wake.
		return 'no_agent';
	}

	/**
	 * Push the resolved state back to callcontroller via the telemetry channel
	 * (async, fire-and-forget). Only states that won't be reported by an agent on
	 * their own are pushed: a live agent (agent_pending) reports itself, and a
	 * missing Call (call_not_found) has no analytics context to send through.
	 *
	 * @param array<string,mixed> $track
	 */
	private function pushTrackState(array $track, string $state): void
	{
		if ($state === 'agent_pending')
		{
			return;
		}

		$call = Registry::getCallWithId((int)$track['CALL_ID']);
		if (!$call)
		{
			// call_not_found: no Call to build analytics from — KK's orphan/age
			// handling finalizes it.
			return;
		}

		$trackObj = (new \Bitrix\Call\Track)
			->setId((int)$track['ID'])
			->setExternalTrackId((int)$track['EXTERNAL_TRACK_ID'])
			->setType((string)$track['TYPE'])
		;

		$downloaded = ($state === 'downloaded');
		$event = $downloaded ? 'probe_downloaded' : 'probe_failed';
		$status = $downloaded ? 'success' : 'error';
		$errorCode = $downloaded ? null : $state;

		$isCloud = $this->isCloudTrackType((string)$track['TYPE']);

		$analytics = $isCloud ? new CloudRecordAnalytics($call) : new TrackAnalytics($call);
		$analytics->sendTelemetry(
			source: $trackObj,
			status: $status,
			errorCode: $errorCode,
			event: $event,
		);
	}

	/**
	 * @restMethod call.CallController.checkPublicUrl
	 */
	public function checkPublicUrlAction(int $ping): ?array
	{
		if (CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
			$logger->info("Got checkPublicUrl action");
		}

		return ['pong' => ++$ping];
	}
}
