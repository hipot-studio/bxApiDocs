<?php

namespace Bitrix\Call\Controller;

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Service\MicroService\BaseReceiver;
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
use Bitrix\Call\Track\Downloader\DownloadAgent;
use Bitrix\Call\Track\CloudRecordExpectationAgent;
use Bitrix\Call\Call\Registry;
use Bitrix\Call\Model\CallTable;
use Bitrix\Call\Model\CallTrackTable;
use Bitrix\Call\Integration\AI\CallAIError;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Integration\AI\CallAIService;
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

		if (!$call->isAiAnalyzeEnabled())
		{
			$log && $logger->error("Ignoring track:{$trackFile->url}, track #{$trackFile->trackId}. FollowUp was disabled for call #{$call->getId()}.");
			$this->addError(new CallAIError(CallAIError::RECORDING_DISABLED));
			return null;
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

		$trackList = CallTrackTable::query()
			->setSelect(['ID'])
			->where('CALL_ID', $call->getId())
			->where('EXTERNAL_TRACK_ID', $trackFile->trackId)
			->setLimit(1)
			->exec()
		;
		if ($trackList->getSelectedRowsCount() > 0)
		{
			$log && $logger->error("Ignoring track:{$trackFile->url}, track #{$trackFile->trackId}. Got duplicate request for call #{$call->getId()}");
			$this->addError(new Error(TrackError::TRACK_DUPLICATE_ERROR));
			return null;
		}

		$track = (new \Bitrix\Call\Track)
			->setCallId($call->getId())
			->setExternalTrackId($trackFile->trackId)
			->setDownloadUrl($trackFile->url)
		;

		if (in_array($trackFile->type, [\Bitrix\Call\Track::TYPE_TRACK_PACK, \Bitrix\Call\Track::TYPE_RECORD], true))
		{
			$track->setType($trackFile->type);
		}

		$mime = \Bitrix\Main\Web\MimeType::normalize($trackFile->mime);
		if ($mime)
		{
			$track->setFileMimeType($mime);
		}

		if ($trackFile->name)
		{
			$track->setFileName($trackFile->name);
		}
		$track->generateFilename();

		if ($trackFile->duration)
		{
			$track->setDuration($trackFile->duration);
		}

		if ($trackFile->size)
		{
			$track->setFileSize($trackFile->size);
		}

		$saveResult = $track->save();
		if (!$saveResult->isSuccess())
		{
			$log && $logger->error("Save track error: ".implode('; ', $saveResult->getErrorMessages()));
			$this->addErrors($saveResult->getErrors());
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

		if ($track->getType() === \Bitrix\Call\Track::TYPE_TRACK_PACK)
		{
			$processResult = $trackService->processTrack($track);
			if (!$processResult->isSuccess())
			{
				$this->addErrors($processResult->getErrors());

				$error = $processResult->getError();
				(new FollowUpAnalytics($call))
					->sendTelemetry(
						source: null,
						status: 'error',
						event: 'track_processing_error',
						error: $error
					);
			}
		}

		if ($trackService->doNeedDownloadTrack($track))
		{
			$downloadResult = $trackService->downloadTrackFile($track, true);
			if (!$downloadResult->isSuccess())
			{
				$this->addErrors($downloadResult->getErrors());
				return null;
			}

			// Check if download is still in progress (chunked download)
			$downloadData = $downloadResult->getData();
			if (isset($downloadData['status']) && $downloadData['status'] === 'in_progress')
			{
				// Download will continue via agent
				return ['result' => true, 'status' => 'in_progress'];
			}
		}
		else
		{
			// Track already downloaded, process it directly
			$processResult = $trackService->processTrack($track);
			if (!$processResult->isSuccess())
			{
				$this->addErrors($processResult->getErrors());

				$error = $processResult->getError();
				(new FollowUpAnalytics($call))
					->sendTelemetry(
						source: null,
						status: 'error',
						event: 'track_processing_error',
						error: $error
					)
				;
			}
		}

		// Update AI agent expectation time when track is ready
		if ($call->isAiAnalyzeEnabled())
		{
			CallAIService::getInstance()->updateExpectationTime($call->getId());
			$log && $logger->info("Updated AI expectation time for call #{$call->getId()} after receiving track");
		}

		return ['result' => true];
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

		$log && $logger->error("Got track error: ".($trackError->errorCode ?? '-'));

		// Remove AI expectation agent for failed track recording
		CallAIService::getInstance()->removeExpectation($call->getId());
		$log && $logger->info("Removed AI expectation agent for call #{$call->getId()} due to track error: ".($trackError->errorCode ?? '-'));

		$wasAiAnalyzeEnabled = $call->isAiAnalyzeEnabled();

		$call
			->disableAudioRecord()
			->disableAiAnalyze()
			->save();

		// Reply into the chat where the "analysis started" message hangs so it does not stay unresolved.
		if ($wasAiAnalyzeEnabled)
		{
			NotifyService::getInstance()->sendTaskFailedMessage(CallAIError::fromTrackErrorCode($trackError->errorCode), $call);
		}

		$call->getSignaling()
			->sendSwitchTrackRecordStatus(0, false, $trackError->errorCode);

		(new FollowUpAnalytics($call))->addErrorRecording($trackError->errorCode);

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
