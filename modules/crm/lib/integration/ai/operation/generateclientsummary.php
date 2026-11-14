<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\AI\Context;
use Bitrix\AI\Payload\IPayload;
use Bitrix\AI\Payload\Prompt;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\ClientSummaryData;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadFactory;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Integration\Analytics\Builder\AI\GenerateClientSummaryEvent;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Chat\SummaryChatMessenger;
use Bitrix\Crm\V2\Internal\Service\AiSummary\ClientSummaryOrigin;
use Bitrix\Crm\V2\Internal\Service\AiSummary\ClientSummaryReadyNotifier;
use Bitrix\Crm\V2\Internal\Service\AiSummary\ClientSummaryState;
use Bitrix\Crm\V2\Internal\Service\AiSummary\SummaryResponseParser;
use Bitrix\Main;
use CCrmOwnerType;

/**
 * Generates the client summary that the CRM card button asks for; the answer is delivered to the
 * copilot chat by the queue callback.
 *
 * Keeps the inherited text engine setting on purpose: the summarize-specific
 * SETTINGS_SUMMARIZE_ENGINE_CODE is not registered in EventHandler::onTuningLoad(), so a launch on it
 * fails with AI_ENGINE_NOT_FOUND (@see SummarizeCallTranscription, pinned by its test).
 *
 * Access to the entity and availability of the summary are checked by the launch service before the
 * job is created: the asynchronous finish has no authorized user to ask.
 */
final class GenerateClientSummary extends AbstractOperation
{
	public const TYPE_ID = 17;
	public const CONTEXT_ID = 'generate_client_summary';

	public const SUPPORTED_TARGET_ENTITY_TYPE_IDS = [
		CCrmOwnerType::Contact,
		CCrmOwnerType::Company,
	];

	/**
	 * A PENDING job older than this (seconds) is treated as dead and never blocks a new launch: the
	 * chat is already open and waiting for an answer, so a stuck job must not silence the button for
	 * good. The summary finishes well under a minute; 10 minutes is a safe upper bound.
	 */
	private const STALE_PENDING_TTL = 600;

	protected const PAYLOAD_CLASS = ClientSummaryData::class;

	/**
	 * The key of the delivery mark in b_crm_ai_queue.RESULT. The mark is the only sign that the answer
	 * of this job - the summary or the refusal of it - has already been written into the chat (ALG-02),
	 * and it is put down before the message is sent.
	 */
	private const DELIVERY_MARK = 'summaryDelivered';

	/**
	 * The addressee of a job, kept from the moment the answer of the model is read to the moment it is
	 * delivered: between the two the framework overwrites RESULT with the payload, and the addressee
	 * seeded at launch does not survive it (R-3).
	 *
	 * @var array<int, array{chatId: int, language: string, origin: ClientSummaryOrigin}> identifier of
	 *      the job => addressee
	 */
	private static array $launchAddressByJobId = [];

	/**
	 * The chat the answer is delivered to. The launch service opens it before the job is created, so
	 * the operation takes it as given and never looks for a chat of its own. A launch from the tab of
	 * the call card has no chat at all and leaves this at zero ([P1.T4]).
	 */
	private readonly int $chatId;

	/**
	 * Where the launch came from. It decides nothing about how the summary is written and only tells
	 * an absent chat apart: a fault of the chat launch from the normal shape of the tab one.
	 */
	private readonly ClientSummaryOrigin $origin;

	public function __construct(
		ItemIdentifier $target,
		?int $userId = null,
		?int $parentJobId = null,
		int $chatId = 0,
		ClientSummaryOrigin $origin = ClientSummaryOrigin::Chat,
	)
	{
		parent::__construct($target, $userId, $parentJobId);

		$this->setScenario(Scenario::CLIENT_SUMMARY_SCENARIO);

		$this->chatId = $chatId;
		$this->origin = $origin;
	}

	public static function isSuitableTarget(ItemIdentifier $target): bool
	{
		return in_array($target->getEntityTypeId(), self::SUPPORTED_TARGET_ENTITY_TYPE_IDS, true);
	}

	/**
	 * Dedup policy (ALG-01): one running job per client and per user. The user belongs to the key
	 * because the data is collected on their behalf - people with different rights get different
	 * summaries, and one job would answer both with the data of one of them.
	 *
	 * Only a PENDING job blocks a launch. A finished one, successful or failed, is an answer the user
	 * already has in the chat, and the next click asks for a new one.
	 *
	 * The refusal reaches the launch service as ErrorCode::JOB_ALREADY_EXISTS: by it the service tells
	 * a job it has just queued from one that was already running.
	 */
	protected function checkPreviousJobsBeforeLaunch(): Main\Result
	{
		return self::checkPendingJobsOfUser($this->target, (int)$this->userId, $this->chatId > 0);
	}

	/**
	 * Tells whether a fresh job of this target and this user is already running.
	 *
	 * Asked before the chat is told that the summary is being collected: the launch that answers with
	 * the stub is the one that has nobody ahead of it. The question is the very one the dedup of the
	 * launch asks, so it is read off its refusal - the age a job is counted stale by is decided in one
	 * place only ({@see isJobFresh}).
	 *
	 * The caller says whether the launch it asks for delivers to a chat: the question of the stub must
	 * be the very question of the queueing, or the two would answer one click differently.
	 */
	public static function isJobRunningFor(ItemIdentifier $target, int $userId, bool $isDeliveredToChat): bool
	{
		return !self::checkPendingJobsOfUser($target, $userId, $isDeliveredToChat)->isSuccess();
	}

	/**
	 * A running job blocks a new launch only while it delivers everything the new launch would deliver.
	 * A job queued from the tab of the call card carries no chat, so it answers the person with the
	 * signal alone and leaves a launch from the chat with nothing in the chat: such a job neither
	 * blocks that launch nor counts as a stale row to relaunch, and the chat gets a job of its own.
	 *
	 * The asymmetry is the rule itself and not an oversight: a launch from the tab delivers the signal
	 * only, so it joins any running job of this pair, a chat one included (ADR section 3).
	 *
	 * A stale row is taken over only by a launch that delivers everything that row was going to - the
	 * same rule once more, on the other end of it. The framework relaunches a stale row by rewriting
	 * the seed of it ({@see getJobUpdateFields()}), and a launch from the tab carries no chat, so a
	 * stuck job of the chat taken over by the tab would leave the chat waiting on a stub no answer is
	 * coming to, its failure unlogged along with it. The chat relaunches its own row on the next click
	 * and the tab queues a row of its own.
	 */
	protected static function checkPendingJobsOfUser(
		ItemIdentifier $target,
		int $userId,
		bool $isDeliveredToChat,
	): Main\Result
	{
		$result = new Main\Result();

		$stalePendingJob = null;
		foreach (self::findPendingJobs($target, $userId) as $job)
		{
			$isJobDeliveredToChat = self::extractLaunchAddress($job)['chatId'] > 0;
			if ($isDeliveredToChat && !$isJobDeliveredToChat)
			{
				continue;
			}

			if (self::isJobFresh($job))
			{
				return $result->addError(ErrorCode::getJobAlreadyExistsError());
			}

			if ($isJobDeliveredToChat && !$isDeliveredToChat)
			{
				continue;
			}

			// the jobs come newest first: a stuck row is relaunched instead of piling up a new one
			$stalePendingJob ??= $job;
		}

		return $stalePendingJob === null
			? $result
			: $result->setData(['previousJob' => $stalePendingJob])
		;
	}

	/**
	 * The newest pending job decides on its own: a fresh one refuses the launch, and a stale one is
	 * relaunched together with every older row, which is stale by definition.
	 *
	 * RESULT is among the fields because the chat of a job is read out of the seed of its launch
	 * ({@see extractLaunchAddress()}), and by it the rule above tells a job that delivers to a chat
	 * from one that does not.
	 *
	 * @return EO_Queue[] newest first, one row at most
	 */
	private static function findPendingJobs(ItemIdentifier $target, int $userId): array
	{
		return QueueTable::query()
			->setSelect(['ID', 'EXECUTION_STATUS', 'RETRY_COUNT', 'CREATED_TIME', 'RESULT'])
			->where('ENTITY_TYPE_ID', $target->getEntityTypeId())
			->where('ENTITY_ID', $target->getEntityId())
			->where('TYPE_ID', self::TYPE_ID)
			->where('USER_ID', $userId)
			->where('EXECUTION_STATUS', QueueTable::EXECUTION_STATUS_PENDING)
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->fetchCollection()
			->getAll()
		;
	}

	/**
	 * Public because the tab of the call card asks the very same question: a pending job that has gone
	 * stale is read there as no job at all, and the button to launch comes back. The age a job is
	 * counted stale by stays decided in this one place - an open constant would split the rule in two,
	 * and the tab and the launch would start contradicting each other.
	 */
	public static function isJobFresh(EO_Queue $job): bool
	{
		$createdTime = $job->getCreatedTime();
		if (!($createdTime instanceof Main\Type\DateTime))
		{
			// Unknown age: treat as stale so a genuinely stuck job stays relaunchable.
			return false;
		}

		$ageSeconds = time() - $createdTime->getTimestamp();

		return $ageSeconds >= 0 && $ageSeconds < self::STALE_PENDING_TTL;
	}

	protected function getJobAddFields(): array
	{
		return ['RESULT' => $this->buildLaunchResult()] + parent::getJobAddFields();
	}

	/**
	 * A stale row is relaunched, not continued, so the age it is judged by starts over with this
	 * attempt. Without the restart the next click reads the birth of the dead job, calls the running
	 * one stale again and sends one more request to the model ({@see isJobFresh}).
	 *
	 * The seed of the launch is rewritten along with it, and the addressee of the row changes to this
	 * one. Which is why only a launch delivering everything the stale row was going to is handed the
	 * row at all ({@see checkPendingJobsOfUser()}).
	 */
	protected function getJobUpdateFields(): array
	{
		return [
			'RESULT' => $this->buildLaunchResult(),
			'CREATED_TIME' => new Main\Type\DateTime(),
		] + parent::getJobUpdateFields();
	}

	/**
	 * Seeds b_crm_ai_queue.RESULT at launch with the addressee of the answer: the chat to write into
	 * and the client the summary is about. The asynchronous finish carries no context of the launch
	 * (R-3), so without the seed a refusal would have nowhere to go. The row is PENDING here, and
	 * setResult() overwrites RESULT with the payload once the job succeeds.
	 *
	 * The language of the person goes with them. The model is asked to answer in it here, at launch
	 * ({@see \Bitrix\Crm\Integration\AI\Operation\Payload\Payload\ClientSummary}), while the
	 * buttons, the mark of a cut text and the refusals are written in the callback of the queue, where
	 * there is no user left to read a language off - and a summary in German with buttons in Russian is
	 * what a default of the portal would give.
	 */
	private function buildLaunchResult(): string
	{
		return Main\Web\Json::encode([
			'chatId' => $this->chatId,
			'entityTypeId' => $this->target->getEntityTypeId(),
			'entityId' => $this->target->getEntityId(),
			'userId' => (int)$this->userId,
			'language' => Main\Localization\Loc::getCurrentLang(),
			'origin' => $this->origin->value,
			'precedingFinishedJobId' => $this->readPrecedingFinishedJobId(),
		]);
	}

	/**
	 * The answer this pair already had at the moment this job was queued, by the identifier of its row.
	 *
	 * Written down because the times of the queue cannot be asked instead. They are kept to the second,
	 * and a second shared by the finish of one job and the queueing of another puts the two in no order
	 * at all - while that shared second is exactly the case worth telling apart, because a launch
	 * follows the answer it replaces by as little as one click. By this identifier the tab of the call
	 * card tells a summary that arrived while it was waiting from the one the person already had
	 * ({@see \Bitrix\Crm\V2\Internal\Service\AiSummary\ClientSummaryStateReader}).
	 *
	 * Zero means the pair had no answer at all yet. The row is read at every launch and not cached: a
	 * launch ends in a request to the model, and one indexed query beside it costs nothing.
	 */
	private function readPrecedingFinishedJobId(): int
	{
		$precedingJob = JobRepository::getInstance()
			->getLastFinishedClientSummaryJob($this->target, (int)$this->userId)
		;

		return (int)($precedingJob?->getId() ?? 0);
	}

	/**
	 * Reads the launch-seeded addressee back out of a job. Zeroes mean the row never carried one, and
	 * the caller has nobody to answer.
	 *
	 * @return array{chatId: int, entityTypeId: int, entityId: int, userId: int, language: string,
	 *         origin: ClientSummaryOrigin, precedingFinishedJobId: int|null}
	 */
	public static function extractLaunchAddress(EO_Queue $job): array
	{
		return self::readLaunchAddress(self::decodeLaunchResult((string)$job->getResult()));
	}

	/**
	 * A row seeded before the tab existed carries no place of launch, and the chat is the only place
	 * there was: that is what an unreadable value falls back to.
	 *
	 * The answer that preceded the launch answers `null` and never zero when the row does not carry it:
	 * zero is the launch saying this pair had no answer yet, and the two must not become one word - the
	 * reader of the tab falls back to the times of the queue for a row that says nothing, and trusts
	 * the number for a row that does.
	 *
	 * @return array{chatId: int, entityTypeId: int, entityId: int, userId: int, language: string,
	 *         origin: ClientSummaryOrigin, precedingFinishedJobId: int|null}
	 */
	private static function readLaunchAddress(array $decoded): array
	{
		return [
			'chatId' => (int)($decoded['chatId'] ?? 0),
			'entityTypeId' => (int)($decoded['entityTypeId'] ?? 0),
			'entityId' => (int)($decoded['entityId'] ?? 0),
			'userId' => (int)($decoded['userId'] ?? 0),
			'language' => (string)($decoded['language'] ?? ''),
			'origin' => ClientSummaryOrigin::tryFrom((string)($decoded['origin'] ?? ''))
				?? ClientSummaryOrigin::Chat,
			'precedingFinishedJobId' => isset($decoded['precedingFinishedJobId'])
				? (int)$decoded['precedingFinishedJobId']
				: null,
		];
	}

	private static function decodeLaunchResult(string $raw): array
	{
		if ($raw === '')
		{
			return [];
		}

		try
		{
			$decoded = Main\Web\Json::decode($raw);
		}
		catch (Main\ArgumentException)
		{
			return [];
		}

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * A missing record in the prompt registry and data that did not collect are both refusals to
	 * launch: without the record the engine is sent the code of the prompt itself, without the data a
	 * prompt with its markers still in it - an odd answer of the model instead of an honest error.
	 */
	protected function getAIPayload(): Main\Result
	{
		$result = PayloadFactory::build(self::TYPE_ID, $this->userId, $this->target)
			->setMarkers([])
			->getResult()
		;

		/** @var IPayload $payload */
		$payload = $result->getData()['payload'];

		if ($payload instanceof Prompt && $payload->getPromptCode() === '')
		{
			return (new Main\Result())->addError(ErrorCode::getPayloadNotFoundError());
		}

		if ($payload->getMarkers() === [])
		{
			return (new Main\Result())->addError(ErrorCode::getInvalidPayloadError());
		}

		return $result;
	}

	/**
	 * Here the answer of the model is read, and here the addressee of it is taken down: the job still
	 * carries the launch seed at this point, and by the time the answer is delivered it does not.
	 *
	 * An answer that broke the contract is cut off right here and never reaches the delivery: the
	 * framework asks the payload whether it is valid and takes its own error branch
	 * ({@see AbstractOperation::onQueueJobExecute()}), which ends in {@see self::notifyAboutJobError()}.
	 * So a broken answer is turned into a payload that says it is broken - the reason of it is written
	 * to the log by the parser and never shown to a person (AC-020).
	 */
	protected static function extractPayloadFromAIResult(\Bitrix\AI\Result $result, EO_Queue $job): Dto
	{
		self::captureLaunchAddress((int)$job->getId());

		$parsed = SummaryResponseParser::parse($result->getPrettifiedData());
		if (!$parsed->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: The model answered past the contract of the client summary: {errors}' . PHP_EOL,
				[
					'class' => self::class,
					'errors' => $parsed->getErrors(),
				],
			);

			// an empty set of fields and not null: a payload built of nothing states the violation of the
			// contract, while a payload built of null is not checked at all and would pass as valid
			return new ClientSummaryData([]);
		}

		return $parsed->getData()[SummaryResponseParser::SUMMARY_KEY];
	}

	/**
	 * The addressee is read from the row and not from the object of the job on purpose: which fields
	 * the caller selected is not this operation's to know, and the row still holds the seed of the
	 * launch.
	 *
	 * The snapshot is taken whatever the chat is. A job launched from the tab has none, and a snapshot
	 * skipped over that would lose the language and the place of launch along with it.
	 */
	private static function captureLaunchAddress(int $jobId): void
	{
		if ($jobId <= 0)
		{
			return;
		}

		$address = self::findLaunchAddress($jobId);

		self::$launchAddressByJobId[$jobId] = [
			'chatId' => $address['chatId'],
			'language' => $address['language'],
			'origin' => $address['origin'],
		];
	}

	/**
	 * @return array{chatId: int, language: string, origin: ClientSummaryOrigin}
	 */
	private static function takeCapturedAddress(int $jobId): array
	{
		$address = self::$launchAddressByJobId[$jobId]
			?? ['chatId' => 0, 'language' => '', 'origin' => ClientSummaryOrigin::Chat]
		;
		unset(self::$launchAddressByJobId[$jobId]);

		return $address;
	}

	protected static function getJobFinishEventBuilder(): GenerateClientSummaryEvent
	{
		return new GenerateClientSummaryEvent();
	}

	// region notify
	protected static function notifyTimelineAfterSuccessfulLaunch(Result $result): void
	{
		// the summary lives in the chat, not in the timeline
	}

	protected static function notifyTimelineAfterSuccessfulJobFinish(Result $result): void
	{
		// the summary lives in the chat, not in the timeline
	}

	/**
	 * The summary is delivered to the chat (ALG-02).
	 *
	 * The mark of delivery is read before anything is sent and is put down before the message goes out:
	 * a callback of the queue may arrive a second time for one job (R-2), and the mark is the only thing
	 * telling the second one from the first, so a person never reads two summaries of one click (AC-013).
	 *
	 * The payload arrives checked - the framework refuses a broken answer of the model earlier
	 * ({@see self::extractPayloadFromAIResult()}) - and the branch below is the second line of the same
	 * defence: a payload that is not a summary is answered with a refusal and not with silence.
	 *
	 * A send that did not reach the chat is answered the same way. The messenger never throws and says
	 * so with a bool ({@see SummaryChatMessenger}), and a summary too long or a chat that refused it
	 * would otherwise leave the person with the stub alone (AC-012).
	 *
	 * A job launched from the tab of the call card has no chat, and an absent chat skips the message
	 * alone: the mark of delivery is put down before it and for every launch, because it is what tells
	 * a second callback of one job from the first ([P1.T4]).
	 *
	 * The signal to the person goes out ahead of the chat and for every launch alike (EVENT-01): a tab
	 * that joined a job queued from the chat has no other way of learning that the summary is ready. It
	 * says the summary is ready only when there is one - an answer that is not a summary is the very
	 * failure the chat is told about below, and the tab is told the same thing.
	 */
	protected static function onAfterSuccessfulJobFinish(Result $result, ?Context $context = null): void
	{
		$jobId = (int)$result->getJobId();
		$storedResult = self::readJobResult($jobId);
		if (self::isAnswerDelivered($storedResult))
		{
			return;
		}

		[
			'chatId' => $chatId,
			'language' => $language,
			'origin' => $origin,
		] = self::takeCapturedAddress($jobId);

		if ($jobId > 0)
		{
			self::markAnswerDelivered($jobId, $storedResult, $chatId);
		}

		$summary = $result->getPayload();
		$target = $result->getTarget();
		$isSummaryReady = $summary instanceof ClientSummaryData && $target !== null;

		self::signalStateChanged(
			(int)$result->getUserId(),
			$target,
			$isSummaryReady ? ClientSummaryState::Ready : ClientSummaryState::Failed,
		);

		if ($chatId <= 0)
		{
			if ($origin === ClientSummaryOrigin::Chat)
			{
				AIManager::logger()->error(
					'{date}: {class}: The summary of job {jobId} on target {target} has nowhere to go:'
					. ' the launch left no chat' . PHP_EOL,
					[
						'class' => self::class,
						'jobId' => $jobId,
						'target' => $result->getTarget(),
					],
				);
			}

			return;
		}

		$messenger = new SummaryChatMessenger();

		if (!$isSummaryReady)
		{
			$messenger->sendFailure($chatId, $language);

			return;
		}

		if (!$messenger->sendSummary($chatId, $target, $summary, $language))
		{
			$messenger->sendFailure($chatId, $language);
		}
	}

	/**
	 * Every refusal of this operation passes here, and the asynchronous ones are answered in the chat.
	 *
	 * A synchronous refusal is not: it happens before the job row exists, so the identifier of the job
	 * is empty, and the launch service answers it on the spot - it still holds the chat and the user
	 * ([P3.T5], synchronous branch). Were the message sent from here as well, a person would read the
	 * same refusal twice.
	 *
	 * The stub is already in the chat by this point, so the refusal comes after it and the person is
	 * left with an answer instead of an indicator that never ends (AC-012). The addressee is taken from
	 * the result of the job and not from the context of the operation: the context of the launch does
	 * not reach an asynchronous finish (R-3).
	 *
	 * The way out is split in two on purpose. A known job whose answer is still undelivered is marked
	 * delivered whatever its addressees are, and only the message in the chat waits on a chat: a job
	 * launched from the tab would otherwise pass this method by without leaving a trace of its failure
	 * ([P1.T4]).
	 *
	 * The signal of the failure goes out in between, inside the same mark of delivery (EVENT-01): put
	 * after the check of the chat it would never reach a launch from the tab, and put before the mark a
	 * second reading of one job would send a second failure.
	 */
	protected static function notifyAboutJobError(
		Result $result,
		bool $withSyncBadges = true,
		bool $withSendAnalytics = true,
		?ItemIdentifier $target = null,
	): void
	{
		$jobId = (int)$result->getJobId();
		$storedResult = self::readJobResult($jobId);
		// taken first and not as a fallback: the process map must be released on every branch
		$captured = self::takeCapturedAddress($jobId);
		$seeded = self::readLaunchAddress($storedResult);
		$chatId = $seeded['chatId'] ?: $captured['chatId'];
		$language = $seeded['language'] ?: $captured['language'];

		AIManager::logger()->error(
			'{date}: {class}: Generate client summary job error on target {target} for chat {chatId}' . PHP_EOL,
			[
				'class' => self::class,
				'target' => $result->getTarget(),
				'chatId' => $chatId,
			],
		);

		if ($jobId <= 0 || self::isAnswerDelivered($storedResult))
		{
			return;
		}

		self::markAnswerDelivered($jobId, $storedResult, $chatId);

		self::signalStateChanged(
			(int)$result->getUserId() ?: $seeded['userId'],
			$result->getTarget() ?? $target ?? self::clientOfSeed($seeded),
			ClientSummaryState::Failed,
		);

		if ($chatId <= 0)
		{
			return;
		}

		(new SummaryChatMessenger())->sendFailure($chatId, $language);
	}

	/**
	 * Tells the person who asked for the summary how their job ended (EVENT-01). Sent from inside the
	 * same defence the message of the chat is sent from, so a second callback of one job never sends a
	 * second signal.
	 *
	 * An unknown addressee is answered with silence: a job with no user or with no client behind it has
	 * nobody to tell and nothing to tell about.
	 */
	private static function signalStateChanged(
		int $userId,
		?ItemIdentifier $client,
		ClientSummaryState $state,
	): void
	{
		if ($userId <= 0 || $client === null)
		{
			return;
		}

		(new ClientSummaryReadyNotifier())->notify($userId, $client, $state);
	}

	/**
	 * The client of a job read out of the seed of its launch: the last addressee left once the result
	 * of the job has been overwritten and the caller passed no target of its own.
	 *
	 * @param array{entityTypeId: int, entityId: int} $seeded
	 */
	private static function clientOfSeed(array $seeded): ?ItemIdentifier
	{
		return CCrmOwnerType::isCorrectEntityTypeId($seeded['entityTypeId']) && $seeded['entityId'] > 0
			? new ItemIdentifier($seeded['entityTypeId'], $seeded['entityId'])
			: null
		;
	}

	private static function isAnswerDelivered(array $storedResult): bool
	{
		return ($storedResult[self::DELIVERY_MARK] ?? false) === true;
	}

	/**
	 * The mark goes into the result of the job beside the payload, and the chat goes with it: the keys
	 * of the contract of the answer are read back by {@see ClientSummaryData}, which takes its own two
	 * and drops everything else, so neither key changes what the summary is.
	 *
	 * A mark that could not be saved does not hold the summary back. The job has just been written
	 * successfully, so a refusal here means the database is gone; of the two outcomes left - a summary
	 * a person may read twice and a summary they never read - the first one is the lesser.
	 */
	private static function markAnswerDelivered(int $jobId, array $storedResult, int $chatId): void
	{
		$storedResult[self::DELIVERY_MARK] = true;
		$storedResult['chatId'] = $chatId;

		try
		{
			$updateResult = QueueTable::update($jobId, ['RESULT' => Main\Web\Json::encode($storedResult)]);
			$errors = $updateResult->getErrorMessages();
		}
		catch (\Throwable $exception)
		{
			$errors = [$exception->getMessage()];
		}

		if ($errors !== [])
		{
			AIManager::logger()->critical(
				'{date}: {class}: Cant mark the answer of job {jobId} as delivered: {errors}' . PHP_EOL,
				[
					'class' => self::class,
					'jobId' => $jobId,
					'errors' => $errors,
				],
			);
		}
	}

	/**
	 * The addressee of a job that has already been created. A launch refused before the job exists
	 * carries no identifier, and there is nothing to read.
	 *
	 * @return array{chatId: int, entityTypeId: int, entityId: int, userId: int, language: string}
	 */
	private static function findLaunchAddress(int $jobId): array
	{
		return self::readLaunchAddress(self::readJobResult($jobId));
	}

	private static function readJobResult(int $jobId): array
	{
		$job = $jobId > 0
			? QueueTable::query()->setSelect(['ID', 'RESULT'])->where('ID', $jobId)->fetchObject()
			: null
		;

		return $job === null ? [] : self::decodeLaunchResult((string)$job->getResult());
	}
	// endregion
}
