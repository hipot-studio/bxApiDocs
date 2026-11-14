<?php

namespace Bitrix\Call\Integration\AI;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Loader;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Type\Callback;
use Bitrix\Main\Type\DateTime;
use Bitrix\AI\Engine;
use Bitrix\AI\Context;
use Bitrix\AI\Payload\IPayload;
use Bitrix\Call\Call;
use Bitrix\Call\Call\Registry;
use Bitrix\Call\ControllerClient;
use Bitrix\Call\DTO\TrackFileRequest;
use Bitrix\Call\DTO\FollowUpRestartRequest;
use Bitrix\Call\FollowUpAgent;
use Bitrix\Call\Track;
use Bitrix\Call\Track\TrackService;
use Bitrix\Call\Track\Downloader\DownloadAgent;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\NotifyService;
use Bitrix\Call\Integration\AI\Task\AITask;
use Bitrix\Call\Model\CallTable;
use Bitrix\Call\Model\CallAITaskTable;
use Bitrix\Call\Model\CallOutcomeTable;
use Bitrix\Call\Model\CallOutcomePropertyTable;
use Bitrix\Call\Model\CallTrackTable;
use Bitrix\Call\Integration\AI\Outcome\OutcomeCollection;
use Bitrix\Call\Integration\AI\Outcome\AISenseContent;
use Bitrix\Call\Analytics\FollowUpAnalytics;

final class CallAIService
{
	private const FINISH_TASK_DEPTH_DAYS = 60;
	private const EXPECTATION_FIRST_REPEAT = 1;

	/**
	 * How many rows one statement of the erasing takes {@see self::deleteRowsByIds()}.
	 *
	 * The erasing runs under the lock of the restart while the operator waits, so what it must not
	 * do is grow a statement with the history of the call. A page of this size holds the list of
	 * ids of a delete well inside any packet limit, and on the history of a real call - a few rows
	 * per type per press of the button - the whole of a type goes in one statement anyway.
	 */
	private const PURGE_DELETE_CHUNK = 500;

	/**
	 * The one form of an expectation agent name earlier versions of the module wrote, up to the
	 * bracket the call id follows {@see self::migrateLegacyExpectationAgents()}.
	 *
	 * A literal and not `self::class`: the rows in the wild carry the class path of the version
	 * that wrote them, so this states a fact about the past and must not follow a later rename of
	 * this class.
	 */
	private const LEGACY_EXPECTATION_AGENT_PREFIX = 'Bitrix\\Call\\Integration\\AI\\CallAIService::expectCallAiTask(';

	// The REST-facing five-status enum of a restart. `started`, `already_running` and
	// `already_complete` are set by restartCallAiTask() itself via Result::setData(['status' => ...]);
	// `recording_missing` and `launch_failed` are derived by the caller from a failed restart's error.
	public const RESTART_STATUS_STARTED = 'started';
	public const RESTART_STATUS_ALREADY_RUNNING = 'already_running';
	public const RESTART_STATUS_ALREADY_COMPLETE = 'already_complete';
	public const RESTART_STATUS_RECORDING_MISSING = 'recording_missing';
	public const RESTART_STATUS_LAUNCH_FAILED = 'launch_failed';

	// What became of every single type of the set a restart was asked for. The status above speaks
	// for the whole call, these speak per type, and the admin panel of the service reports them to
	// the operator line by line - so the values are a contract and not an internal detail.
	public const OUTCOME_STARTED = 'started';
	public const OUTCOME_CHAINED = 'chained';
	public const OUTCOME_SKIPPED_CONTENTFUL = 'skipped_contentful';
	public const OUTCOME_SKIPPED_PENDING = 'skipped_pending';
	public const OUTCOME_BLOCKED_PRECONDITION = 'blocked_precondition';
	public const OUTCOME_NOT_FAILED = 'not_failed';
	public const OUTCOME_LAUNCH_FAILED = 'launch_failed';

	// The refusal of a retry of a call whose recording a user destroyed. It is a missing recording
	// and not a failed launch: nothing was launched and nothing ever will be, because the file the
	// analysis needs is gone for good {@see self::retryCallAiTask()}.
	public const ERROR_RECORD_DESTROYED = 'record_destroyed';

	// The half of "there is no recording" that says the recording was there and the retention of
	// the portal has taken it since, told apart from CallAIError::TRACK_PACK_NOT_FOUND_ERROR, which
	// keeps meaning "no recording was ever delivered" {@see self::missingRecordCode()}. Answered to
	// the operator of the admin panel of the service and to nobody else: the two are one refusal
	// everywhere the analysis is retried by a user, and the paths of those retries read the old
	// code to decide what to do next.
	public const ERROR_TRACK_PACK_EXPIRED = 'TRACK_PACK_EXPIRED_ERROR';

	// launchTask() Result flag: the launch left no queued task that can ever report back - an
	// onCallAiTask handler refused it before the queue, or the queue hash never reached the row.
	// The Result stays successful, so only a caller that reports a start has to read it.
	private const LAUNCH_QUEUED = 'queued';

	// restartCallAiTask() Result flag: the batch got a task into the queue before it failed, so the
	// analysis is on its way and the failure cannot be read as a restart that started nothing.
	public const RESTART_LAUNCHED = 'launched';

	private static ?CallAIService $service = null;

	/** @var (\Closure(): ControllerClient)|null Seam of the controller client: replaced in tests. */
	private static ?\Closure $controllerClientFactory = null;

	private function __construct()
	{}

	public static function getInstance(): self
	{
		if (!self::$service)
		{
			self::$service = new self();
		}
		return self::$service;
	}

	/**
	 * The one point every start of the chain goes through: the intake of a file of the call
	 * {@see TrackService::processAiTrack()} and every restart of the chain
	 * {@see self::restartCallAiTask()} - the check of the wait, the retry a user asked for and the
	 * resend from the service. The gate of the quota therefore lives here and nowhere else: a copy
	 * in a caller is a gate the next caller walks past.
	 */
	public function processTrack(Track $track): Result
	{
		$result = new Result;

		$logger = Logger::getInstance();

		// The same rule the intake of a file applies to a pack {@see TrackService::processAiTrack()},
		// applied again where the paid task is actually created. A caller that already holds the row
		// of a pack reaches this method directly - the manual retry hands it the row of the file it
		// has just taken {@see self::retryCallAiTask()} - and the intake may have refused that very
		// pack as too short, sending the user the failure and ending the wait. A refusal upstream
		// must not become a paid analysis here.
		$minDuration = CallAISettings::getRecordMinDuration();
		if ($track->getDuration() > 0 && $track->getDuration() < $minDuration)
		{
			$logger->error(
				'Unable process track. The recording is shorter than the minimum. TrackId:' . $track->getId()
			);

			return $result->addError(new CallAIError(CallAIError::RECORD_TOO_SHORT));
		}

		if (!CallAISettings::isCallAIEnable())
		{
			$logger->error('Unable process track. Module AI is unavailable. TrackId:'.$track->getId());

			$error = new CallAIError(CallAIError::AI_UNAVAILABLE_ERROR);
			$error->allowRecover();

			return $result->addError($error);
		}

		// Availability, not a bare limit check: the limit answers "no" wherever there is no BaaS to
		// ask, which would keep the chain from ever starting on an installation without it.
		//
		// Ahead of the task, not next to the launch: a refusal must leave nothing behind, or the
		// saved task would keep counting as running and the wait would end on a recoverable
		// "transcription failed" with a button that cannot help. Not recoverable on purpose either:
		// a retry gets the same refusal until the quota is topped up.
		if (!CallAISettings::baasAvailable())
		{
			$logger->error('Unable process track. AI request limit is reached. TrackId:'.$track->getId());

			// The wait ends where the refusal happens, for the same reason the gate stands ahead of
			// the task: nothing was created, so a check left standing would find a pack with no task
			// on it and report a recoverable "transcription failed" {@see self::checkCallAiTask()} -
			// a second, vaguer message about the refusal the user has already read, offering a button
			// that leads straight back here. Nothing puts the wait back either, on this path or on
			// any other {@see self::hasAnythingToWaitFor()}.
			$this->removeExpectation($track->getCallId());

			return $result->addError(new CallAIError(CallAIError::AI_NOT_ENOUGH_BAAS_ERROR));
		}

		$resultTask = $this->buildTaskByTrack($track);
		if (!$resultTask->isSuccess())
		{
			$logger->error('Unable process track. Error: '. implode('; ', $resultTask->getErrorMessages()));
			return $result->addErrors($resultTask->getErrors());
		}

		/** @var AITask $task */
		$task = $resultTask->getData()['task'] ?? null;
		if ($task)
		{
			$launchResult = $this->launchTask($task);
			if (!$launchResult->isSuccess())
			{
				// The row of the transcription goes with the failure {@see self::writeOffUnlaunchedTask()}:
				// a launch that failed before the engine - on a payload it could not build, on an
				// engine that is not there, on a restriction that refused it - reaches no callback
				// of the engine, and it is that callback which ends the task on the ordinary path
				// {@see self::launchTask()}. Left in the status the create wrote, a task nobody is
				// going to answer for reads as the call still running to every later question
				// {@see AITask::hasPendingTaskForCall()} and holds the final message of the call
				// back for good - this path arms no wait of its own, and only the agent of the old
				// tasks would free it, sixty days on {@see self::finishTasks()}. The guard of the
				// helper skips the task a launch that did reach the engine has already ended, so
				// the answer of the engine stays on the row.
				//
				// Only a failure, and never a veto: a handler that refuses the launch answers a
				// successful result and never lands here, because a veto has always been silence
				// for this path and leaves the row of its type where it is {@see self::launchTask()}.
				// What the user is told does not change either - the refusal below is what the
				// intake of the file turns into the message of the chat
				// {@see TrackService::processAiTrack()}.
				$this->writeOffUnlaunchedTask($task, $launchResult);

				return $result->addErrors($launchResult->getErrors());
			}

			$result->setData($launchResult->getData());
		}

		return $result;
	}

	/**
	 * @param Track $track
	 * @return Result<AITask|Task\TranscribeCallRecord>
	 */
	public function buildTaskByTrack(Track $track): Result
	{
		$result = new Result;

		if ($track->getType() != Track::TYPE_TRACK_PACK)
		{
			return $result->addError(new CallAIError(CallAIError::UNSUPPORTED_TRACK_ERROR, 'Unsupported track format'));
		}

		$task = new Task\TranscribeCallRecord();
		$task
			->setPayload($track)
			->save()
		;

		return $result->setData(['task' => $task]);
	}

	/**
	 * @param Outcome $outcome
	 * @param AITask|null $sourceTask the task the processed result came from
	 * @return Result<AITask[]>
	 */
	public function buildTasksByOutcome(Outcome $outcome, ?AITask $sourceTask = null): Result
	{
		$result = new Result;

		$taskToLaunch = $this->getTaskToLaunchByOutcome($outcome);

		$existingTasks = AITask::getTasksForCall($outcome->getCallId());

		$tasks = [];
		foreach ($taskToLaunch as $taskSenseType)
		{
			$task = $existingTasks[$taskSenseType->value] ?? null;
			if ($task && $task->isFinished() && self::taskOutlivesSource($task, $sourceTask))
			{
				continue; // skip finished task of a newer attempt than this result belongs to
			}

			$taskClass = $taskSenseType->getTaskClass();

			$task = new $taskClass();
			$task->setPayload($outcome);
			if ($outcome->getLanguageId())
			{
				$task->setLanguageId($outcome->getLanguageId());
			}
			$dbResult = $task->save();
			if ($dbResult->isSuccess())
			{
				$tasks[] = $task;
			}
			else
			{
				$result->addErrors($dbResult->getErrors());
			}
		}

		return $result->setData(['tasks' => $tasks]);
	}

	/**
	 * The boundary between the generations of the analysis: a finished task of a type holds the
	 * type back only while it is NEWER than the task the processed result came from. A finished
	 * task older than the source belongs to an attempt that is over, and a run rebuilding the
	 * analysis has to reach past it - otherwise a call once analysed in full would stop at its
	 * transcription and never open the chain again.
	 *
	 * The comparison lives here and nowhere else: the graph of the chain is read in one place and
	 * so is its boundary.
	 */
	private static function taskOutlivesSource(AITask $task, ?AITask $sourceTask): bool
	{
		if ($sourceTask === null)
		{
			// The source of the result was not named, so nothing can be said about generations and
			// the finished task stays the answer of its type - what this builder did before.
			return true;
		}

		return (int)$task->getId() > (int)$sourceTask->getId();
	}

	/**
	 * @return SenseType[]
	 */
	private function getTaskToLaunchByOutcome(Outcome $outcome): array
	{
		return self::getTaskToLaunchByType((string)$outcome->getType());
	}

	/**
	 * The graph of the chain: the types a result of the given type opens.
	 *
	 * The transcription is opened by nothing - it is the root of the chain, and the only way to
	 * start it is the full run {@see self::restartCallAiTask()}.
	 *
	 * @return SenseType[]
	 */
	private static function getTaskToLaunchByType(string $type): array
	{
		$taskToLaunch = [];
		if ($type == SenseType::TRANSCRIBE->value)
		{
			$taskToLaunch = [
				SenseType::OVERVIEW,
				SenseType::SUMMARY,
			];
		}
		if ($type == SenseType::OVERVIEW->value)
		{
			$taskToLaunch = [
				SenseType::INSIGHTS,
				SenseType::EVALUATION,
			];
		}

		return $taskToLaunch;
	}

	/**
	 * The type whose result opens the given one, read from the graph itself so the two cannot
	 * drift apart. The root of the chain has none.
	 */
	private static function predecessorOf(SenseType $senseType): ?SenseType
	{
		foreach (SenseType::cases() as $sourceType)
		{
			if (in_array($senseType, self::getTaskToLaunchByType($sourceType->value), true))
			{
				return $sourceType;
			}
		}

		return null;
	}

	/**
	 * @param AITask $task
	 * @return Result
	 */
	public function finishTask(AITask $task): Result
	{
		return $task->drop();
	}

	/**
	 * @param AITask $task
	 * @return Result
	 */
	public function launchTask(AITask $task): Result
	{
		$result = new Result;

		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
		}

		$payloadResult = $task->getAIPayload();
		if (!$payloadResult->isSuccess())
		{
			$log && $logger->error('Empty payload for AI');

			$error = new CallAIError(CallAIError::EMPTY_PAYLOAD_ERROR);
			$call = Registry::getCallWithId($task->getCallId());

			(new FollowUpAnalytics($call))
				->sendTelemetry(
					source: $task,
					status: 'error',
					errorCode: $error->getCode(),
					event: 'task_launch_error',
					error: $error
				)
			;

			$this->fireCallAiFailedEvent($task, $error);

			return $result->addError($error);
		}

		/**
		 * @var \Bitrix\AI\Payload\IPayload $payload
		 */
		$payload = $payloadResult->getData()['payload'];
		$context = $task->getAIEngineContext();
		$engine = $task->getAIEngine($context);
		$call = Registry::getCallWithId($task->getCallId());

		if ($payload instanceof \Bitrix\AI\Payload\IPayload)
		{
			$payload->setCost($task->getCost());
			if (
				CallAISettings::isPaidTariff()
				&& CallAISettings::checkMarketSubscription()
				&& CallAISettings::isMarketSubscriptionEnabled()
			)
			{
				$payload->setCost(0);
			}
			elseif (
				Loader::includeModule('bitrix24')
				&& CallAISettings::isCopilotAutostartFeatureEnable()
				&& $call->autoStartRecording()
			)
			{
				$payload->setCost(0);
			}
		}

		$event = $this->fireCallAiTaskEvent($task, $payload, $context, $engine);
		if (
			($eventResult = $event->getResults()[0] ?? null)
			&& $eventResult instanceof EventResult
			&& $eventResult->getType() == EventResult::ERROR
		)
		{
			$log && $logger->error('AI processing was cancelled by event');

			// A cancelling handler is not a task failure for the automatic pipeline, which has
			// always read it as silence; the task simply keeps the status it was created with and
			// never reaches the queue. Only a caller that reports a start reads the flag.
			return $result->setData([self::LAUNCH_QUEUED => false]);
		}

		if (!($engine instanceof \Bitrix\AI\Engine))
		{
			$log && $logger->error('AI engine is unavailable');
			$result->addError((new CallAIError(CallAIError::AI_UNAVAILABLE_ERROR))->allowRecover());
		}
		else
		{
			$checkRestrictionResult = $this->checkRestriction($engine);

			if (!$checkRestrictionResult->isSuccess())
			{
				$log && $logger->error('AI engine error: '.$checkRestrictionResult->getError()->getMessage());
				$result->addError($checkRestrictionResult->getError());
			}
			else
			{
				$log && $logger->info(
					'Launch AI task: '.$task->getType()
					. ' Engine: '. $engine->getCode()
					. ' Payload: '. $task->decodePayload($payload->pack())
					. ' Language: '. ($context->getLanguage()?->getCode() ?? '')
					. ($payload instanceof \Bitrix\AI\Payload\Prompt ? ' Prompt code: '. $payload->getPromptCode() : '')
				);

				$launchIsQueued = true;
				$engine
					->setPayload($payload)
					->setHistoryState(false)
					->onSuccess(
						function (\Bitrix\AI\Result $result, ?string $queueHash = null)
						use (&$task, &$logger, &$launchIsQueued)
						{
							$saveResult = $task
								->setHash($queueHash)
								->setStatus($task::STATUS_PENDING)
								->save();

							// Every callback of the job finds its task by the stored hash, so a launch
							// whose hash did not reach the row is orphaned: nothing can report back on
							// it. The queue answers without a hash on its own cache hit, and the UPDATE
							// can be refused outright - the row after the save decides, not the call.
							$launchIsQueued = $saveResult->isSuccess() && (string)$task->getHash() !== '';
						}
					)
					->onError(
						function (Error $processingError)
						use (&$result, &$task)
						{
							$error = CallAIError::constructTaskError(CallAIError::AI_TASK_START_FAIL, $processingError, $task);
							$error->allowRecover();

							$task
								->setStatus($task::STATUS_FAILED)
								->setErrorCode($error->getCode())
								->setErrorMessage($error->getMessage(). ($error->getDescription() ? '; '.$error->getDescription() : ''))
								->save();

							$result->addError($error);
						}
					)
					->completionsInQueue();

				if (!$launchIsQueued)
				{
					$log && $logger->error('AI task was queued but its hash was not stored. Task Id:'.$task->getId());
					$result->setData([self::LAUNCH_QUEUED => false]);
				}
			}
		}

		if (!$result->isSuccess())
		{
			$log && $logger->error('AI processing has failed. Task Id:'.$task->getId().' Error: '.$result->getError()?->getMessage());

			$errorCode = $result->getError()?->getCode() ?? '';
			(new FollowUpAnalytics($call))
				->addAITaskFailed($task, $errorCode)
				->sendTelemetry(
					source: $task,
					status: 'error',
					errorCode: $errorCode,
					event: 'task_launch_error',
					error: $result->getError()
				)
			;

			$this->fireCallAiFailedEvent($task, $result->getError());
		}
		else
		{
			$log && $logger->info('New AI task has been set. TaskId:'.$task->getId().' Hash: '.$task->getHash());

			(new FollowUpAnalytics($call))
				->addAITaskLunch($task)
				->sendTelemetry(
					source: $task,
					status: 'success',
					event: 'task_launch_success'
				)
			;
		}

		return $result;
	}

	/**
	 * Restarts the AI chain of a call.
	 *
	 * $trackPack names the file the chain must be rebuilt from. Without it the newest pack of
	 * the call is taken, which is what every call-scoped caller means (the chat button, the
	 * expectation agent); a caller that acts on one chosen file has to pass it, otherwise a
	 * call with several packs would restart the analysis of a file nobody asked about.
	 *
	 * The pack is only reached while the call has no contentful transcription: a call that is
	 * already analysed gets its missing dependent tasks relaunched from the outcomes it holds,
	 * and a full set of contentful outcomes launches nothing at all. So this is a restart of an
	 * analysis that is missing or empty, not a re-analysis on demand - the operator-facing texts
	 * of the resend say exactly that.
	 *
	 * $request is the ask of the restart action of the service
	 * {@see \Bitrix\Call\Controller\CallController::restartFollowUpAction()}: which types to run,
	 * whether a contentful result still holds a type back, and whether only the types whose last
	 * task failed are taken. Without it nothing changes - every call-scoped caller (the chat
	 * button, the expectation agent, the callback of a resent file) runs the branch it ran before,
	 * by the condition it ran it by, and gets the answer it always got.
	 *
	 * @param int $callId
	 * @param Track|null $trackPack Track pack to rebuild the chain from, newest one if omitted
	 * @param FollowUpRestartRequest|null $request What the operator asked for, null for the call-scoped callers
	 * @return Result
	 */
	public function restartCallAiTask(
		int $callId,
		?Track $trackPack = null,
		?FollowUpRestartRequest $request = null
	): Result
	{
		if ($request?->purge)
		{
			// The erasing mode is a run with an order of its own around it, and the run in the
			// middle of that order is this very one {@see self::restartWithPurge()}.
			return $this->restartWithPurge($callId, $trackPack, $request);
		}

		return $this->runRestart($callId, $trackPack, $request);
	}

	/**
	 * The restart itself, without the order the erasing mode wraps it in.
	 */
	private function runRestart(
		int $callId,
		?Track $trackPack,
		?FollowUpRestartRequest $request
	): Result
	{
		$outcomeCollection = OutcomeCollection::getOutcomesByCallId($callId);

		// The latest outcome with meaningful content per type. Any contentful outcome means
		// that sense is "done" (an empty retry on top of a valid result must not shadow it),
		// and keeping one source per type prevents relaunching downstream tasks twice or from
		// a stale source version.
		$contentfulByType = [];
		foreach ($outcomeCollection as $outcomeExisting)
		{
			$type = $outcomeExisting->getType();
			if (isset($contentfulByType[$type]))
			{
				continue;// the latest contentful outcome of this type is already taken
			}
			$sourceSense = $outcomeExisting->getSenseContent();
			if ($sourceSense instanceof AISenseContent && $sourceSense->hasContent(ignoreComputedCriteria: true))
			{
				$contentfulByType[$type] = $outcomeExisting;
			}
		}

		// What became of every type of the set, filled in as the decisions are taken. A caller that
		// named no set gets no report: nothing but the new action reads one.
		$report = [];
		$requestedSenses = $request === null
			? null
			: $this->resolveRestartSenses($callId, $request, $report)
		;

		$transcriptionIsRequested =
			$requestedSenses === null
			|| in_array(SenseType::TRANSCRIBE->value, $requestedSenses, true)
		;

		// The full run is the one and only way a transcription is ever launched: the graph of the
		// chain does not open it, because no result produces the root of the chain. Without the
		// force mark the condition is the one this branch always had - no transcription with
		// content - so every caller that names no request keeps taking it exactly as before.
		if ($transcriptionIsRequested && (!isset($contentfulByType[SenseType::TRANSCRIBE->value]) || $request?->force))
		{
			return $this->restartWholeChain($callId, $trackPack, $requestedSenses, $report, $request);
		}

		return $this->relaunchDependentTasks(
			$callId,
			$contentfulByType,
			(bool)$request?->force,
			$requestedSenses,
			$report
		);
	}

	/**
	 * The erasing mode: the text results the call holds are replaced by a new generation of them
	 * instead of standing beside it.
	 *
	 * The order is write off - launch - erase, and it is what keeps the call from being left with
	 * no analysis at all. Erasing first would take the previous answer away from a launch that may
	 * never happen; erasing the pending tasks with it would take away the answer that is on its
	 * way. So the previous generation is taken away only once a new one is known to be running,
	 * and a run that launched nothing leaves the call exactly as it found it.
	 *
	 * "Running" is asked per type and not once for the whole set {@see self::launchedSenses()}: a
	 * run that started one type and was refused the next would otherwise take the answer of the
	 * refused type away with nothing to put in its place, and nothing would ever build it again -
	 * the graph hangs every text type on a result of another type, and the transcription this mode
	 * never runs.
	 *
	 * The write-off comes first for a reason of its own: a type whose task is still pending is
	 * skipped by the run whatever else is asked of it {@see self::relaunchDependentTasks()}, so
	 * without it the mode would answer "already running" and erase nothing. And because it comes
	 * first, it is taken back where the run it cleared the way for never happened
	 * {@see self::restorePendingTasks()}: a task written off by this method was being answered for
	 * when the operator pressed the button, and a refusal that left it ended would have killed the
	 * analysis that was on its way and started none - the one outcome the whole order exists to
	 * avoid. The same on the way out of an exception, for the same reason.
	 *
	 * Taken back per type, by the very set the erasing is limited to: the write-off is one act
	 * over the whole set, while the run under it answers type by type, so a run that started one
	 * type and was refused the next leaves the refused one written off for nothing - its result is
	 * whole, no new task of it is coming, and the operator would be shown a failure of the one
	 * type of the set that never failed. Everything outside the set a new generation is really on
	 * its way for goes back exactly as it was {@see self::writtenOffOutside()}, which on a run that
	 * launched nothing at all is the whole write-off and is why that branch reads the same.
	 *
	 * Only the text types are ever touched here {@see SenseType::isTextual()}: the drop of a
	 * transcription takes the recording of the call with it
	 * {@see Task\TranscribeCallRecord::drop()}, and a call whose recording is gone can never be
	 * analysed again.
	 *
	 * The whole sequence runs under the lock of the call taken by the action
	 * {@see \Bitrix\Call\Controller\CallController::restartFollowUpAction()}, and the invariant
	 * holds exactly within it: a task created between the write-off and the erasing by one of the
	 * paths that do not take that lock survives by the boundary of the generation, while the
	 * result it was built from does not. That residual case is accepted by the ADR of the feature
	 * and is not closed here - closing it would mean making the existing paths take a new lock.
	 */
	private function restartWithPurge(int $callId, ?Track $trackPack, FollowUpRestartRequest $request): Result
	{
		// Both boundaries are read here, before the first task of the attempt exists, and neither
		// moves for the rest of it. Two of them and not one: a result carries no id of the task
		// that produced it - `OUTCOME_ID` of a task names the result it was BUILT FROM - so the
		// tasks are cut off by their own sequence and the results by theirs.
		$taskBoundary = AITask::getLatestTaskId($callId);
		$outcomeBoundary = Outcome::getLatestOutcomeId($callId);

		$textual = self::textualSenses($request);

		$writeOffError = (new CallAIError(CallAIError::AI_OVERVIEW_TASK_ERROR))->allowRecover();
		$writtenOff = $this->abandonPendingTasks($callId, $writeOffError, $textual);

		try
		{
			$launch = $this->runRestart($callId, $trackPack, self::forcedRequest($request));
		}
		catch (\Throwable $exception)
		{
			$this->restorePendingTasks($writtenOff, $writeOffError);

			throw $exception;
		}

		if (!self::restartLaunchedAnything($launch))
		{
			// Nothing is on its way, so nothing is taken away: the call keeps the analysis it had,
			// and the tasks that were producing it keep waiting for their answers.
			$this->restorePendingTasks($writtenOff, $writeOffError);

			return $launch;
		}

		$launched = self::launchedSenses($launch, $textual);

		// The write-off was one act over the whole set, and what it cleared the way for turned out
		// to be a run over part of it. A type the run did not take on is a type whose result stays
		// and whose pending task is still being answered for: its write-off bought nothing, and
		// left standing it would tell the operator "the analysis failed" about the one type of the
		// set that is perfectly whole. So it is taken back here, by the same rule the refused run
		// takes it all back by - the difference between the two being how much of the set the
		// rule applies to.
		$this->restorePendingTasks(self::writtenOffOutside($writtenOff, $launched), $writeOffError);

		$this->purgeGeneration($callId, $launched, $taskBoundary, $outcomeBoundary);

		return $launch;
	}

	/**
	 * The rows of a write-off that belong to a type no new generation is on its way for.
	 *
	 * @param array<int, array{status: string, type: string}> $writtenOff
	 *     {@see self::abandonPendingTasks()}
	 * @param string[] $launched the types a run really started {@see self::launchedSenses()}
	 * @return array<int, array{status: string, type: string}>
	 */
	private static function writtenOffOutside(array $writtenOff, array $launched): array
	{
		return array_filter(
			$writtenOff,
			static fn(array $written): bool => !in_array($written['type'], $launched, true)
		);
	}

	/**
	 * The types of the set a new generation is really on its way for, read off the per-type report
	 * the run answers with {@see self::withSenseReport()}.
	 *
	 * Two outcomes say a task of the type is coming: `started` - the run queued it itself - and
	 * `chained` - the chain of a type it did queue will open it
	 * {@see self::relaunchDependentTasks()}, {@see self::restartWholeChain()}. Every other outcome
	 * is a type this run leaves alone, and the result it holds is the only one the call will ever
	 * have of it.
	 *
	 * Narrowed by the set of the mode and not taken from the report alone: the report answers for
	 * every type the request named, and what may be erased at all is the text types
	 * {@see self::textualSenses()}.
	 *
	 * @param string[] $textual the set the mode was asked about, text types only
	 * @return string[] in the canonical order of the types
	 */
	private static function launchedSenses(Result $launch, array $textual): array
	{
		$onItsWay = [];
		foreach ($launch->getData()['senses'] ?? [] as $sense)
		{
			$outcome = (string)($sense['outcome'] ?? '');
			if ($outcome === self::OUTCOME_STARTED || $outcome === self::OUTCOME_CHAINED)
			{
				$onItsWay[(string)($sense['type'] ?? '')] = true;
			}
		}

		return array_values(array_filter(
			$textual,
			static fn(string $senseValue): bool => isset($onItsWay[$senseValue])
		));
	}

	/**
	 * Takes away the results and the tasks of the named types that belong to the generation the
	 * boundaries cut off, and nothing else.
	 *
	 * Everything above a boundary is the attempt that has just started - the tasks it created and
	 * a result that arrived while it was starting - and it is the answer the call is being left
	 * with, so it stays.
	 *
	 * The predicate of the type is asked again right here, at the one place a row is actually
	 * dropped: it is the last thing standing between an erasing and the recording of the call
	 * {@see Task\TranscribeCallRecord::drop()}, and a guard that lives only where the set is
	 * chosen guards nothing once another caller appears.
	 *
	 * Nothing of a row but its id is ever read here, and the rows go in batches: the whole of this
	 * runs inside the lock of the restart, synchronously, while the operator waits - and the one
	 * thing that grows with the history of the call is exactly what the feature invites the
	 * operator to grow. Built of objects it was one SELECT of the longText content of every
	 * erased result, and then a handful of statements per row; built of ids it is one SELECT of
	 * ids and a bounded number of deletes per type, whatever the history
	 * {@see self::deleteRowsByIds()}.
	 *
	 * The rows are deleted by statement and not through the drop of their objects, and the one
	 * thing that costs is the type: the drop of a transcription takes the recording of the call
	 * with it {@see Task\TranscribeCallRecord::drop()}, which is why the predicate above is asked
	 * right here and not only where the set is chosen. Every text type drops as the plain deletion
	 * of its row {@see Task\AITask::drop()}, and a result drops as its properties and then itself
	 * {@see Outcome::drop()} - which is the pair of statements below, in that order, so that no
	 * property is ever left behind a result that is gone.
	 *
	 * @param string[] $textual values of {@see SenseType}, text types only, and only the ones a
	 *     new generation is on its way for {@see self::launchedSenses()}
	 */
	private function purgeGeneration(int $callId, array $textual, int $taskBoundary, int $outcomeBoundary): void
	{
		foreach ($textual as $senseValue)
		{
			$senseType = SenseType::tryFrom($senseValue);
			if ($senseType === null || !$senseType->isTextual())
			{
				continue;
			}

			$outcomeIds = Outcome::getOutcomeIdsOfSenseUpTo($callId, $senseType, $outcomeBoundary);
			if ($outcomeIds !== [])
			{
				self::deleteRowsByIds(CallOutcomePropertyTable::getTableName(), 'OUTCOME_ID', $outcomeIds);
				self::deleteRowsByIds(CallOutcomeTable::getTableName(), 'ID', $outcomeIds);
			}

			$taskIds = AITask::getTaskIdsOfSenseUpTo($callId, $senseType, $taskBoundary);
			if ($taskIds !== [])
			{
				self::deleteRowsByIds(CallAITaskTable::getTableName(), 'ID', $taskIds);
			}
		}
	}

	/**
	 * Deletes the named rows in batches of {@see self::PURGE_DELETE_CHUNK}.
	 *
	 * Every value is an id the query above read out of the very table it is about, and it is cast
	 * to an integer before it reaches the statement: nothing of a request ever gets here.
	 *
	 * @param int[] $ids
	 */
	private static function deleteRowsByIds(string $table, string $column, array $ids): void
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$quotedTable = $helper->quote($table);
		$quotedColumn = $helper->quote($column);

		foreach (array_chunk($ids, self::PURGE_DELETE_CHUNK) as $chunk)
		{
			$list = implode(',', array_map('intval', $chunk));
			$connection->queryExecute("DELETE FROM {$quotedTable} WHERE {$quotedColumn} IN ({$list})");
		}
	}

	/**
	 * The types the erasing mode runs and erases: the named list of the request, or every text
	 * type where it named none.
	 *
	 * The set is also what the write-off of the pending tasks is limited to, so the predicate is
	 * applied here and not only where the results are taken away: a media type that reached this
	 * list would have its running transcription written off for nothing.
	 *
	 * @return string[] in the canonical order of the types
	 */
	private static function textualSenses(FollowUpRestartRequest $request): array
	{
		return array_values(array_filter(
			self::resolveRequestedSenses($request),
			static fn(string $senseValue): bool => SenseType::tryFrom($senseValue)?->isTextual() === true
		));
	}

	/**
	 * The ask of the erasing mode as the run below reads it: forced.
	 *
	 * A contentful result is exactly what this mode is there to replace, so it may not hold a type
	 * back - without the mark the run would skip every type it was asked to erase and the mode
	 * would do nothing at all. The copy is taken instead of writing into the request: the request
	 * is the ask of the operator and the telemetry of the action is built from it.
	 */
	private static function forcedRequest(FollowUpRestartRequest $request): FollowUpRestartRequest
	{
		if ($request->force)
		{
			return $request;
		}

		$forced = clone $request;
		$forced->force = true;

		return $forced;
	}

	/**
	 * Whether the restart got anything into the queue of the engine.
	 *
	 * Read from both marks the answer carries: a batch that went through says so with the status
	 * of the call, a batch that failed after a launch of its own says so with the flag
	 * {@see self::RESTART_LAUNCHED}, and the erasing mode has to see a launch in either of them.
	 */
	private static function restartLaunchedAnything(Result $result): bool
	{
		$data = $result->getData();

		return ($data['status'] ?? null) === self::RESTART_STATUS_STARTED
			|| ($data[self::RESTART_LAUNCHED] ?? false) === true
		;
	}

	/**
	 * The full run: the chain is rebuilt from the file of the call, starting with a new
	 * transcription, and every other type of the set follows it through the graph.
	 *
	 * $request is the ask of the operator, null for every call-scoped caller: only an ask that
	 * names the source of the recording has a source to hold the run to, and only an ask answered
	 * to the admin panel tells the two refusals about a missing recording apart.
	 *
	 * @param string[]|null $requestedSenses
	 * @param array<string, array{outcome: string, reason?: string}> $report
	 */
	private function restartWholeChain(
		int $callId,
		?Track $trackPack,
		?array $requestedSenses,
		array $report,
		?FollowUpRestartRequest $request = null
	): Result
	{
		$result = new Result();

		// The wait belongs to the file the restart will process. A named pack asks about its
		// own task: the newest task of the call may belong to another pack of the same call,
		// and then it answers the wrong question in both directions - it holds back a resend
		// nothing is analysing, and lets one through while the chosen pack is still running.
		// Left unnamed, the pack is the newest one taken below, so the question stays
		// call-scoped, which is what every call-scoped caller means.
		$analysisIsRunning = $trackPack
			? AITask::hasPendingTaskForTrack((int)$trackPack->getId(), SenseType::TRANSCRIBE)
			: (bool)AITask::getTaskForCall($callId, SenseType::TRANSCRIBE)?->isPending()
		;
		if ($analysisIsRunning)
		{
			// The running attempt is the one the rest of the set will be built from, so the types
			// behind the transcription are chained to it rather than refused.
			return self::withSenseReport(
				$result->setData(['status' => self::RESTART_STATUS_ALREADY_RUNNING]),
				$requestedSenses,
				self::reportWholeChain($report, $requestedSenses, self::OUTCOME_SKIPPED_PENDING, '', self::OUTCOME_CHAINED, '')
			);
		}

		$trackPack ??= Track::getTrackForCall($callId, Track::TYPE_TRACK_PACK);
		if (!$trackPack)
		{
			// Explicit retry but track pack was never delivered — a delivery failure, not a wait timeout.
			return $this->recordingIsGone(
				$result,
				$request === null
					? CallAIError::TRACK_PACK_NOT_FOUND_ERROR
					: $this->missingRecordCode($callId),
				$requestedSenses,
				$report
			);
		}

		if (
			$request?->source === FollowUpRestartRequest::SOURCE_STORED
			&& !TrackService::getInstance()->hasLiveTaskSource($trackPack)
		)
		{
			// The operator asked for a run on the copy the portal holds, and the file that copy is
			// made of is not there any more - a file a user removed from the chat leaves the row of
			// the track behind, because the module has no handler of that deletion. Refused here
			// and not by the task: the answer is owed before a task is created and before the
			// quota of one is spent. The service is not asked for the recording either - that is
			// the other source of the same axis, and fetching the file here would quietly turn the
			// ask into a full delivery.
			return $this->recordingIsGone(
				$result,
				CallAIError::TRACK_PACK_NOT_FOUND_ERROR,
				$requestedSenses,
				$report
			);
		}

		$trackResult = $this->processTrack($trackPack);
		if (!$trackResult->isSuccess() || self::launchWasNotQueued($trackResult))
		{
			// Nothing was durably launched, so neither answer may be dressed up as `started`:
			// the failed launch carries its error, the unqueued one no status at all. The rest of
			// the set waits for a transcription that is not coming, and says so.
			return self::withSenseReport(
				$trackResult,
				$requestedSenses,
				self::reportWholeChain(
					$report,
					$requestedSenses,
					self::OUTCOME_LAUNCH_FAILED,
					(string)$trackResult->getError()?->getCode(),
					self::OUTCOME_BLOCKED_PRECONDITION,
					SenseType::TRANSCRIBE->value
				)
			);
		}

		return self::withSenseReport(
			$trackResult->setData(['status' => self::RESTART_STATUS_STARTED]),
			$requestedSenses,
			self::reportWholeChain($report, $requestedSenses, self::OUTCOME_STARTED, '', self::OUTCOME_CHAINED, '')
		);
	}

	/**
	 * The one answer of a full run that found no recording to run on, in whichever of the codes
	 * says why. The transcription is the launch that failed, and everything behind it in the chain
	 * is blocked by the transcription it will never get.
	 *
	 * @param string[]|null $requestedSenses
	 * @param array<string, array{outcome: string, reason?: string}> $report
	 */
	private function recordingIsGone(
		Result $result,
		string $code,
		?array $requestedSenses,
		array $report
	): Result
	{
		return self::withSenseReport(
			$result->addError($this->missingTrackPackError($code)),
			$requestedSenses,
			self::reportWholeChain(
				$report,
				$requestedSenses,
				self::OUTCOME_LAUNCH_FAILED,
				$code,
				self::OUTCOME_BLOCKED_PRECONDITION,
				SenseType::TRANSCRIBE->value
			)
		);
	}

	/**
	 * Which of the two things "there is no recording" can mean for this call: a recording that was
	 * never delivered, or one the retention of the portal has taken since.
	 *
	 * Told by the age of the call against the depth the retention works to
	 * {@see self::FINISH_TASK_DEPTH_DAYS} and by the call having no row of a track left at all -
	 * the cleanup leaves none, so there is nothing else left to read the fact of a deletion from,
	 * and a store of that fact would have to be introduced by a migration and would still be empty
	 * for exactly the calls the question is asked about. On the boundary itself the answer is
	 * approximate, which is the assumption the ADR of the feature took.
	 *
	 * The depth is the one of the retention and not a second copy of the term: a term written down
	 * twice is a term that drifts.
	 */
	private function missingRecordCode(int $callId): string
	{
		$call = Registry::getCallWithId($callId);
		$happenedAt = $call?->getEndDate() ?? $call?->getStartDate();
		if ($happenedAt === null)
		{
			return CallAIError::TRACK_PACK_NOT_FOUND_ERROR;
		}

		$depthDays = self::FINISH_TASK_DEPTH_DAYS;
		if ($happenedAt->getTimestamp() > (new DateTime())->add("-{$depthDays} days")->getTimestamp())
		{
			return CallAIError::TRACK_PACK_NOT_FOUND_ERROR;
		}

		$tracksLeft = CallTrackTable::query()
			->setSelect(['ID'])
			->where('CALL_ID', $callId)
			->setLimit(1)
			->exec()
			->getSelectedRowsCount()
		;

		return $tracksLeft > 0
			? CallAIError::TRACK_PACK_NOT_FOUND_ERROR
			: self::ERROR_TRACK_PACK_EXPIRED
		;
	}

	/**
	 * Relaunch of the dependent tasks from the latest contentful source of each type.
	 *
	 * @param array<string, Outcome> $contentfulByType
	 * @param string[]|null $requestedSenses null where nobody named a set: every type of the graph
	 * @param array<string, array{outcome: string, reason?: string}> $report
	 */
	private function relaunchDependentTasks(
		int $callId,
		array $contentfulByType,
		bool $force,
		?array $requestedSenses,
		array $report
	): Result
	{
		$result = new Result();

		$launchedAny = false;
		$launchWasNotDurable = false;
		$missingSenseIsPending = false;// a genuinely MISSING sense (not any sense) is covered by a pending task
		// A type the repeat of the failed ones kept out of the set because its last task is running
		// {@see self::narrowToFailedSenses()}: the walk below never sees it, and its answer is on
		// its way all the same.
		$narrowedOutSenseIsPending = in_array(
			self::OUTCOME_SKIPPED_PENDING,
			array_column($report, 'outcome'),
			true
		);
		$startedTypes = [];

		// The sources are walked in the canonical order of the chain instead of the order their
		// results happen to be stored in: a type whose source is running again in this very attempt
		// must not be built from the result of the attempt before, and only a fixed order makes
		// that question answerable at all.
		foreach (SenseType::cases() as $sourceType)
		{
			$outcomeExisting = $contentfulByType[$sourceType->value] ?? null;
			if ($outcomeExisting === null)
			{
				continue;
			}

			foreach ($this->getTaskToLaunchByOutcome($outcomeExisting) as $taskSenseType)
			{
				if (!self::senseIsRequested($taskSenseType, $requestedSenses))
				{
					continue;// the request named its types, and this is not one of them
				}

				if (isset($startedTypes[$sourceType->value]))
				{
					// The source is being run again right here, so the chain will build this type
					// from the result of THAT run {@see self::buildTasksByOutcome()}. Building it
					// now from the result of the previous run would pay for the type twice.
					$report[$taskSenseType->value] = ['outcome' => self::OUTCOME_CHAINED];
					continue;
				}

				if (!$force && isset($contentfulByType[$taskSenseType->value]))
				{
					$report[$taskSenseType->value] = ['outcome' => self::OUTCOME_SKIPPED_CONTENTFUL];
					continue;// the target sense already has meaningful content
				}

				// The target outcome is missing, semantically empty, or asked for again: relaunch the task
				$task = AITask::getTaskForCall($callId, $taskSenseType);
				if ($task && $task->isPending())
				{
					// A task already running is not doubled, force or no force: it answers the very
					// question the second one would ask.
					$missingSenseIsPending = true;
					$report[$taskSenseType->value] = ['outcome' => self::OUTCOME_SKIPPED_PENDING];
					continue;// wait more
				}

				$taskClass = $taskSenseType->getTaskClass();
				$task = new $taskClass();
				$task->setPayload($outcomeExisting);
				if ($outcomeExisting->getLanguageId())
				{
					$task->setLanguageId($outcomeExisting->getLanguageId());
				}
				$dbResult = $task->save();
				if (!$dbResult->isSuccess())
				{
					// Skipped exactly as before, without an error: the automatic callers have always
					// read a refused row as silence. It only stops this restart claiming a start.
					$launchWasNotDurable = true;
					$report[$taskSenseType->value] = [
						'outcome' => self::OUTCOME_LAUNCH_FAILED,
						'reason' => (string)($dbResult->getErrors()[0]?->getCode() ?? ''),
					];
					continue;
				}

				$launchResult = $this->launchTask($task);
				if (!$launchResult->isSuccess())
				{
					// The error stands for the whole batch, but it takes away only its own task: the
					// dependent tasks of one source are each built from a result that is already
					// stored, so none of them waits for what another produces.
					//
					// The row is written off with it {@see self::writeOffUnlaunchedTask()}: a launch
					// that failed before the engine - on a payload it could not build, on an engine
					// that is not there, on a restriction that refused it - reaches no callback of
					// the engine, and it is that callback which ends the task on the ordinary path
					// {@see self::launchTask()}. Left in the status it was created with, a task
					// nobody is going to answer for reads as the call still running to every later
					// question {@see AITask::hasPendingTaskForCall()} and holds the final message of
					// the call back for good. The guard of the helper skips the task a launch that
					// did reach the engine has already ended, so the answer of the engine stays.
					$this->writeOffUnlaunchedTask($task, $launchResult);
					$result->addErrors($launchResult->getErrors());
					$report[$taskSenseType->value] = [
						'outcome' => self::OUTCOME_LAUNCH_FAILED,
						'reason' => (string)($launchResult->getError()?->getCode() ?? ''),
					];
					continue;
				}

				if (self::launchWasNotQueued($launchResult))
				{
					// Skipped like a refused row, not breaking the batch: nothing will ever report
					// back on a launch the queue never took, and that costs this type alone.
					//
					// The row it left behind is written off with it {@see self::writeOffUnlaunchedTask()}:
					// kept in the status it was created with, a task nobody is going to answer for
					// reads as the call still running to every later question
					// {@see AITask::hasPendingTaskForCall()} and holds the final message of the
					// call back for good - this path arms no wait of its own, and only the agent
					// of the old tasks would free it, sixty days on {@see self::finishTasks()}.
					// What the operator is told does not change with it: the type did not run.
					$this->writeOffUnlaunchedTask($task, $launchResult);
					$launchWasNotDurable = true;
					$report[$taskSenseType->value] = ['outcome' => self::OUTCOME_LAUNCH_FAILED];
					continue;
				}

				$launchedAny = true;
				$startedTypes[$taskSenseType->value] = true;
				$report[$taskSenseType->value] = ['outcome' => self::OUTCOME_STARTED];
			}
		}

		$report = self::reportSensesLeftOut($report, $requestedSenses, $contentfulByType);

		if (!$result->isSuccess() || $launchWasNotDurable)
		{
			// `started` speaks for the whole restart, so a batch that failed or left one of its
			// tasks unqueued withholds it even where a sibling did start: the failed batch answers
			// with its error, the unqueued one with no status at all, which is what this module
			// answered before the status contract existed. Neither may claim a start.
			return self::withSenseReport(
				$launchedAny ? $result->setData([self::RESTART_LAUNCHED => true]) : $result,
				$requestedSenses,
				$report
			);
		}

		if ($launchedAny)
		{
			return self::withSenseReport(
				$result->setData(['status' => self::RESTART_STATUS_STARTED]),
				$requestedSenses,
				$report
			);
		}

		if ($missingSenseIsPending || $narrowedOutSenseIsPending)
		{
			return self::withSenseReport(
				$result->setData(['status' => self::RESTART_STATUS_ALREADY_RUNNING]),
				$requestedSenses,
				$report
			);
		}

		// Not atomic: two restarts racing within the same instant can both observe "nothing
		// pending" for the same missing sense and both report `started` — accepted for this
		// narrow, single-user-triggered flow.
		return self::withSenseReport(
			$result->setData(['status' => self::RESTART_STATUS_ALREADY_COMPLETE]),
			$requestedSenses,
			$report
		);
	}

	/**
	 * The set of types the request asks about, narrowed to the failed ones where it asks for those.
	 *
	 * @param array<string, array{outcome: string, reason?: string}> $report
	 * @return string[]
	 */
	private function resolveRestartSenses(int $callId, FollowUpRestartRequest $request, array &$report): array
	{
		$senses = self::resolveRequestedSenses($request);

		return $request->onlyFailed
			? $this->narrowToFailedSenses($callId, $senses, $report)
			: $senses
		;
	}

	/**
	 * The types the request asks about before anything is known about the call: the named list, or
	 * the list the other marks of the request complete an empty one into (DTO-01 of the SDD).
	 *
	 * The erasing mode takes the text types alone - a transcription is never erased and never
	 * enters the set that way - and every other mode takes them all.
	 *
	 * @return string[] in the canonical order of the types
	 */
	public static function resolveRequestedSenses(FollowUpRestartRequest $request): array
	{
		$named = $request->normalizedSenses();
		$senses = [];
		foreach (SenseType::cases() as $senseType)
		{
			if ($named === [])
			{
				if ($request->purge && $senseType === SenseType::TRANSCRIBE)
				{
					continue;
				}
				$senses[] = $senseType->value;

				continue;
			}

			if (in_array($senseType->value, $named, true))
			{
				$senses[] = $senseType->value;
			}
		}

		return $senses;
	}

	/**
	 * Narrows the set to the types whose LAST task ended in a failure.
	 *
	 * The last task and not any task: an old failure standing behind a newer success is an answer
	 * the call already has. The status of that task is the whole criterion - the code of the
	 * failure narrows nothing, and there is no per-type limit of attempts. A repeat here is asked
	 * for by an operator of the service by an explicit action, and that operator sees the call, the
	 * complaint of the client and a context the code of an error does not carry; a guess of the
	 * portal about which failures are worth repeating would only decide in their place, and decide
	 * wrong - `HASH_EXPIRED` reads like a verdict of the engine and means the portal never waited
	 * its answer out (`\Bitrix\AI\QueueJob::expire()`), with the consumption rolled back.
	 *
	 * A last task still running is neither: not a failure and not an answer the call has. The
	 * answer of that type is on its way, so the type is reported `skipped_pending` - the outcome
	 * the relaunch gives a running task {@see self::relaunchDependentTasks()} - and kept out of
	 * the set: a repeat would double the running task, not replace it. A run left with nothing to
	 * start because of it answers that the analysis is running, not that it is complete.
	 *
	 * @param string[] $senses
	 * @param array<string, array{outcome: string, reason?: string}> $report
	 * @return string[]
	 */
	private function narrowToFailedSenses(int $callId, array $senses, array &$report): array
	{
		$lastTasks = AITask::getTasksForCall($callId);

		$failed = [];
		foreach ($senses as $senseValue)
		{
			$task = $lastTasks[$senseValue] ?? null;
			if ($task !== null && $task->isPending())
			{
				// The answer of this type is on its way: neither a failure to repeat nor a result
				// the call already has. A second task would only double the running one.
				$report[$senseValue] = ['outcome' => self::OUTCOME_SKIPPED_PENDING];
				continue;
			}

			if ($task === null || !$task->isFailed())
			{
				$report[$senseValue] = ['outcome' => self::OUTCOME_NOT_FAILED];
				continue;
			}

			$failed[] = $senseValue;
		}

		return $failed;
	}

	/**
	 * @param string[]|null $requestedSenses null means "every type of the graph", as the callers
	 *     that name no set have always meant
	 */
	private static function senseIsRequested(SenseType $senseType, ?array $requestedSenses): bool
	{
		return $requestedSenses === null || in_array($senseType->value, $requestedSenses, true);
	}

	/**
	 * The report of a full run: the transcription answers for itself, every other type of the set
	 * answers for the chain that follows it.
	 *
	 * @param array<string, array{outcome: string, reason?: string}> $report
	 * @param string[]|null $requestedSenses
	 * @return array<string, array{outcome: string, reason?: string}>
	 */
	private static function reportWholeChain(
		array $report,
		?array $requestedSenses,
		string $transcriptionOutcome,
		string $transcriptionReason,
		string $chainOutcome,
		string $chainReason
	): array
	{
		foreach ($requestedSenses ?? [] as $senseValue)
		{
			if (isset($report[$senseValue]))
			{
				continue;
			}

			$report[$senseValue] = $senseValue === SenseType::TRANSCRIBE->value
				? ['outcome' => $transcriptionOutcome, 'reason' => $transcriptionReason]
				: ['outcome' => $chainOutcome, 'reason' => $chainReason]
			;
		}

		return $report;
	}

	/**
	 * The types of the set the walk of the chain never reached: their source holds nothing
	 * contentful to build them from, and the operator is told which one is missing instead of
	 * being told nothing at all.
	 *
	 * @param array<string, array{outcome: string, reason?: string}> $report
	 * @param string[]|null $requestedSenses
	 * @param array<string, Outcome> $contentfulByType
	 * @return array<string, array{outcome: string, reason?: string}>
	 */
	private static function reportSensesLeftOut(array $report, ?array $requestedSenses, array $contentfulByType): array
	{
		foreach ($requestedSenses ?? [] as $senseValue)
		{
			if (isset($report[$senseValue]) || SenseType::tryFrom($senseValue) === null)
			{
				continue;
			}

			$predecessor = self::predecessorOf(SenseType::from($senseValue));
			if ($predecessor !== null && !isset($contentfulByType[$predecessor->value]))
			{
				$report[$senseValue] = [
					'outcome' => self::OUTCOME_BLOCKED_PRECONDITION,
					'reason' => $predecessor->value,
				];
				continue;
			}

			// Everything the graph can reach from a contentful source is reached by the walk, so
			// what is left here is the root of the chain - contentful, which is the very reason
			// the full run was not taken.
			$report[$senseValue] = ['outcome' => self::OUTCOME_SKIPPED_CONTENTFUL];
		}

		return $report;
	}

	/**
	 * Fills the report in for the types the run never named and the chain will run all the same.
	 *
	 * A result of a type reopens everything below it in the graph, whatever the request asked for
	 * and whatever content the lower types hold: the builder of the chain rebuilds a type unless
	 * its last task is finished AND newer than the result at hand
	 * {@see self::buildTasksByOutcome()}, {@see self::taskOutlivesSource()}. So every type
	 * reachable from one that started here - or from one that is itself chained to a start - is a
	 * type this attempt will run and the client will pay for.
	 *
	 * Without this the report says the opposite of what happens, in both shapes the operator can
	 * ask for. A repeat of the failed types alone answers `not_failed` for the four types behind a
	 * transcription it is restarting, and all four are rebuilt. A named list of one type leaves
	 * the types below it out of the answer entirely - they never enter the set
	 * {@see self::senseIsRequested()} - and they are rebuilt too. The operator reads "left alone"
	 * off both and the quota says otherwise.
	 *
	 * What a type already says about itself is kept where it is a start of its own: `started` and
	 * `chained` are the two the walk is looking for and it has nothing to add to either. Every
	 * other answer is about a decision this run took before the graph was walked, and the graph
	 * outlives it.
	 *
	 * @param array<string, array{outcome: string, reason?: string}> $report
	 * @return array<string, array{outcome: string, reason?: string}>
	 */
	private static function reportTypesTheChainReopens(array $report): array
	{
		$onItsWay = [];
		foreach ($report as $senseValue => $row)
		{
			$outcome = (string)($row['outcome'] ?? '');
			if ($outcome === self::OUTCOME_STARTED || $outcome === self::OUTCOME_CHAINED)
			{
				$onItsWay[] = (string)$senseValue;
			}
		}

		while ($onItsWay !== [])
		{
			$senseValue = array_shift($onItsWay);
			$senseType = SenseType::tryFrom($senseValue);
			if ($senseType === null)
			{
				continue;
			}

			foreach (self::getTaskToLaunchByType($senseType->value) as $openedType)
			{
				$outcome = (string)($report[$openedType->value]['outcome'] ?? '');
				if ($outcome === self::OUTCOME_STARTED || $outcome === self::OUTCOME_CHAINED)
				{
					continue;// already answered for by the run itself, and answered the same way
				}

				$report[$openedType->value] = ['outcome' => self::OUTCOME_CHAINED];
				$onItsWay[] = $openedType->value;
			}
		}

		return $report;
	}

	/**
	 * Puts the per-type report into the answer of the restart, in the canonical order of the types.
	 * A caller that named no set gets the answer it always got, without a report of any kind.
	 *
	 * @param string[]|null $requestedSenses
	 * @param array<string, array{outcome: string, reason?: string}> $report
	 */
	private static function withSenseReport(Result $result, ?array $requestedSenses, array $report): Result
	{
		if ($requestedSenses === null)
		{
			return $result;
		}

		$report = self::reportTypesTheChainReopens($report);

		$senses = [];
		foreach (SenseType::cases() as $senseType)
		{
			$row = $report[$senseType->value] ?? null;
			if ($row === null)
			{
				continue;
			}

			$sense = ['type' => $senseType->value, 'outcome' => $row['outcome']];
			if (($row['reason'] ?? '') !== '')
			{
				$sense['reason'] = $row['reason'];
			}
			$senses[] = $sense;
		}

		return $result->setData($result->getData() + ['senses' => $senses]);
	}

	/**
	 * A launch that left no queued task: an onCallAiTask handler cancelled it before the queue, or
	 * the queue took the job and the UPDATE storing its hash was refused. Either way nothing will
	 * ever report back on the task, so a restart that has to answer whether the analysis started
	 * cannot answer `started` where the automatic pipeline stays silent.
	 */
	private static function launchWasNotQueued(Result $launchResult): bool
	{
		return ($launchResult->getData()[self::LAUNCH_QUEUED] ?? true) === false;
	}

	/**
	 * Retry of the analysis a user asked for, from either manual entry point: the button of the
	 * failure chat message {@see \Bitrix\Call\Integration\Im\CallFollowupBot::onCommandAdd()} and
	 * the public action {@see \Bitrix\Call\Controller\TaskAI::restartAction()}.
	 *
	 * Access to the call is NOT checked here and is up to the caller: the path behind is shared
	 * with the expectation agent and with the resend from the service, neither of which has a
	 * portal user. The taken pack is named to the restart: a call may hold several packs.
	 *
	 * A retry that set something going is watched again {@see self::watchRetriedCall()}: the whole
	 * point of the wait is the final answer, and a retry left unwatched would leave the user with
	 * neither a result nor a timeout if the callback of the analysis were lost a second time. A
	 * retry that set nothing going has already told the user everything it knows.
	 */
	public function retryCallAiTask(Call $call): Result
	{
		$callId = (int)$call->getId();

		if ($call->isRecordDestroyed())
		{
			// Ahead of everything else, the flag included: a retry of a call whose recording a user
			// destroyed has nothing to retry, and restoring the flag below would outlive this
			// refusal - the flag is all the incoming callback of the service reads before taking a
			// file of the call {@see \Bitrix\Call\Controller\CallController::trackReadyAction()},
			// so a flag restored here would let a file arriving later through
			// {@see Call::isRecordDestroyed()}.
			return (new Result())->addError(
				new \Bitrix\Call\Error(self::ERROR_RECORD_DESTROYED, 'The record of the call was destroyed')
			);
		}

		if (!$call->isAiAnalyzeEnabled())
		{
			$this->restoreAiAnalyze($call);
		}

		$result = $this->restartCallAiTask($callId);
		if ($result->getError()?->getCode() !== CallAIError::TRACK_PACK_NOT_FOUND_ERROR)
		{
			if ($result->isSuccess())
			{
				$this->watchRetriedCall($callId);
			}

			return $result;
		}

		$response = $this->askServiceForFiles($call);
		if (!$response->isSuccess())
		{
			// The service did not answer: the failure the user already sees stands, with its button.
			return $result;
		}

		$taken = $this->takeDeliverableServiceFiles($call, $response->getData()['tracks'] ?? []);
		if ($taken === [])
		{
			// The service holds no file of this call: the failure of the lookup is the whole answer.
			return $result;
		}

		$trackPack = $this->takenTrack($taken[Track::TYPE_TRACK_PACK] ?? null);
		$retryResult = $trackPack ? $this->restartCallAiTask($callId, $trackPack) : $result;

		// Whatever was taken is still in flight - the analysis of a pack, the download of a record,
		// and a pack the service may yet finish making - so the call is watched again either way.
		$this->watchRetriedCall($callId);

		return $retryResult;
	}

	/**
	 * Puts the wait back on a call a restart has just set going - the manual retry above, and the
	 * action the service asks a restart through
	 * {@see \Bitrix\Call\Controller\CallController::restartFollowUpAction()}. Every path that
	 * starts an analysis again arms the wait by this one condition: a chain left unwatched ends
	 * with the user holding neither a result nor a timeout if its callback is lost, and that is
	 * the very failure the wait exists for.
	 *
	 * Armed only when nothing waits for the call already: two agents of one call would each spend
	 * the checks of the other, and a wait that still stands is already carrying the answer the user
	 * is waiting for - shifting it forward {@see self::updateExpectationTime()} would only delay
	 * that answer and hand the new attempt the checks the previous one had spent. A wait armed here
	 * starts its checks over, because the retry is a new attempt; the deadline of the window is
	 * counted from the end of the call {@see self::expectationDeadlineReached()}, so starting them
	 * over cannot stretch the wait past it.
	 *
	 * And only while the call has something left to watch: a retry whose start was refused for good
	 * left nothing in flight, and that refusal has already ended the wait where it happened
	 * {@see self::hasAnythingToWaitFor()}.
	 */
	public function watchRetriedCall(int $callId): void
	{
		if (!$this->hasExpectationAgent($callId) && $this->hasAnythingToWaitFor($callId))
		{
			$this->setupExpectation($callId);
		}
	}

	/**
	 * Brings the AI analysis of the call back for a retry somebody asked for; the expectation
	 * agent never comes here.
	 *
	 * Persists ONLY this flag on purpose: a full $call->save() would write back every other field
	 * of an instance loaded earlier and lose what a concurrent path has changed since.
	 */
	public function restoreAiAnalyze(Call $call): void
	{
		$call->enableAiAnalyze();
		CallTable::update($call->getId(), ['AI_ANALYZE' => true]);

		(new FollowUpAnalytics($call))
			->sendTelemetry(
				source: null,
				status: 'success',
				event: 'ai_analyze_restored_on_resend'
			)
		;
	}

	/**
	 * Whether a task of the call is still waiting for its answer.
	 *
	 * Asked by the end of the analysis {@see self::completeAnalysis()}: an attempt is told from the
	 * next by the newest task of the call, and that id moves on inside an attempt as well - every
	 * task of the chain shifts it. So an attempt made of several tasks would report itself on the
	 * completion of each of them; it speaks once instead, when it has nothing left running.
	 *
	 * $attemptBoundary is the task whose result is being reported, and it keeps the question
	 * inside the attempt that result came from {@see AITask::hasPendingTaskForCall()}. A caller
	 * holding no result of its own passes nothing and asks about the call whole.
	 */
	public function hasPendingTasks(int $callId, int $attemptBoundary = 0): bool
	{
		return AITask::hasPendingTaskForCall($callId, $attemptBoundary);
	}

	/**
	 * Latest AI task id for the call (any sense type) as a claim discriminator that tells
	 * one analysis attempt from the next. Every restart adds a fresh task: a full restart a
	 * new TRANSCRIBE task, a dependent-only restart (transcription already done) new
	 * OVERVIEW/SUMMARY tasks. Keying on the newest id — not the TRANSCRIBE one, which is
	 * reused on a dependent-only restart — makes the discriminator advance on every attempt,
	 * so a new attempt's AI_START wins its own send-guard claim instead of being muted by the
	 * previous attempt's live claim.
	 */
	public function getCurrentAnalysisAttemptId(int $callId): ?string
	{
		$row = CallAITaskTable::getList([
			'select' => ['ID'],
			'filter' => ['=CALL_ID' => $callId],
			'order' => ['ID' => 'DESC'],
			'limit' => 1,
		])?->fetch();

		return $row ? (string)$row['ID'] : null;
	}

	/**
	 * Success AI callback handler.
	 * @see \Bitrix\AI\QueueJob::execute
	 * @event ai:onQueueJobExecute
	 * @param Event $event
	 * @return void
	 */
	public static function onQueueTaskExecute(Event $event): void
	{
		/** @var string $hash */
		$hash = $event->getParameter('queue');

		/** @var \Bitrix\AI\Engine\IEngine $engine */
		$engine = $event->getParameter('engine');
		$context = $engine->getContext();

		$moduleId = $context->getModuleId();
		$contextId = $context->getContextId();
		$parameters = $context->getParameters();

		if (
			empty($moduleId)
			|| $moduleId != 'call'
			|| empty($contextId)
			|| empty($parameters)
			|| empty($parameters['taskId'])
			|| !($task = AITask::loadById($parameters['taskId']))
			|| $contextId != $task->getContextId()
			|| $hash != $task->getHash()
		)
		{
			return;
		}

		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
			$logger->info('AI task has successfully completed. TaskId:' . $task->getId() . ' Hash:' . $hash);
		}

		// check for duplicate event
		if ($task->getStatus() == AITask::STATUS_FINISHED)
		{
			$res = CallOutcomeTable:: query()
				->setSelect(['ID'])
				->where('CALL_ID', $task->getCallId())
				->where('TYPE', $task->getAISenseType())
				->setLimit(1)
				->exec()
			;
			if ($res->fetch())
			{
				if ($log)
				{
					$logger->info('Got duplicate AI event. TaskId:' . $task->getId() . ' Hash:' . $hash);
				}
				return;
			}
		}

		$task
			->setStatus(AITask::STATUS_FINISHED)
			->setDateFinished(new DateTime)
			->save()
		;

		$aiResult = $event->getParameter('result');
		if (!($aiResult instanceof \Bitrix\AI\Result))
		{
			return;
		}

		$outcome = $task->buildOutcome($aiResult);
		if (!$outcome)
		{
			return;
		}

		$outcome->save();
		$outcome->saveProps();

		if ($log)
		{
			$logger->info('AI task outcome. TaskId:' . $task->getId() . ' OutcomeId: ' . $outcome->getId());
			$propsLog = '';
			foreach ($outcome->getProps() as $prop)
			{
				$propsLog .= "\nProperty: {$prop->getCode()}, Content: " . $prop->getContent();
			}
			$logger->info(
				"AI outcome. Type: {$outcome->getType()}"
				. ($outcome->hasLanguageId() ? "\nLanguage: " . $outcome->getLanguageId() : '')
				. ($outcome->hasContent() ? "\nContent: " . $outcome->getContent() : '')
				. ($propsLog ?: '')
			);
		}

		self::getInstance()->processStoredOutcome($outcome, $task);
	}

	/**
	 * What the handler of the AI queue does with a result once the result is stored: the domain
	 * event, the chain the result opens, the launch of that chain, and the end of the attempt.
	 *
	 * A method of its own and not the tail of the handler it is called from, because the ORDER of
	 * these four is the property the one-shot final message rests on and nothing else states it.
	 * The end of the attempt asks whether the chain opened anything past this result
	 * {@see self::completeAnalysis()}, so it has to come after the builder: run ahead of it, it
	 * finds a call with nothing queued and reports the analysis complete over the first result of
	 * a forced run - the new transcription beside the texts of the run before it - and reports it
	 * a second time when the chain it did not see finishes. The handler of the queue cannot be
	 * driven from a test without an engine and a job of the AI module behind it, and an order that
	 * only a test helper reproduces is an order the product is free to lose
	 * {@see \Bitrix\Call\Tests\Unit\Lib\Integration\AI\EventServiceOverviewDuplicateTest}.
	 *
	 * @param Outcome $outcome the stored result of $task
	 * @param AITask $task the task that produced it, already finished
	 */
	public function processStoredOutcome(Outcome $outcome, AITask $task): void
	{
		$logger = CallAISettings::isLoggingEnable() ? Logger::getInstance() : null;

		$event = $this->fireCallOutcomeEvent($outcome);
		if (self::isOutcomeProcessingCancelled($event))
		{
			$logger?->info('Processing AI result has been canceled by event');
			return;
		}

		$call = Registry::getCallWithId($task->getCallId());
		if ($call)
		{
			(new FollowUpAnalytics($call))
				->sendTelemetry(
					source: $task,
					status: 'success',
					event: 'task_outcome'
				);
		}

		$nextTaskResult = $this->buildTasksByOutcome($outcome, $task);
		if (!$nextTaskResult->isSuccess() && !empty($nextTaskResult->getErrors()))
		{
			$logger?->error('Unable process AI outcome. OutcomeId: '.$outcome->getId().' Error: '. implode('; ', $nextTaskResult->getErrorMessages()));
		}

		/** @var AITask[] $tasks */
		$tasks = $nextTaskResult->getData()['tasks'] ?? [];

		$queuedTasks = $this->launchChainTasks($tasks);

		$this->completeAnalysis($outcome, $queuedTasks, $task);
	}

	/**
	 * Launches the tasks the chain opened past a result and answers with the ones that can report
	 * back.
	 *
	 * A launch that never reached the queue leaves a task nothing will ever answer for: a handler
	 * of `call:onCallAiTask` refused it before the engine, the hash of the job never reached the
	 * row {@see self::launchWasNotQueued()}, or the launch failed outright. Such a task is written
	 * off {@see self::writeOffUnlaunchedTask()} and left out of the list the end of the attempt
	 * reads, and both halves of that are needed: the list alone decides this pass
	 * {@see self::completeAnalysis()}, and the row alone decides every later one - the two statuses
	 * the call is read as running by are the one a task is created with and the one a queued launch
	 * turns it into {@see AITask::hasPendingTaskForCall()}. A task left in either of them holds the
	 * final message of the call back for good, and only the agent of the old tasks would ever free
	 * it, sixty days on {@see self::finishTasks()}.
	 *
	 * @param AITask[] $tasks the tasks the chain opened past a result
	 * @return AITask[] the ones the queue took, the only ones a completion callback is coming for
	 */
	public function launchChainTasks(array $tasks): array
	{
		$queued = [];
		foreach ($tasks as $task)
		{
			$launchResult = $this->launchTask($task);
			if ($launchResult->isSuccess() && !self::launchWasNotQueued($launchResult))
			{
				$queued[] = $task;
			}
			else
			{
				$this->writeOffUnlaunchedTask($task, $launchResult);
			}

			usleep(100);
		}

		return $queued;
	}

	/**
	 * Ends a task whose launch left nothing that can report back, so that the call is not read as
	 * running by a row waiting for an answer that is not coming.
	 *
	 * Asked by every place that launches a task of an attempt and walks on: the entry point of the
	 * chain {@see self::processTrack()}, the chain opened past a result
	 * {@see self::launchChainTasks()} and the relaunch of the dependent types the operator restart
	 * runs {@see self::relaunchDependentTasks()}.
	 *
	 * The status is the one the ordinary path of a failure selects by, and the type is picked up
	 * from it like any other that did not run {@see self::restartCallAiTask()}. The code of the
	 * error is the one the launch named where it named one, and otherwise the code of a launch
	 * that did not start - a veto has no error of its own.
	 *
	 * The failure event is not fired here: a veto is silence for the automatic pipeline and has
	 * always been {@see self::launchTask()}, and a launch that failed has fired the event there
	 * already, together with the telemetry of the failure.
	 */
	private function writeOffUnlaunchedTask(AITask $task, Result $launchResult): void
	{
		// The row is ended as the storage holds it and not as the object that tried to launch it
		// sees it: a save the storage refused leaves the values it carried on the object, and the
		// write below would take them along - the hash of the queue among them. A task written off
		// with the hash of a job on it is matched by the callback of that job like any other
		// {@see self::onQueueTaskExecute()}, which is the one thing a write-off says is not coming.
		$storedTask = AITask::loadById((int)$task->getId());
		if ($storedTask === null || $storedTask->isFailed())
		{
			// Nothing left to end: the row is gone, or a launch the engine refused has ended the
			// task itself, with the answer of the engine on it {@see self::launchTask()}.
			return;
		}

		$error = $launchResult->getError();
		$errorCode = (string)($error?->getCode() ?? '');

		$storedTask
			->setStatus(AITask::STATUS_FAILED)
			->setDateFinished(new DateTime)
			->setErrorCode($errorCode !== '' ? $errorCode : CallAIError::AI_TASK_START_FAIL)
			->setErrorMessage($error?->getMessage() ?: 'The launch of the task never reached the queue')
			->save()
		;
	}

	/**
	 * The end of an attempt, told from its middle: the analysis reports itself in the chat once,
	 * when the chain opened nothing past the result at hand and no task of the call is still
	 * waiting for its answer.
	 *
	 * Asked here, at the end of {@see self::onQueueTaskExecute()}, and not from the result event,
	 * which is fired before the chain is built: on the completion of the transcription of a forced
	 * run the chain is not queued yet, so the call has nothing running and the attempt would
	 * report itself over its own first result - out of two generations at once. Asking the graph
	 * of the chain instead of the builder does not answer it either: the graph says a
	 * transcription opens the overview and the summary even where both are contentful already and
	 * no task is created, and the analysis would then never report itself at all. Only the builder
	 * knows, by the tasks it created.
	 *
	 * Only the tasks the queue took are counted: one it refused is answered by nobody, so an
	 * attempt that waited for it would wait for ever {@see self::launchChainTasks()}.
	 *
	 * $sourceTask is the task this result came from. It bounds the question about what is still
	 * running {@see AITask::hasPendingTaskForCall()}, and it is the marker the message carries:
	 * read at the moment of the send instead, the marker is the newest task of the call as it
	 * stands by then, and a restart pressed between the question and the send would put the
	 * results of THIS attempt into the chat under the number of the next one - after which the
	 * message of that next attempt, finding its own number already in the chat, would never go out
	 * at all {@see EventService::sendAnalysisResultMessage()}.
	 *
	 * The marker is not what the send is guarded by, and the two are told apart here. The leaves
	 * of a chain are opened in pairs and finish side by side, so two handlers of ONE attempt reach
	 * the send holding tasks of their own; a guard keyed on the task of the handler gives each of
	 * them a key nobody else takes, and the claim that exists to serialize them lets both through -
	 * two final messages for one attempt, and the one-shot effects behind the claim done twice.
	 * What both handlers do share is the attempt, which is the newest task of the call
	 * {@see self::getCurrentAnalysisAttemptId()}: equal for every handler of one attempt, and
	 * moved on by every restart, since each of them adds a task. So that is the key, read one step
	 * ahead of the question about what is still running - where the window of the restart cannot
	 * reach it either, which is what a caller holding no source of its own has always used.
	 *
	 * @param AITask[] $chainTasks the tasks the chain opened past this result and got queued
	 * @param AITask|null $sourceTask the task the processed result came from
	 */
	public function completeAnalysis(Outcome $outcome, array $chainTasks = [], ?AITask $sourceTask = null): void
	{
		$callId = $outcome->getCallId();
		// The key of the attempt, read here and not at the send: taken one step earlier than the
		// question about what is still running, so a restart pressed in that window cannot move it
		// onto the number of the next attempt.
		$attemptKey = $this->getCurrentAnalysisAttemptId($callId);
		$attemptId = $sourceTask === null ? $attemptKey : (string)$sourceTask->getId();

		if (!empty($chainTasks) || $this->hasPendingTasks($callId, (int)$sourceTask?->getId()))
		{
			return;
		}

		EventService::sendAnalysisResultMessage($outcome, $attemptId, $attemptKey);
	}

	/**
	 * A subscriber of the result event stopped the processing of that result: the chain is not
	 * built past it and the analysis says nothing in the chat.
	 *
	 * @see self::fireCallOutcomeEvent()
	 */
	public static function isOutcomeProcessingCancelled(Event $event): bool
	{
		$eventResult = $event->getResults()[0] ?? null;

		return $eventResult instanceof EventResult && $eventResult->getType() == EventResult::ERROR;
	}


	/**
	 * Callback handler AI job has been failed.
	 * @see \Bitrix\AI\QueueJob::clearOldAgent
	 * @see \Bitrix\AI\QueueJob::fail
	 * @event ai:onQueueJobFail
	 * @return void
	 */
	public static function onQueueTaskFail(Event $event): void
	{
		/** @var string $hash */
		$hash = $event->getParameter('queue');

		/** @var \Bitrix\AI\Engine\IEngine $engine */
		$engine = $event->getParameter('engine');
		$context = $engine->getContext();

		$moduleId = $context->getModuleId();
		$contextId = $context->getContextId();
		$parameters = $context->getParameters();

		if (
			empty($moduleId)
			|| $moduleId != 'call'
			|| empty($contextId)
			|| empty($parameters)
			|| empty($parameters['taskId'])
			|| !($task = AITask::loadById($parameters['taskId']))
			|| $contextId != $task->getContextId()
			|| $hash != $task->getHash()
		)
		{
			return;
		}

		$processingError = $event->getParameter('error');
		$error = CallAIError::constructTaskError(CallAIError::AI_TASK_FAILED, $processingError, $task);
		$error->allowRecover();

		$task
			->setStatus(AITask::STATUS_FAILED)
			->setDateFinished(new DateTime)
			->setErrorMessage($error->getMessage(). ($error->getDescription() ? '; '.$error->getDescription() : ''))
			->setErrorCode($error->getCode())
			->save()
		;

		if (CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
			$logger->info(
				'AI task has failed.'
				. ' TaskId:' . $task->getId()
				. ' Hash: ' . $hash
				. ' Code: ' . $error->getCode()
				. ' Error: ' . $error->getMessage()
				. ($error->getDescription() ? ' Desc: '.$error->getDescription() : '')
			);
		}

		$call = Registry::getCallWithId($task->getCallId());
		(new FollowUpAnalytics($call))
			->addAITaskFailed($task, $error->getCode() ?? '')
			->sendTelemetry(
					source: $task,
					status: 'failed',
					errorCode: $error->getCode(),
					event: 'task_failed',
					error: $error
				)
		;

		$service = self::getInstance();
		$service->fireCallAiFailedEvent($task, $error);
	}

	/**
	 * Check service AI unavailability and restrictions.
	 * @return Result
	 */
	public function checkRestriction(Engine $engine): Result
	{
		$checkResult = new Result;
		if (!$engine->isAvailableByTariff())
		{
			$error = new CallAIError(CallAIError::AI_UNAVAILABLE_ERROR);// AI service unavailable by tariff
			$error->allowRecover();

			$checkResult->addError($error);
		}
		elseif (!$engine->isAvailableByAgreement())
		{
			$error = new CallAIError(CallAIError::AI_AGREEMENT_ERROR);// AI service agreement must be accepted
			$error->allowRecover();

			$checkResult->addError($error);
		}

		return $checkResult;
	}

	/**
	 * @event call:onCallAiOutcome
	 * @param Outcome $outcome
	 * @return Event
	 */
	public function fireCallOutcomeEvent(Outcome $outcome): Event
	{
		$event = new Event('call', 'onCallAiOutcome', ['outcome' => $outcome]);
		$event->send();

		return $event;
	}

	/**
	 * @event call:onCallAiFailed
	 * @param AITask $task
	 * @param Error|null $processingError
	 * @return Event
	 */
	public function fireCallAiFailedEvent(AITask $task, ?Error $processingError): Event
	{
		$event = new Event('call', 'onCallAiFailed', ['task' => $task, 'error' => $processingError]);
		$event->send();

		return $event;
	}

	/**
	 * @event call:onCallAiTask
	 * @param AITask $task
	 * @param IPayload $payload
	 * @param Context $context
	 * @param Engine|null $engine
	 * @return Event
	 */
	public function fireCallAiTaskEvent(AITask $task, IPayload $payload, Context $context, ?Engine $engine): Event
	{
		$event = new Event(
			'call',
			'onCallAiTask',
			[
				'task' => $task,
				'payload' => $payload,
				'context' => $context,
				'engine' => $engine,
			]
		);
		$event->send();

		return $event;
	}

	public static function finishTasks(): string
	{
		$service = self::getInstance();

		$depthDays = self::FINISH_TASK_DEPTH_DAYS;
		$taskList = CallAITaskTable::getList([
			'filter' => [
				'<DATE_CREATE' => (new DateTime())->add("-{$depthDays} days")
			]
		]);
		while ($row = $taskList->fetchObject())
		{
			$task = AITask::buildBySource($row);
			$service->finishTask($task);
		}

		return __METHOD__. "();";
	}

	//region Expectation

	/**
	 * The one place an expectation agent name is built, so the name a call is scheduled, found and
	 * cancelled by cannot drift {@see FollowUpAgent::run}.
	 *
	 * Both arguments are always passed: the comma the lookup pattern cuts at exists only because
	 * the argument list holds more than the call id.
	 *
	 * The entry point is the short one and not this class: only 100 characters of b_agent.NAME are
	 * indexed {@see self::getExpectationAgentNamePattern()}.
	 */
	private function getExpectationAgentCallback(int $callId, int $repeat): Callback
	{
		return new Callback([FollowUpAgent::class, 'run'], [$callId, $repeat]);
	}

	/**
	 * LIKE pattern of every expectation agent of the call, whatever repeat counter it carries.
	 *
	 * `ix_agent_name` indexes the first 100 characters of NAME, so the call id has to fall inside
	 * them: with it outside, the first 100 characters are the same for every call and MySQL builds
	 * the same range for all of them - every live expectation of the portal, plus its JOIN on
	 * b_user, read on every lookup. The short entry point of the agent puts the call id at
	 * character 91 {@see FollowUpAgent}.
	 */
	private function getExpectationAgentNamePattern(int $callId): string
	{
		return $this->cutNamePatternAfterCallId(
			$this->getExpectationAgentCallback($callId, self::EXPECTATION_FIRST_REPEAT)
		);
	}

	/**
	 * The one cut of an envelope into a lookup pattern: everything up to and including the comma
	 * after the call id, so the separator that keeps call 1 from matching 10 and 123 comes from the
	 * builder of the name and not from a literal here. Relies on 'arguments' being the last key of
	 * the envelope and on the passed envelope carrying EXPECTATION_FIRST_REPEAT. Backslashes of the
	 * FQCN are not escaped: {@see \CAgent::GetList} runs NAME through ForSQLLike.
	 */
	private function cutNamePatternAfterCallId(Callback $envelope): string
	{
		$repeatTail = self::EXPECTATION_FIRST_REPEAT . ']}';

		return substr($envelope->toJson(), 0, -strlen($repeatTail)) . '%';
	}

	/**
	 * Expectation agents of the call by name, each with the number of the check it is about to run.
	 * Looked up by the call, because a rescheduled agent carries a counter unknown to the lookup.
	 *
	 * One form of the name is looked for, the only one the module writes: the envelope of the short
	 * entry point, whose call id falls inside the indexed prefix of NAME. Rows of the forms earlier
	 * versions wrote are not looked for at all, and they reach this lookup two ways instead: the
	 * migration of the update converts them {@see self::migrateLegacyExpectationAgents()}, and until
	 * it gets to one, that row runs itself and the core rewrites its name into this form on that
	 * first run {@see self::expectCallAiTask()}. What the gap between the two costs is spelled out
	 * where the wait is armed {@see self::setupExpectation()}.
	 *
	 * @return array<string, int>
	 */
	private function getExpectationAgents(int $callId): array
	{
		$agents = [];

		$rows = \CAgent::GetList([], [
			'MODULE_ID' => 'call',
			'NAME' => $this->getExpectationAgentNamePattern($callId),
		]);
		while ($row = $rows->Fetch())
		{
			$agentName = (string)$row['NAME'];
			$repeat = $this->getExpectationRepeat($agentName, $callId);
			if ($repeat !== null)
			{
				$agents[$agentName] = $repeat;
			}
		}

		return $agents;
	}

	/**
	 * Number of the check the scheduled agent is going to run, or null when the name is not an
	 * expectation agent of this call.
	 *
	 * With one form of the name looked for, the pattern already answers both of those: an envelope
	 * that matches it carries this entry point and this call id. The checks are kept as the guard
	 * against a drift between the builder of the pattern and the reading of the name - the envelope
	 * has to be decoded here anyway to learn the counter - and not as a live filter, so no test of
	 * the suite can tell them from a constant `true`.
	 */
	private function getExpectationRepeat(string $agentName, int $callId): ?int
	{
		$callback = Callback::tryFromJson($agentName);
		if (!$callback)
		{
			return null;
		}

		if (
			!$this->isExpectationEntryPoint($callback->getCallback())
			|| ($callback->getArguments()[0] ?? null) !== $callId
		)
		{
			return null;
		}

		$repeat = $callback->getArguments()[1] ?? null;

		return is_int($repeat)
			? max($repeat, self::EXPECTATION_FIRST_REPEAT)
			: self::EXPECTATION_FIRST_REPEAT
		;
	}

	/**
	 * Whether the envelope points at the wait at all: at the short entry point every name of it is
	 * written with {@see FollowUpAgent::run()}.
	 */
	private function isExpectationEntryPoint(string|array $callback): bool
	{
		return $callback === [FollowUpAgent::class, 'run'];
	}

	private function addExpectationAgent(int $callId, int $repeat): void
	{
		$interval = CallAISettings::getFollowUpExpectationInterval();

		/** @see FollowUpAgent::run */
		\CAgent::AddAgent(
			$this->getExpectationAgentCallback($callId, $repeat),
			'call',
			'N',
			$interval,
			'',
			'Y',
			\ConvertTimeStamp(time() + \CTimeZone::GetOffset() + $interval, 'FULL')
		);
	}

	/**
	 * Adds agent to checkup ai tasks.
	 * The lookup of the agent knows one form of the name, so a call whose agent still carries an
	 * older form is invisible to it: this arms a second agent next to the first, and the two spend
	 * each other's checks. That lasts until the migration of the update converts the row
	 * {@see self::migrateLegacyExpectationAgents()}, and no longer than the window of the wait
	 * either way. The alternative - teaching the lookup the older forms - costs a second query on
	 * every intake of a track, for good, to serve a population that empties itself.
	 *
	 * What an older row heals on its own first run is its name, and only that: the core rewrites
	 * NAME with what the run returned and computes the next run from the AGENT_INTERVAL already
	 * stored on the row {@see \CAgent::ExecuteAgents()}. A row written by a version whose interval
	 * was six hours would therefore keep checking every six hours under the new name, inside a
	 * window of twelve counted from the end of the call {@see self::expectationDeadlineReached()} -
	 * two checks where this release means twelve. That is the half no run heals, and it is what the
	 * migration exists for: it writes the interval of this release onto the row
	 * {@see CallAISettings::getFollowUpExpectationInterval()}.
	 *
	 * What a user deleted is not exposed by any of this, and that is not left to the cancelling of
	 * an agent: a destroyed recording and a deleted follow-up both leave the mark of the deletion,
	 * and the check reads it whatever name its row carries {@see Call::isRecordDestroyed()}.
	 *
	 * @param int $callId
	 * @return void
	 */
	public function setupExpectation(int $callId): void
	{
		$this->addExpectationAgent($callId, self::EXPECTATION_FIRST_REPEAT);
	}

	/**
	 * Removes agents expecting for ai tasks. The repeat counter of a rescheduled agent is unknown
	 * here, so every agent of the call is cancelled by its own name instead of one guessed name.
	 * @param int $callId
	 * @return void
	 */
	public function removeExpectation(int $callId): void
	{
		foreach (array_keys($this->getExpectationAgents($callId)) as $agentName)
		{
			\CAgent::RemoveAgent($agentName, 'call');
		}
	}

	/**
	 * Moves the next check of the call further away, leaving it one agent and keeping the checks
	 * already spent: a shift is not an attempt and does not reset the limit of attempts.
	 *
	 * A shift is not an arming point either. Both callers - a file of the call has arrived
	 * {@see \Bitrix\Call\Controller\CallController::trackReadyAction()}, the mixer reports progress
	 * {@see \Bitrix\Call\Controller\Mixer::processingStatusAction()} - mean "do not give up yet",
	 * which says nothing about a call nobody waits for. It does arm the wait of a call that still
	 * has something to watch, because a file may well arrive for a call whose wait has ended: a pack
	 * resent by the service, or one the media backend delivers after the window closed, starts an
	 * analysis that would otherwise run unwatched {@see self::hasAnythingToWaitFor()}.
	 * @param int $callId
	 * @return void
	 */
	public function updateExpectationTime(int $callId): void
	{
		$agents = $this->getExpectationAgents($callId);
		if ($agents === [] && !$this->hasAnythingToWaitFor($callId))
		{
			return;
		}

		$repeat = self::EXPECTATION_FIRST_REPEAT;

		foreach ($agents as $agentName => $agentRepeat)
		{
			$repeat = max($repeat, $agentRepeat);
			\CAgent::RemoveAgent($agentName, 'call');
		}

		$this->addExpectationAgent($callId, $repeat);
	}

	/**
	 * Whether a wait armed for the call right now would have anything to watch: a step of the
	 * analysis that has not answered yet, or a pack the portal does not hold at all - the file the
	 * wait goes and asks the service for {@see self::takeTracksFromService()}.
	 *
	 * A pack the portal holds with no task on it is neither. There the start of the chain was
	 * refused before it created anything and the refusal was reported at once
	 * {@see self::processTrack()}, so the only thing a wait could add is a report about a step that
	 * was never launched. Asked by every point that may arm a wait for a call that already lived
	 * through an attempt, and not at the initial arming, where a call has neither by definition
	 * {@see \Bitrix\Call\Integration\AI\EventService::onCallFinished()}.
	 *
	 * A task counts while it has not answered - queued, or saved and not queued yet - and stops
	 * counting once it is finished or written off.
	 */
	private function hasAnythingToWaitFor(int $callId): bool
	{
		$unanswered = CallAITaskTable::query()
			->setSelect(['ID'])
			->where('CALL_ID', $callId)
			->whereIn('STATUS', [AITask::STATUS_READY, AITask::STATUS_PENDING])
			->setLimit(1)
			->exec()
		;
		if ($unanswered->getSelectedRowsCount() > 0)
		{
			return true;
		}

		return Track::getTrackForCall($callId, Track::TYPE_TRACK_PACK) === null;
	}

	/**
	 * Checks if expectation agent exists for specific call.
	 * @param int $callId
	 * @return bool
	 */
	public function hasExpectationAgent(int $callId): bool
	{
		return $this->getExpectationAgents($callId) !== [];
	}

	/**
	 * Brings the waits armed before this version into the current form of the name, at most $limit
	 * rows per call, and answers how many rows it touched - zero once none are left
	 * {@see \Bitrix\Call\Update\FollowUpAgentNameMigration}.
	 *
	 * Two things are wrong with such a row and only one of them heals itself. The name is rewritten
	 * by the core on the first run of the row {@see self::setupExpectation()}; the interval is not -
	 * the core computes the next run from the AGENT_INTERVAL already stored on the row, so a wait
	 * armed by the version whose interval was six hours keeps checking every six hours inside a
	 * window of twelve, and a call this release exists to rescue would get two checks where the
	 * release means twelve {@see CallAISettings::getFollowUpExpectationInterval()}.
	 *
	 * The rows are read by the prefix of the legacy form, and that is the whole difference from
	 * doing the same at runtime. Both forms keep the call id inside the indexed prefix of NAME, so
	 * a lookup of one call could be written for either; what it would cost is a second query on
	 * every intake of a track, for good, to serve a population that empties itself within one
	 * interval of the wait. A migration wants every such row at once, so the shared prefix is
	 * exactly the range it should read - once.
	 *
	 * The counter of spent checks is carried over as it stands. A wait standing since long before
	 * the deadline this release introduced therefore ends at its first check, with the recoverable
	 * failure and its retry button - which is what happens to such a row without any migration too,
	 * because the counter is part of the name either way {@see self::expectationWindowClosed()}.
	 *
	 * NEXT_EXEC is left alone: when the row runs is not this migration's to decide, and pulling the
	 * checks of every migrated call to one moment would ask the service about all of them at once.
	 *
	 * A call that already has an agent of the current form loses its legacy row instead of keeping
	 * both: the wait is already represented, and the second row would only spend the checks of the
	 * first {@see self::updateExpectationTime()}.
	 *
	 * @internal
	 * @return int rows touched by this call, 0 when no readable row of the legacy form is left
	 */
	public function migrateLegacyExpectationAgents(int $limit = 200): int
	{
		$touched = 0;

		$rows = \CAgent::GetList([], [
			'MODULE_ID' => 'call',
			'NAME' => self::LEGACY_EXPECTATION_AGENT_PREFIX . '%',
		]);
		while ($touched < $limit && ($row = $rows->Fetch()))
		{
			$legacy = $this->readLegacyExpectationAgentName((string)$row['NAME']);
			if ($legacy === null)
			{
				continue;
			}

			[$callId, $repeat] = $legacy;
			$agentId = (int)$row['ID'];

			$this->hasExpectationAgent($callId)
				? \CAgent::Delete($agentId)
				: \CAgent::Update($agentId, [
					'NAME' => $this->getExpectationAgentCallback($callId, $repeat),
					'AGENT_INTERVAL' => CallAISettings::getFollowUpExpectationInterval(),
				])
			;

			$touched++;
		}

		return $touched;
	}

	/**
	 * The call and the number of the check a name of the legacy form carries, or null when the name
	 * is not one of them: only the two shapes that version wrote are read - the arming, which
	 * passed the call alone, and every check after it, which passed the counter as well. A row this
	 * does not recognise is left exactly as it is; it still runs itself and still heals its own
	 * name on that run.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	private function readLegacyExpectationAgentName(string $agentName): ?array
	{
		$pattern = '/^' . preg_quote(self::LEGACY_EXPECTATION_AGENT_PREFIX, '/') . '(\\d+)(?:\\s*,\\s*(\\d+))?\\);$/';
		if (!preg_match($pattern, $agentName, $matches))
		{
			return null;
		}

		$callId = (int)$matches[1];

		return $callId > 0
			? [$callId, max((int)($matches[2] ?? 0), self::EXPECTATION_FIRST_REPEAT)]
			: null
		;
	}

	/**
	 * One check of the wait. Reached through {@see FollowUpAgent::run()}, and directly by the rows
	 * earlier versions scheduled - which is why it stays public: the wait of a call armed before the
	 * deploy runs from here, and the short envelope it returns is what the core writes back into the
	 * row. That rewrite is one of the two ways such a row comes into view of the lookup, the other
	 * being the migration of the update {@see self::migrateLegacyExpectationAgents()}.
	 *
	 * @param int $callId
	 * @param int $repeat
	 * @return Callback|null Next check of the call, or null when there is nothing left to wait for.
	 */
	public static function expectCallAiTask(int $callId, int $repeat = 1): ?Callback
	{
		Loader::includeModule('im');

		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			return null;
		}

		$service = self::getInstance();
		$notifyService = NotifyService::getInstance();

		if ($call->isRecordDestroyed())
		{
			// The wait of a call whose recording a user destroyed is over, and over silently: there
			// is nothing left to deliver and no failure to report {@see Call::isRecordDestroyed()}.
			// The destroy ends the wait itself {@see \Bitrix\Call\Controller\Track::destroyAction()};
			// this is for the wait a manual retry armed again before the deletion
			// {@see self::watchRetriedCall()}, and for the rows of a check already in flight.
			$service->removeExpectation($callId);

			return null;
		}

		if (!$call->isAiAnalyzeEnabled())
		{
			return $service->closeExpectationWithoutAnalysis($call, $repeat);
		}

		$result = $service->checkCallAiTask($callId, $repeat);

		if ($result->isSuccess() && ($result->getData()['restart'] ?? null) === true)
		{
			// Relaunch the chain to regenerate semantically empty outcomes
			$restartResult = $service->restartCallAiTask($callId);
			if (!$restartResult->isSuccess())
			{
				$result = $restartResult->setData(['repeat' => false]);
			}
		}
		elseif ($result->isSuccess() && ($result->getData()['track_pack_missing'] ?? null) === true)
		{
			// The portal holds no pack of this call: ask the service instead of waiting for one.
			$result = $service->takeTracksFromService($call);
		}

		// After the dispatch on purpose: the service is asked before the clock is read, so a late
		// file is still taken instead of being reported as lost.
		$checkData = $result->getData();
		if (
			$result->isSuccess()
			&& ($checkData['repeat'] ?? false) === true
			&& $service->expectationWindowClosed($call, $repeat, $checkData)
		)
		{
			$result = $service->giveUpOnExpectation($call, $checkData);
		}

		if (!$result->isSuccess())
		{
			$notifyService->sendTaskFailedMessage($result->getError(), $call);

			(new FollowUpAnalytics($call))
				->sendTelemetry(
					source: null,
					status: 'error',
					errorCode: $result->getError()?->getCode(),
					event: 'follow_up_error',
					error: $result->getError()
				)
			;
		}
		/*
		elseif ($result->getData()['wait_more'] === true)
		{
			$notifyService->sendTaskWaitMessage($call);
		}
		*/

		if (($result->getData()['repeat'] ?? false) === true)
		{
			// A check the service did not answer is not a check spent: a silent service is bounded
			// by the deadline, not by attempts.
			$nextRepeat = ($result->getData()['keep_attempt'] ?? false) === true ? $repeat : $repeat + 1;

			return $service->getExpectationAgentCallback($callId, $nextRepeat); // wait more
		}

		return null;
	}

	/**
	 * The check of a call the portal holds no pack of: the service is asked what files it keeps and
	 * the deliverable ones are taken.
	 *
	 * An unanswered request is not a verdict, so the wait goes on and the check is not spent. The
	 * verdict that every pack is lost ends the wait regardless of the portal window
	 * {@see self::expectationWindowClosed()}.
	 *
	 * A start of the chain the intake refused ends the wait where it was refused, and without a
	 * word: the refusal is already in the chat, put there by the path that refused it, and the
	 * check that would stand next reads only the state it left behind
	 * {@see self::takeFile()}.
	 *
	 * @return Result data: ['repeat' => bool, 'keep_attempt' => bool, 'pack_taken' => bool,
	 *     'no_pack_at_service' => bool]
	 */
	private function takeTracksFromService(Call $call): Result
	{
		$result = new Result();
		$result->setData(['repeat' => false]);

		$response = $this->askServiceForFiles($call);
		if (!$response->isSuccess())
		{
			return $result->setData(['repeat' => true, 'keep_attempt' => true]);
		}

		$files = $response->getData()['tracks'] ?? [];

		$taken = $this->takeDeliverableServiceFiles($call, $files);

		if ($this->everyServiceFileLost($files, Track::TYPE_TRACK_PACK))
		{
			return $result->addError($this->missingTrackPackError(CallAIError::TRACK_PACK_NOT_FOUND_TIMEOUT));
		}

		$packIntake = $taken[Track::TYPE_TRACK_PACK] ?? null;
		if ($packIntake && !$packIntake->isSuccess() && !$this->hasAnythingToWaitFor((int)$call->getId()))
		{
			// The start of the chain was refused and left nothing running: the wait ends here, and
			// silently. The refusal has already been told to the user by the path that refused it
			// {@see TrackService::processAiTrack()}, and a check left standing would find the pack
			// with no task on it and answer with a recoverable "transcription failed" - a second,
			// vaguer message about that same refusal, offering a button that leads back into it.
			return $result;
		}

		return $result->setData([
			'repeat' => true,
			'pack_taken' => $packIntake !== null
				&& ($packIntake->isSuccess() || $this->takenTrack($packIntake) !== null),
			'no_pack_at_service' => $this->serviceFilesOfType($files, Track::TYPE_TRACK_PACK) === [],
		]);
	}

	/**
	 * The last step of a call whose analysis is off for good: the audio record is rescued and the
	 * wait ends. This is an exit, not another cycle - the analysis stays off - and the one thing
	 * that postpones the exit is a service that said nothing at all.
	 *
	 * A silent service is not a verdict about the file: the answer of the only question this branch
	 * asks would otherwise decide the fate of the record on a single request, and a record is
	 * exactly what the branch exists to rescue. So the check is scheduled again with the same
	 * number - an unanswered check is not a check spent {@see self::expectCallAiTask()} - and only
	 * the absolute deadline of the window, counted from the end of the call, ends the wait then
	 * {@see self::expectationDeadlineReached()}. Silence includes the read this process refused to
	 * make {@see ControllerClient::getTracksRequestRefusal()}: on a mass run the rescue must not
	 * end on the ceiling of the portal itself.
	 *
	 * The other thing that postpones the exit is a rescue that failed on the side of the portal:
	 * the lock of the intake, the row of the file or its download. That is not a verdict about the
	 * file either, and the branch exists to deliver it, so the wait goes back on until the same
	 * deadline. It goes back on as a new agent rather than as the returned check: the row this
	 * check runs from has just been removed, and the core writes the returned check into that very
	 * row {@see \CAgent::ExecuteAgents()} - it would have nothing to write into.
	 *
	 * A record the portal already holds is announced by the end of the wait itself. While the wait
	 * stood, the arrival of that file said nothing - the result of the analysis was going to speak
	 * instead {@see TrackService::checkAiTasksExecution()} - and by the time this branch runs the
	 * analysis is off for good, so nothing else is left to say it. Only the exit that takes no file
	 * announces: a file taken here speaks through its own download, and speaking ahead of that
	 * download would take the barrier of the sender inside this very process.
	 *
	 * @return Callback|null Next check of the call, or null once the wait is over.
	 */
	private function closeExpectationWithoutAnalysis(Call $call, int $repeat): ?Callback
	{
		$callId = (int)$call->getId();
		$audio = $this->tryTakeAudio($call);

		if (!$audio->isSuccess() && !$this->expectationDeadlineReached($call))
		{
			return $this->getExpectationAgentCallback($callId, $repeat);
		}

		// Ahead of the intake: the fallback notice about an audio record stays muted while an
		// expectation agent stands for the call {@see TrackService::checkAiTasksExecution()}.
		$this->removeExpectation($callId);

		$file = $audio->getData()['file'] ?? null;
		if (is_array($file))
		{
			// The analysis is not coming back: the file is delivered, the chain is not relaunched.
			$intake = $this->takeFile($call, $file, restoreAiChain: false);
			if (!$intake->isSuccess() && !$this->expectationDeadlineReached($call))
			{
				// The number of the check is kept: a rescue the portal itself could not carry out
				// is not a check spent, exactly as an unanswered service is not.
				$this->addExpectationAgent($callId, $repeat);
			}
		}
		else
		{
			// Nothing is on its way any more, so a record the portal already holds is announced
			// here or never. Asked without a condition of its own: whether there is a record to
			// announce, and whether it has been announced already, is the question of the sender
			// {@see NotifyService::sendAudioRecordMessage()}.
			NotifyService::getInstance()->sendAudioRecordMessage($call);
		}

		return null;
	}

	/**
	 * The audio record of the call as the service keeps it.
	 *
	 * A service that answered gives a successful result, whether or not there is a file in it
	 * (data: ['file' => array|null]); a service that said nothing gives its error. The two are told
	 * apart because only one of them says anything about the file
	 * {@see self::closeExpectationWithoutAnalysis()}.
	 *
	 * @return Result data of an answered service: ['file' => the file to take, or null].
	 */
	private function tryTakeAudio(Call $call): Result
	{
		$response = $this->askServiceForFiles($call);
		if (!$response->isSuccess())
		{
			return (new Result())->addErrors($response->getErrors());
		}

		$audio = $this->newestDeliverableServiceFile($response->getData()['tracks'] ?? [], Track::TYPE_RECORD);
		$taken = $audio !== null && !$this->audioRecordOnItsWay((int)$call->getId());

		$this->reportFilesTaken($call, $taken);

		return (new Result())->setData(['file' => $taken ? $audio : null]);
	}

	/**
	 * Asks the controller service what files it keeps for the call and reports the question, and an
	 * unanswered one, to telemetry.
	 *
	 * The budget of the process is asked first, before anything is reported: a check the client
	 * refuses to make announces nothing at all. It never asked, so it did not request a track list;
	 * and the service said nothing because nobody spoke to it, so it is not unavailable either.
	 * Reporting either would make both counters lie about an incident they exist to explain - and
	 * the ceiling that stopped the read would keep paying background sends for every call behind
	 * it. For the caller a refused read stays exactly what an unanswered one is
	 * {@see ControllerClient::getTracksRequestRefusal()}.
	 *
	 * @return Result data of a successful answer: ['tracks' => list of file states].
	 */
	private function askServiceForFiles(Call $call): Result
	{
		if ($call->isRecordDestroyed())
		{
			// Ahead of the request on purpose: the service of the modern scheme was never told
			// about the deletion, so it would answer with the very file the user removed
			// {@see Call::isRecordDestroyed()}. The intake refuses that file anyway
			// {@see TrackService::intakeServiceFile()} - this saves the request and keeps the
			// telemetry of the reads free of calls the portal has no business asking about.
			return (new Result())->addError(
				new \Bitrix\Call\Error(self::ERROR_RECORD_DESTROYED, 'The record of the call was destroyed')
			);
		}

		$refusal = ControllerClient::getTracksRequestRefusal();
		if ($refusal)
		{
			return (new Result())->addError($refusal);
		}

		$analytics = new FollowUpAnalytics($call);
		$analytics->sendTelemetry(
			source: null,
			status: 'success',
			event: FollowUpAnalytics::EVENT_TRACKS_REQUESTED
		);

		$response = $this->getServiceTracks((string)$call->getUuid());
		if (!$response->isSuccess())
		{
			$analytics->sendTelemetry(
				source: null,
				status: 'error',
				errorCode: $response->getError()?->getCode(),
				event: FollowUpAnalytics::EVENT_TRACKS_SERVICE_UNAVAILABLE,
				error: $response->getError()
			);
		}

		return $response;
	}

	/**
	 * Tells telemetry whether the answer of the service led to an intake of a file. The call object
	 * is required: analytics leaves silently without one
	 * {@see \Bitrix\Call\Analytics\AbstractAnalytics::async()}.
	 */
	private function reportFilesTaken(Call $call, bool $taken): void
	{
		(new FollowUpAnalytics($call))->sendTelemetry(
			source: null,
			status: 'success',
			event: $taken ? FollowUpAnalytics::EVENT_TRACKS_RECEIVED : FollowUpAnalytics::EVENT_TRACKS_ABSENT
		);
	}

	/**
	 * Takes every file of the answer of the service the portal may still take, and tells telemetry
	 * whether anything was taken at all.
	 *
	 * @param array<int, mixed> $files Files of the call as the service reports them.
	 * @return array<string, Result> The intake of every file that was handed over, keyed by type of
	 *     the file: data ['track' => Track] once the row of the file exists, and the errors of a
	 *     start the intake refused {@see self::takeFile()}.
	 */
	private function takeDeliverableServiceFiles(Call $call, array $files): array
	{
		$filesToTake = [];

		$trackPack = $this->newestDeliverableServiceFile($files, Track::TYPE_TRACK_PACK);
		if ($trackPack)
		{
			$filesToTake[Track::TYPE_TRACK_PACK] = $trackPack;
		}

		// A record already on its way to the chat is not taken twice.
		$audio = $this->newestDeliverableServiceFile($files, Track::TYPE_RECORD);
		if ($audio && !$this->audioRecordOnItsWay((int)$call->getId()))
		{
			$filesToTake[Track::TYPE_RECORD] = $audio;
		}

		$this->reportFilesTaken($call, $filesToTake !== []);

		$intakes = [];
		foreach ($filesToTake as $type => $file)
		{
			$intakes[$type] = $this->takeFile($call, $file);
		}

		return $intakes;
	}

	/**
	 * The row one intake of a file landed on, or null when the file never got one.
	 */
	private function takenTrack(?Result $intake): ?Track
	{
		$track = $intake?->getData()['track'] ?? null;

		return $track instanceof Track ? $track : null;
	}

	/**
	 * Hands one file of the service to the intake of the track pipeline, which finds or creates its
	 * row and routes it by its type {@see TrackService::intakeServiceFile()}.
	 *
	 * The intake is handed back whole, row and refusal together, because the caller has to tell a
	 * file that is now being worked on from one whose start was refused. The row exists in both
	 * cases and on its own says only that the file arrived: a refused start leaves a pack with no
	 * task on it, which the next check would report as a recoverable "transcription failed" with a
	 * button leading back into the same refusal {@see self::takeTracksFromService()}.
	 *
	 * @param array<string, mixed> $file
	 * @return Result data: ['track' => Track] once the row of the file exists, whatever the verdict.
	 */
	private function takeFile(Call $call, array $file, bool $restoreAiChain = true): Result
	{
		$intakeResult = TrackService::getInstance()
			->intakeServiceFile($call, $this->toTrackFile($call, $file), $restoreAiChain)
		;
		if (!$intakeResult->isSuccess() && CallAISettings::isLoggingEnable())
		{
			Logger::getInstance()->error(
				'Unable to take the file of the service. Call #' . $call->getId()
				. ', file #' . (int)($file['id'] ?? 0)
				. ': ' . implode('; ', $intakeResult->getErrorMessages())
			);
		}

		return $intakeResult;
	}

	/**
	 * Files of the type the service holds, newest first. Sorted by file id here instead of trusting
	 * the order of the answer, which is set by a private query of the service and promised to
	 * nobody {@see \Bitrix\CallController\Controller\InternalApi::getTracksAction()}.
	 *
	 * @param array<int, mixed> $files
	 * @return array<int, array<string, mixed>>
	 */
	private function serviceFilesOfType(array $files, string $type): array
	{
		$filesOfType = [];
		foreach ($files as $file)
		{
			if (is_array($file) && ($file['type'] ?? null) === $type)
			{
				$filesOfType[] = $file;
			}
		}

		usort($filesOfType, static fn(array $a, array $b): int => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));

		return $filesOfType;
	}

	/**
	 * The newest file of the type the portal may still take, or null when there is none - newest,
	 * as the rest of the module reads the file of a call {@see Track::getTrackForCall()}.
	 * Deliverability is asked before the newest is picked, so a lost newer file does not hide an
	 * older one that is still there.
	 *
	 * @param array<int, mixed> $files
	 * @return array<string, mixed>|null
	 */
	private function newestDeliverableServiceFile(array $files, string $type): ?array
	{
		foreach ($this->serviceFilesOfType($files, $type) as $file)
		{
			if ($this->isDeliverableServiceFile($file))
			{
				return $file;
			}
		}

		return null;
	}

	/**
	 * The service holds no file of the type it can still hand over: every one of them is closed and
	 * sourceless. One lost pack says nothing while another pack of the call is alive, and an empty set
	 * is "not yet", not "lost".
	 *
	 * Lost is both at once {@see self::isLostServiceFile()}. A closed file whose url still works is
	 * not lost - taking it again is what the wait is for - and a file with no url the service has not
	 * closed yet is still being made. Neither ends the wait here: the deadline does, and it ends it
	 * with the very failure this verdict raises {@see self::giveUpOnExpectation()}, so nothing is lost
	 * but the earliness of the message.
	 *
	 * @param array<int, mixed> $files
	 */
	private function everyServiceFileLost(array $files, string $type): bool
	{
		$filesOfType = $this->serviceFilesOfType($files, $type);
		if ($filesOfType === [])
		{
			return false;
		}

		foreach ($filesOfType as $file)
		{
			if (!$this->isLostServiceFile($file))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the portal may take the file: there is something to download it from, and the portal
	 * has not already downloaded it once.
	 *
	 * The second one is what keeps a deletion deleted. The portal holds no row of a file it once
	 * downloaded only because that row was deleted - by the drop of one file
	 * {@see \Bitrix\Call\Controller\Track::dropAction()}, by the destroy of the record or with the
	 * whole follow-up - and taking such a file again publishes it back into the chat the user
	 * removed it from. The state comes from the service, but it is the portal that sets it: every
	 * download reports itself {@see \Bitrix\Call\Track\Downloader\DownloadAgent::run()}, so a file
	 * the portal never got still reads as deliverable and the rescue of a lost one is untouched.
	 *
	 * What is deliberately not asked is the failure the service reports. `failed` is a verdict about
	 * the delivery to this portal, never about the file: the service raises it from the telemetry the
	 * portal itself sends - a download that gave up after its retries
	 * {@see \Bitrix\Call\Track\Downloader\DownloadAgent::run()}, a probe that found no url and no
	 * agent for the file {@see \Bitrix\Call\Controller\CallController::pingTracksAction()} - and
	 * from its own giving up on a file nobody finished within a day
	 * {@see \Bitrix\CallController\Track\TrackStatePoller}. Those failures are what this wait exists
	 * to undo, so reading them as "the file is gone" would make the follow-up refuse exactly the
	 * calls it was built for, and the audio record of a call whose recording died technically would
	 * never be rescued {@see self::closeExpectationWithoutAnalysis()}.
	 *
	 * A file the user removed needs no failure of the service to stay removed. A deletion the service
	 * knows about keeps the file out of the answer altogether - neither a dropped file nor any file of
	 * a destroyed recording is reported {@see \Bitrix\CallController\Call\Track::loadByCallUuid()} -
	 * and a deletion it was never told about is refused by the download it does remember, above, and by
	 * the mark of the portal at the intake {@see TrackService::intakeServiceFile()}.
	 *
	 * The price of taking a file that failed before is bounded: one download per check of a wait whose
	 * end is its deadline {@see self::expectationDeadlineReached()}.
	 *
	 * @param array<string, mixed> $file
	 */
	private function isDeliverableServiceFile(array $file): bool
	{
		return !empty($file['fileUrl']) && !$this->isDownloadedServiceFile($file);
	}

	/**
	 * @param array<string, mixed> $file
	 */
	private function isDownloadedServiceFile(array $file): bool
	{
		return (bool)($file['downloaded'] ?? false);
	}

	/**
	 * A file the service is never going to hand over: it has closed the state of the file and holds no
	 * source to take it from {@see \Bitrix\CallController\Call\Track::isFinal()}.
	 *
	 * @param array<string, mixed> $file
	 */
	private function isLostServiceFile(array $file): bool
	{
		return (bool)($file['isFinal'] ?? false) && empty($file['fileUrl']);
	}

	/**
	 * Whether the audio record of the call is already on its way to the chat: a row the portal
	 * holds that is downloaded, or one whose download is still going.
	 *
	 * The question guards against a second audio record of the call, not against a second row of
	 * the same file - that one the intake finds by the identifier of the service
	 * {@see TrackService::findOrCreateTrack()}. The service may keep several records of one call,
	 * and taking a newer one while the first is still coming would put two of them in the chat.
	 *
	 * Every row of the call is asked, not the newest one: rows of one call arrive in no fixed
	 * order, so a record already delivered and a newer row left standing without a download would
	 * together read as "nothing is on its way" - and the file of the service would be taken as a
	 * second record of a call that already has one.
	 *
	 * A row that is neither downloaded nor being downloaded is on its way nowhere: its download was
	 * never scheduled or has given up, while the service still reports a working url for the file.
	 * Such a row is no proof of a delivery - the file is taken again and the intake reuses the row
	 * with the fresh url of the service {@see TrackService::intakeServiceFile()} - or the record
	 * this branch of the wait exists to rescue would never reach the chat at all.
	 */
	private function audioRecordOnItsWay(int $callId): bool
	{
		foreach (Track::getTracksForCall($callId, Track::TYPE_RECORD) as $record)
		{
			if ((bool)$record->getDownloaded() || DownloadAgent::hasScheduledAgent((int)$record->getId()))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The file of the service in the shape the intake of the track pipeline takes a file in - the
	 * same one the callback of the media backend arrives as.
	 *
	 * @param array<string, mixed> $file
	 */
	private function toTrackFile(Call $call, array $file): TrackFileRequest
	{
		$trackFile = new TrackFileRequest();
		$trackFile->callUuid = (string)$call->getUuid();
		$trackFile->trackId = (int)($file['id'] ?? 0);
		$trackFile->type = (string)($file['type'] ?? '');
		$trackFile->url = (string)($file['fileUrl'] ?? '');
		$trackFile->mime = (string)($file['mime'] ?? '');
		$trackFile->name = (string)($file['name'] ?? '');
		$trackFile->size = (int)($file['size'] ?? 0);
		$trackFile->duration = (int)($file['duration'] ?? 0);

		return $trackFile;
	}

	/**
	 * @param int $callId
	 * @param int $repeat
	 * @return Result
	 */
	public function checkCallAiTask(int $callId, int $repeat = 1): Result
	{
		$result = new Result();
		$result->setData(['repeat' => false]);

		$log = function (string $mess)
		{
			if (CallAISettings::isLoggingEnable())
			{
				Logger::getInstance()->error($mess);
			}
		};

		$newestOutcomeByType = [];
		$contentfulSenseByType = [];
		foreach (OutcomeCollection::getOutcomesByCallId($callId) as $candidateOutcome)
		{
			$type = $candidateOutcome->getType();
			$newestOutcomeByType[$type] ??= $candidateOutcome;
			if (isset($contentfulSenseByType[$type]))
			{
				continue;
			}
			$candidateSenseContent = $candidateOutcome->getSenseContent();
			if ($candidateSenseContent instanceof AISenseContent && $candidateSenseContent->hasContent(ignoreComputedCriteria: true))
			{
				$contentfulSenseByType[$type] = $candidateSenseContent;
			}
		}

		// Check all followup tasks
		$taskCompleted = 0;
		$waitForTasks = SenseType::cases();
		foreach ($waitForTasks as $senseType)
		{
			// "done" if any outcome of this type has content; the newest marks "exists but empty".
			$senseContent = $contentfulSenseByType[$senseType->value] ?? null;
			$taskOutcome = $newestOutcomeByType[$senseType->value] ?? null;
			if ($senseContent instanceof AISenseContent && $senseContent->hasContent(ignoreComputedCriteria: true))
			{
				$taskCompleted++;
				continue;// ok
			}

			// The last task may still be in progress: wait for its result
			$task = AITask::getTaskForCall($callId, $senseType);
			if ($task && $task->isPending())
			{
				$log("Check ai task: Call #{$callId}, {$senseType->value} is still pending.");

				// The sense is named so the end of the wait can say which step never came back
				// {@see self::giveUpOnExpectation()}. Deliberately without `wait_more`: a running
				// task is an analysis on its way {@see TrackService::checkAiTasksExecution()}.
				// The age of the task travels with it: the window is bounded by it
				// {@see self::pendingTaskExpired()} and the task is already read here.
				return $result->setData([
					'repeat' => true,
					'pending_sense' => $senseType->value,
					'pending_since' => $task->getDateCreate()?->getTimestamp(),
				]);// wait more
			}

			if ($taskOutcome)
			{
				// The outcome exists, but the AI service returned no meaningful content
				$log("Check ai task: Call #{$callId}, {$senseType->value} outcome has no meaningful content.");

				// One relaunch of the chain, on the first check only - not the limit of attempts of
				// the wait: every relaunch spends a quota.
				if ($repeat <= self::EXPECTATION_FIRST_REPEAT)
				{
					// Only report the empty sense: the caller decides whether to relaunch the chain
					return $result->setData([
						'repeat' => true,
						'restart' => true,
					]);
				}

				$error = new CallAIError(
					$senseType === SenseType::TRANSCRIBE
						? CallAIError::AI_TRANSCRIBE_TASK_ERROR
						: CallAIError::AI_OVERVIEW_TASK_ERROR
				);
				$error->allowRecover();

				return $result->addError($error);
			}

			if ($task && !$task->isFinished())
			{
				$log("Check ai task: Call #{$callId}, {$senseType->value} task has failed.");

				$error = new CallAIError(CallAIError::AI_OVERVIEW_TASK_ERROR);
				$error->allowRecover();

				return $result->addError($error);
			}
		}
		if ($taskCompleted == count($waitForTasks))
		{
			return $result;
		}

		// Check track_pack
		$trackPack = Track::getTrackForCall($callId, Track::TYPE_TRACK_PACK);
		if ($trackPack)
		{
			$log("Check ai task: Call #{$callId}, Transcription task has failed.");

			$error = new CallAIError(CallAIError::AI_TRANSCRIBE_TASK_ERROR);
			$error->allowRecover();

			return $result->addError($error);
		}

		$log("Check ai task: Call #{$callId}, Trackpack not received.");

		// The end of the wait is not decided here: the agent asks the service for the file first
		// {@see self::takeTracksFromService()} and reads the window only after that. This reports
		// the verdict alone - "the portal holds no pack of this call".
		return $result->setData([
			'repeat' => true,
			'wait_more' => true,
			'track_pack_missing' => true,
		]);
	}

	/**
	 * Whether the wait of the call is over by the clock: the window is counted from the end of the
	 * call, so shifting the next check forward again and again does not extend it. A call without
	 * dates is left to the limit of attempts rather than closed on an unknown time.
	 */
	private function expectationDeadlineReached(Call $call): bool
	{
		$finishedAt = $call->getEndDate() ?? $call->getStartDate();
		if (!$finishedAt)
		{
			return false;
		}

		return (time() - $finishedAt->getTimestamp()) >= CallAISettings::getFollowUpExpectationMaxWait();
	}

	/**
	 * Whether the check being run is the last one the call may spend.
	 */
	private function expectationAttemptsExhausted(int $repeat): bool
	{
		return $repeat >= CallAISettings::getFollowUpExpectationMaxAttempts();
	}

	/**
	 * Whether the wait must end now - the one place every branch of the wait is bounded. Only a
	 * check that found nothing to do may end it: a check that has just launched the chain or taken
	 * a file leaves the verdict to the next one, which reads what it produced.
	 *
	 * A running task is bounded by its own age and not by the end of the call, because it may have
	 * been launched long after it and the window must not kill an answer that can still come.
	 * Everything else is bounded by the deadline; attempts bound one case only, a call the service
	 * holds no pack of, because a pack that is there but not yet deliverable is still being made.
	 *
	 * @param array<string, mixed> $checkData
	 */
	private function expectationWindowClosed(Call $call, int $repeat, array $checkData): bool
	{
		if ($this->pendingSense($checkData))
		{
			return $this->pendingTaskExpired($checkData);
		}

		if (
			($checkData['restart'] ?? false) === true
			|| ($checkData['pack_taken'] ?? false) === true
		)
		{
			return false;
		}

		if ($this->expectationDeadlineReached($call))
		{
			return true;
		}

		if (($checkData['keep_attempt'] ?? false) === true)
		{
			return false;
		}

		return ($checkData['no_pack_at_service'] ?? false) === true
			&& $this->expectationAttemptsExhausted($repeat)
		;
	}

	/**
	 * The step of the analysis the check is hanging on, if it is hanging on one.
	 *
	 * @param array<string, mixed> $checkData
	 */
	private function pendingSense(array $checkData): ?SenseType
	{
		return SenseType::tryFrom((string)($checkData['pending_sense'] ?? ''));
	}

	/**
	 * Whether the task the check is hanging on has been running longer than one wait may last.
	 * Counted from the task itself: the queue of the AI service gives up on a job of its own accord
	 * much sooner, so a task older than the whole window is one whose answer is not coming.
	 *
	 * The age comes from the check that named the sense {@see self::checkCallAiTask()}, which had
	 * just read that very task; re-reading it here would be a second query for a value already in
	 * hand. A task without a creation date - or a verdict handed over without one - leaves the
	 * wait open rather than ending it on an unknown time.
	 *
	 * @param array<string, mixed> $checkData
	 */
	private function pendingTaskExpired(array $checkData): bool
	{
		$startedAt = $checkData['pending_since'] ?? null;
		if (!is_int($startedAt))
		{
			return false;
		}

		return (time() - $startedAt) >= CallAISettings::getFollowUpExpectationMaxWait();
	}

	/**
	 * The end of the wait, named after what the wait was waiting for: a step of the analysis that
	 * never came back, or a file that never arrived. The task given up on is written off as failed
	 * so it stops counting as running and the button of the message can actually relaunch the chain
	 * {@see self::restartCallAiTask()}.
	 *
	 * @param array<string, mixed> $checkData
	 * @return Result always a failure
	 */
	private function giveUpOnExpectation(Call $call, array $checkData): Result
	{
		$result = new Result();

		$pendingSense = $this->pendingSense($checkData);
		if (!$pendingSense)
		{
			return $result->addError($this->missingTrackPackError(CallAIError::TRACK_PACK_NOT_FOUND_TIMEOUT));
		}

		$error = new CallAIError(
			$pendingSense === SenseType::TRANSCRIBE
				? CallAIError::AI_TRANSCRIBE_TASK_ERROR
				: CallAIError::AI_OVERVIEW_TASK_ERROR
		);
		$error->allowRecover();

		$this->abandonPendingTasks((int)$call->getId(), $error);

		return $result->addError($error);
	}

	/**
	 * Writes off every task of the call the wait gave up on, the way the queue does when it reports
	 * a failure {@see self::onQueueTaskFail()} - but deliberately without the domain event: its
	 * consumer would send a second failure message {@see self::expectCallAiTask()}. The failure
	 * telemetry is left to that one reporting point as well.
	 *
	 * Every running task of the call and not only the one the check named: the chain launches its
	 * steps in pairs {@see self::getTaskToLaunchByOutcome()}, and a sibling left running would keep
	 * swallowing the retry the message offers {@see self::restartCallAiTask()} - which asks about
	 * any pending task of a track {@see AITask::hasPendingTaskForTrack()}, not only the newest of
	 * its sense.
	 *
	 * $senses narrows that to the types named, which is what the erasing mode needs: it replaces
	 * the text results of the call and has no business writing off the transcription that feeds
	 * them {@see self::restartWithPurge()}. Null keeps the whole call, as the wait that gave up
	 * has always meant.
	 *
	 * Both statuses of an unanswered task are taken, the same two every reader of "the call is
	 * still busy" counts {@see AITask::hasPendingTaskForCall()}, {@see self::hasAnythingToWaitFor()}.
	 * A row left in {@see AITask::STATUS_READY} is a task the queue never took - the launch a
	 * handler of `call:onCallAiTask` refused, which by the decision of the owner keeps the status
	 * it was created with {@see self::processTrack()}, or a process that died between the save and
	 * the launch. Every reader counts such a row as work in progress, so a writer that cannot see
	 * it leaves the call silent for good: nothing ever answers for the row, and neither the wait
	 * giving up nor the erasing mode would end it. Ending it says nothing in the chat - this write-off fires no event - so
	 * the veto keeps the silence it was given at the moment it happened, and only stops outliving
	 * every later attempt of the call.
	 *
	 * @param string[]|null $senses values of {@see SenseType}, null for every type of the call
	 * @return array<int, array{status: string, type: string}> the rows this write-off really
	 *     ended, each with the status it had before and the type it is of - what a caller that may
	 *     have to take it back needs {@see self::restorePendingTasks()}. The type is there because
	 *     taking it back is a decision per type and not per write-off: a run that started one type
	 *     and was refused the next gives back only the ones it was refused
	 *     {@see self::restartWithPurge()}. A caller whose write-off is final ignores all of it, as
	 *     the wait that gave up does.
	 */
	private function abandonPendingTasks(int $callId, CallAIError $error, ?array $senses = null): array
	{
		if ($senses === [])
		{
			return [];
		}

		$query = CallAITaskTable::query()
			->setSelect(['*'])
			->where('CALL_ID', $callId)
			->whereIn('STATUS', [AITask::STATUS_READY, AITask::STATUS_PENDING])
		;
		if ($senses !== null)
		{
			$query->whereIn('TYPE', $senses);
		}

		$abandoned = [];
		$rows = $query->exec();
		while ($row = $rows->fetchObject())
		{
			$task = AITask::buildBySource($row);
			$previousStatus = (string)$task->getStatus();
			$saveResult = $task
				->setStatus(AITask::STATUS_FAILED)
				->setDateFinished(new DateTime)
				->setErrorCode($error->getCode())
				->setErrorMessage($error->getMessage())
				->save()
			;
			if ($saveResult->isSuccess())
			{
				$abandoned[(int)$task->getId()] = [
					'status' => $previousStatus,
					'type' => (string)$task->getType(),
				];
			}
		}

		return $abandoned;
	}

	/**
	 * Gives the tasks a write-off ended their wait back, where the run it made room for never
	 * happened {@see self::restartWithPurge()}.
	 *
	 * Only the rows that very write-off ended, and only while they are still as it left them: the
	 * status it wrote and the code of the error it wrote with. Anything else on the row is another
	 * path's answer - a callback of the engine arriving late, a write-off of a later run - and an
	 * answer already given is not one to overwrite.
	 *
	 * Written field by field and not through a save of the object: a full save would put back
	 * every other field as this pass happens to see it {@see self::restoreAiAnalyze()}. The four
	 * fields the write-off wrote are the four it takes back, so the row comes out of the refusal
	 * exactly as it went into it.
	 *
	 * The status put back is the one the row had, and never a constant: the write-off takes both
	 * statuses of an unanswered task {@see self::abandonPendingTasks()}, and a row of a task the
	 * queue never took would come back saying "the launch reached the queue" - a state it never
	 * was in.
	 *
	 * @param array<int, array{status: string, type: string}> $writtenOff id of the row => what the
	 *     write-off found on it {@see self::abandonPendingTasks()}
	 */
	private function restorePendingTasks(array $writtenOff, CallAIError $error): void
	{
		foreach ($writtenOff as $taskId => $written)
		{
			$previousStatus = (string)$written['status'];

			$stillWrittenOff = CallAITaskTable::query()
				->setSelect(['ID'])
				->where('ID', $taskId)
				->where('STATUS', AITask::STATUS_FAILED)
				->where('ERROR_CODE', $error->getCode())
				->setLimit(1)
				->exec()
				->fetch()
			;
			if ($stillWrittenOff === false)
			{
				continue;
			}

			CallAITaskTable::update($taskId, [
				'STATUS' => $previousStatus,
				'DATE_FINISHED' => null,
				'ERROR_CODE' => null,
				'ERROR_MESSAGE' => null,
			]);
		}
	}
	//endregion

	/**
	 * A failure to get the file of the call, in either of the two codes that say so. Both are
	 * recoverable, so the chat message carries the retry button
	 * {@see ChatMessage::generateTaskFailedMessage()}, and the retry asks the service about the
	 * files instead of repeating the refusal {@see self::retryCallAiTask()}.
	 */
	private function missingTrackPackError(string $code): CallAIError
	{
		return (new CallAIError($code))->allowRecover();
	}

	/**
	 * Returns every file the controller service keeps for the call - files of the service, not the
	 * {@see Track} records of the portal. An error means "the service did not tell", never "the
	 * call has no files".
	 *
	 * @param string $uuid Uuid of the call on the portal.
	 * @return Result Data of a successful result: ['tracks' => list of file states].
	 */
	public function getServiceTracks(string $uuid): Result
	{
		return $this->getControllerClient()->getTracks($uuid);
	}

	/**
	 * The only place the controller client is built: the factory is substituted in tests to reach
	 * the branches of an unreachable service.
	 *
	 * @return ControllerClient
	 */
	private function getControllerClient(): ControllerClient
	{
		$factory = self::$controllerClientFactory;

		return $factory ? $factory() : new ControllerClient();
	}

	/**
	 * Removes the follow-up of the call: its tasks, its outcomes and every file row it was built
	 * from {@see \Bitrix\Call\Controller\FollowUp::dropAction()}.
	 *
	 * The mark of the deletion goes first, the wait right after it. A check left standing would
	 * find a call the portal holds no pack of any more, ask the service for one, take back the very
	 * files just deleted and pay for a new analysis of them - and cancelling the wait stops only
	 * the checks the lookup of the agent can find. A row of an older form is invisible to it until
	 * the migration of the update converts it {@see self::migrateLegacyExpectationAgents()}, a check
	 * already in flight carries an instance of the call loaded before the deletion, and the media
	 * backend may deliver the pack of the call by itself
	 * {@see \Bitrix\Call\Controller\CallController::trackReadyAction()}. The mark is read by all
	 * of those, and by the button left in the chat and the public restart, so it is the one thing
	 * that closes them together {@see Call::isRecordDestroyed()}.
	 *
	 * It is the mark of a recording a user removed, and that is what this deletion leaves behind:
	 * every file row of the call goes with it, the record included, and each row the service knows
	 * of is dropped there too {@see Track::drop()}. A call that records again lifts the mark
	 * {@see Call::liftRecordDestroyedMark()}.
	 *
	 * @param int $callId
	 * @return Result
	 */
	public function dropCallAiFollowUp(int $callId): Result
	{
		$result = new Result();

		Registry::getCallWithId($callId)?->markRecordDestroyed();
		$this->removeExpectation($callId);

		$taskList = CallAITaskTable::query()
			->where('CALL_ID', $callId)
			->exec()
		;
		while ($row = $taskList->fetchObject())
		{
			$task = AITask::buildBySource($row);
			$task->drop();
		}

		$outcomeCollection = OutcomeCollection::getOutcomesByCallId($callId);
		foreach ($outcomeCollection as $outcome)
		{
			$outcome->drop();
		}

		$trackList = CallTrackTable::query()
			->setSelect(['FILE_ID', 'DISK_FILE_ID', 'EXTERNAL_TRACK_ID'])
			->where('CALL_ID', $callId)
			->exec()
		;
		while ($track = $trackList->fetchObject())
		{
			$track->drop();
		}

		return $result;
	}
}
