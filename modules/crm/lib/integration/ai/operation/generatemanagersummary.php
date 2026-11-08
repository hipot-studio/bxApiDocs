<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\AI\Context;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\Scoring\ManagerSummaryPayload;
use Bitrix\Crm\Integration\AI\EventHandler;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadFactory;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Integration\Imbot\CallScoringV2SummaryBot;
use Bitrix\Crm\Copilot\CallAssessment\CriteriaLoader;
use Bitrix\Crm\Copilot\CallAssessment\Entity\CopilotCallAssessmentTable;
use Bitrix\Crm\Copilot\CallAssessment\Summary\ManagerSummaryDataProvider;
use Bitrix\Crm\Copilot\CallAssessment\Summary\ManagerSummaryFormatter;
use Bitrix\Crm\Copilot\CallAssessment\Summary\ManagerSummaryRenderContext;
use Bitrix\Crm\Copilot\CallAssessment\Summary\SettingsRepository;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Integration\Analytics\Builder\AI\GenerateManagerSummaryEvent;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Main;
use CCrmOwnerType;

final class GenerateManagerSummary extends AbstractOperation
{
	public const TYPE_ID = 15;
	public const CONTEXT_ID = 'generate_manager_summary';

	/**
	 * A PENDING job older than this (seconds) is treated as dead and may be relaunched.
	 * The summary AI job normally finishes well under a minute; 10 minutes is a safe
	 * upper bound that still lets a genuinely stuck job be retried. Tune by observation.
	 */
	private const STALE_PENDING_TTL = 600;

	protected const PAYLOAD_CLASS = ManagerSummaryPayload::class;
	protected const ENGINE_CODE = EventHandler::SETTINGS_CALL_ASSESSMENT_ENGINE_CODE;

	/**
	 * The moment the summary button was shown, pinning the 7-day AI-input window (see getAIPayload).
	 * getAIPayload() runs synchronously inside launch(), so this launch-time value is enough - it is
	 * intentionally not persisted for the asynchronous finish. Null falls back to "now".
	 */
	private ?Main\Type\DateTime $referenceDate;

	public function __construct(
		int $managerId,
		?int $userId = null,
		?int $parentJobId = null,
		?Main\Type\DateTime $referenceDate = null,
	)
	{
		parent::__construct(
			new ItemIdentifier(CCrmOwnerType::CopilotCallAssessment, $managerId),
			$userId,
			$parentJobId,
		);

		$this->referenceDate = $referenceDate;
	}

	public static function isAccessGranted(int $userId, ItemIdentifier $target): bool
	{
		return $userId > 0
			&& self::isSuitableTarget($target)
			&& self::isSummaryRecipient($userId)
		;
	}

	/**
	 * Defense-in-depth: only configured summary recipients may launch/receive the summary.
	 * Reuses SettingsRepository (already drops inactive users) as the single source of truth.
	 */
	private static function isSummaryRecipient(int $userId): bool
	{
		return in_array($userId, (new SettingsRepository())->load()->recipientUserIds, true);
	}

	public static function isSuitableTarget(ItemIdentifier $target): bool
	{
		return $target->getEntityTypeId() === CCrmOwnerType::CopilotCallAssessment;
	}

	/**
	 * Idempotency / dedup policy (ALG-01) keyed by the single job-per-manager invariant
	 * (ENTITY_ID = managerId, TYPE_ID). Returning an error blocks a new AI job; returning
	 * success with previousJob set makes launch() relaunch by updating that same row.
	 *
	 * The SUCCESS cache-hit decision is owned entirely by deliverCachedIfFresh() upstream:
	 * launchGenerateManagerSummary() (the only entry point) always calls it before launch(),
	 * and it is date-aware. This static pre-check has neither the recipient userId nor the
	 * reference date, so a now-based check here would drift from that decision. Hence SUCCESS
	 * always relaunches by reusing the single row; PENDING and ERROR branches stay unchanged.
	 */
	protected static function checkPreviousJobs(ItemIdentifier $target, int $parentId): Main\Result
	{
		$result = new Main\Result();

		$job = self::findLastJob($target);
		if (!$job)
		{
			return $result;
		}

		$status = $job->requireExecutionStatus();

		if ($status === QueueTable::EXECUTION_STATUS_PENDING)
		{
			if (self::isJobFresh($job))
			{
				// Fresh generation already running: silent no-op (Q-3), no new job, no message.
				return $result->addError(ErrorCode::getJobAlreadyExistsError());
			}

			// Stuck PENDING treated as dead: relaunch by updating the existing row.
			return $result->setData(['previousJob' => $job]);
		}

		if ($status === QueueTable::EXECUTION_STATUS_SUCCESS)
		{
			// Cache-hit vs. regenerate is decided by deliverCachedIfFresh() upstream (date-aware).
			// If we reach here, a fresh generation is wanted: reuse the single row.
			return $result->setData(['previousJob' => $job]);
		}

		// ERROR
		if ($job->requireRetryCount() >= Result::MAX_RETRY_COUNT)
		{
			return $result->addError(ErrorCode::getJobMaxRetriesExceededError());
		}

		return $result->setData(['previousJob' => $job]);
	}

	/**
	 * Re-delivers the previously generated summary when the manager's data has not changed
	 * since it was produced, without launching a new AI job. Returns true when the cached
	 * result is authoritative (caller must skip launch), false when a fresh generation is needed.
	 */
	public static function deliverCachedIfFresh(int $managerId, int $userId, ?Main\Type\DateTime $referenceDate = null): bool
	{
		if ($managerId <= 0 || $userId <= 0)
		{
			return false;
		}

		$target = new ItemIdentifier(CCrmOwnerType::CopilotCallAssessment, $managerId);

		$job = self::findLastJob($target);
		if (
			!$job
			|| $job->requireExecutionStatus() !== QueueTable::EXECUTION_STATUS_SUCCESS
			|| !self::isCachedResultReusable($job, $target, $referenceDate)
		)
		{
			return false;
		}

		$payload = self::constructPayload((string)$job->getResult());
		if (!$payload instanceof ManagerSummaryPayload)
		{
			return false;
		}

		$context = self::buildRenderContext($managerId, $payload);
		$message = (new ManagerSummaryFormatter())->format($payload, $context);
		if ($message === '')
		{
			return false;
		}

		(new CallScoringV2SummaryBot())->deliverManagerSummary($userId, $message);

		return true;
	}

	private static function findLastJob(ItemIdentifier $target): ?EO_Queue
	{
		return QueueTable::query()
			->setSelect(['ID', 'EXECUTION_STATUS', 'RETRY_COUNT', 'CREATED_TIME', 'RESULT', 'ENTITY_ID', 'ENTITY_TYPE_ID'])
			->where('ENTITY_TYPE_ID', $target->getEntityTypeId())
			->where('ENTITY_ID', $target->getEntityId())
			->where('TYPE_ID', self::TYPE_ID)
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->fetchObject()
		;
	}

	private static function isJobFresh(EO_Queue $job): bool
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

	private static function isCachedResultReusable(EO_Queue $job, ItemIdentifier $target, ?Main\Type\DateTime $referenceDate = null): bool
	{
		$storedSignature = self::extractSignatureFromResult((string)$job->getResult());
		if ($storedSignature === null)
		{
			// Legacy/empty result without a stored signature: cannot trust the cache.
			return false;
		}

		$currentSignature = (new ManagerSummaryDataProvider())->countAssessedCalls($target->getEntityId(), $referenceDate);

		return $storedSignature === $currentSignature;
	}

	private static function extractSignatureFromResult(string $result): ?int
	{
		if ($result === '')
		{
			return null;
		}

		try
		{
			$decoded = Main\Web\Json::decode($result);
		}
		catch (Main\ArgumentException)
		{
			return null;
		}

		if (!is_array($decoded) || !array_key_exists('sourceSignature', $decoded) || !is_int($decoded['sourceSignature']))
		{
			return null;
		}

		return $decoded['sourceSignature'];
	}

	protected function getAIPayload(): Main\Result
	{
		$input = (new ManagerSummaryDataProvider())->buildInput($this->target->getEntityId(), $this->referenceDate);

		return PayloadFactory::build(self::TYPE_ID, $this->userId, $this->target)
			->setAdditionalData($input)
			->setMarkers([])
			->getResult()
		;
	}

	protected function getJobAddFields(): array
	{
		return ['RESULT' => $this->buildLaunchResult()] + parent::getJobAddFields();
	}

	protected function getJobUpdateFields(): array
	{
		return ['RESULT' => $this->buildLaunchResult()] + parent::getJobUpdateFields();
	}

	/**
	 * Seeds b_crm_ai_queue.RESULT (TextField) at launch with the data window [D-7d, D] the summary
	 * will describe, where D is the reference date the button was shown (else now). The asynchronous
	 * finish has no reference date, so extractPayloadFromAIResult() reads this value back into the
	 * payload before onQueueJobExecute overwrites RESULT with the final payload (abstractoperation
	 * setResult()). Safe to write here: the row is/goes PENDING and the SUCCESS cache is untouched.
	 */
	private function buildLaunchResult(): string
	{
		$reference = $this->referenceDate ?? new Main\Type\DateTime();
		$to = $reference->getTimestamp();
		$from = (clone $reference)
			->add('-' . ManagerSummaryDataProvider::WINDOW_DAYS . ' days')
			->getTimestamp()
		;

		return Main\Web\Json::encode(['periodFrom' => $from, 'periodTo' => $to]);
	}

	// region notify
	protected static function notifyTimelineAfterSuccessfulLaunch(Result $result): void
	{
	}

	protected static function notifyTimelineAfterSuccessfulJobFinish(Result $result): void
	{
	}

	protected static function notifyAboutJobError(
		Result $result,
		bool $withSyncBadges = true,
		bool $withSendAnalytics = true,
		?ItemIdentifier $target = null,
	): void
	{
		AIManager::logger()->error(
			'{date}: {class}: Generate manager summary job error on target: {target}' . PHP_EOL,
			['class' => self::class, 'target' => $result->getTarget()],
		);

		// Only report genuine finished-job failures to the chat (jobId set); launch-time
		// pre-check refusals (no job yet) stay a silent no-op so retry stays possible.
		if ((int)$result->getJobId() > 0)
		{
			$userId = (int)$result->getUserId();
			if ($userId > 0)
			{
				(new CallScoringV2SummaryBot())->notifyManagerSummaryError($userId);
			}
		}
	}
	// endregion

	protected static function extractPayloadFromAIResult(\Bitrix\AI\Result $result, EO_Queue $job): Dto
	{
		$json = self::extractPayloadPrettifiedData($result);

		// Capture the data signature this summary is based on so a later click can decide
		// between re-delivering the cache and generating anew (see deliverCachedIfFresh).
		// Deliberately now-based: the asynchronous finish has no reference date. The AI-input
		// window and the launch-time dedup are the parts pinned to the message date; this
		// finish signature reflects the data as of when the job actually completed.
		$signature = (new ManagerSummaryDataProvider())->countAssessedCalls((int)$job->getEntityId());

		// Read the launch-seeded data window back before setResult() overwrites RESULT (see
		// buildLaunchResult): the finish has no reference date, so the period lives only here.
		$period = self::extractLaunchPeriod($job);

		if (empty($json))
		{
			return new ManagerSummaryPayload(['sourceSignature' => $signature] + $period);
		}

		return new ManagerSummaryPayload([
			'perScript' => self::parsePerScript($json['per_script'] ?? null),
			'crossScriptPatterns' => self::parseCrossScriptPatterns($json['cross_script_patterns'] ?? null),
			'recommendations' => self::parseRecommendations($json['recommendations'] ?? null),
			'summary' => is_string($json['summary'] ?? null) ? $json['summary'] : '',
			'sourceSignature' => $signature,
		] + $period);
	}

	/**
	 * Reads the launch-seeded data-window bounds from the job's RESULT. At this point in
	 * onQueueJobExecute the job (loaded with select ['*'] in EventHandler) still carries the
	 * launch value - setResult() runs later - so no reload is needed. Legacy/empty RESULT gives
	 * null bounds.
	 *
	 * @return array{periodFrom: ?int, periodTo: ?int}
	 */
	private static function extractLaunchPeriod(EO_Queue $job): array
	{
		$empty = ['periodFrom' => null, 'periodTo' => null];

		$raw = (string)$job->getResult();
		if ($raw === '')
		{
			return $empty;
		}

		try
		{
			$decoded = Main\Web\Json::decode($raw);
		}
		catch (Main\ArgumentException)
		{
			return $empty;
		}

		if (!is_array($decoded))
		{
			return $empty;
		}

		return [
			'periodFrom' => isset($decoded['periodFrom']) ? (int)$decoded['periodFrom'] : null,
			'periodTo' => isset($decoded['periodTo']) ? (int)$decoded['periodTo'] : null,
		];
	}

	/**
	 * Assembles the DB/Container-derived render inputs (manager display name, script id -> title,
	 * period bounds) so ManagerSummaryFormatter stays side-effect free.
	 */
	private static function buildRenderContext(int $managerId, ManagerSummaryPayload $payload): ManagerSummaryRenderContext
	{
		return new ManagerSummaryRenderContext(
			$managerId,
			(string)(Container::getInstance()->getUserBroker()->getName($managerId) ?? ''),
			self::resolveScriptNames($payload),
			self::resolveCriterionNames($payload),
			$payload->periodFrom,
			$payload->periodTo,
		);
	}

	/**
	 * Resolves the criterion definition ids referenced anywhere in the payload to their current
	 * TITLE (CriteriaLoader). Criteria are loaded for every script id the payload mentions
	 * (per_script, recommendations, cross_script_patterns) and flattened into a single id -> title
	 * map. Empty/missing titles are omitted so the formatter can degrade to an unlabeled comment.
	 *
	 * @return array<int, string>
	 */
	private static function resolveCriterionNames(ManagerSummaryPayload $payload): array
	{
		$scriptIds = self::collectScriptIds($payload);
		if ($scriptIds === [])
		{
			return [];
		}

		$names = [];
		foreach ((new CriteriaLoader())->loadForAssessments($scriptIds) as $criteria)
		{
			foreach ($criteria as $criterion)
			{
				$title = trim((string)($criterion['title'] ?? ''));
				if ($title !== '')
				{
					$names[(int)$criterion['id']] = $title;
				}
			}
		}

		return $names;
	}

	/**
	 * Resolves the script assessment-setting ids referenced anywhere in the payload to their current
	 * TITLE (CopilotCallAssessmentTable). Ids are collected across per_script, recommendations and
	 * cross_script_patterns so the "relates to: scripts" line stays symmetric with criterion coverage.
	 * Empty/missing titles are omitted so the formatter can fall back to a generic script header;
	 * the extra ids beyond per_script are harmless (unused map entries).
	 *
	 * @return array<int, string>
	 */
	private static function resolveScriptNames(ManagerSummaryPayload $payload): array
	{
		$ids = self::collectScriptIds($payload);
		if ($ids === [])
		{
			return [];
		}

		$names = [];
		$rows = CopilotCallAssessmentTable::query()
			->setSelect(['ID', 'TITLE'])
			->whereIn('ID', $ids)
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			$title = trim((string)($row['TITLE'] ?? ''));
			if ($title !== '')
			{
				$names[(int)$row['ID']] = $title;
			}
		}

		return $names;
	}

	/**
	 * Unique positive script ids referenced anywhere in the payload: per_script rows plus the
	 * scriptIds of recommendations and cross_script_patterns. Single source so script-name and
	 * criterion-name resolution cover the exact same set of scripts.
	 *
	 * @return array<int, int>
	 */
	private static function collectScriptIds(ManagerSummaryPayload $payload): array
	{
		$scriptIds = [];
		foreach ($payload->perScript as $row)
		{
			if (is_array($row))
			{
				$scriptIds[] = (int)($row['scriptId'] ?? 0);
			}
		}
		foreach ([...$payload->recommendations, ...$payload->crossScriptPatterns] as $row)
		{
			if (!is_array($row))
			{
				continue;
			}

			foreach ((array)($row['scriptIds'] ?? []) as $scriptId)
			{
				$scriptIds[] = (int)$scriptId;
			}
		}

		return array_values(array_unique(array_filter($scriptIds, static fn(int $id): bool => $id > 0)));
	}

	/**
	 * @return array<int, array{scriptId: string, strengths: array, growthAreas: array}>
	 */
	private static function parsePerScript(mixed $rows): array
	{
		if (!is_array($rows))
		{
			return [];
		}

		$result = [];
		foreach ($rows as $row)
		{
			if (!is_array($row))
			{
				continue;
			}

			$result[] = [
				'scriptId' => (string)($row['script_id'] ?? ''),
				'strengths' => self::parseCriterionComments($row['strengths'] ?? null),
				'growthAreas' => self::parseCriterionComments($row['growth_areas'] ?? null),
			];
		}

		return $result;
	}

	/**
	 * @return array<int, array{criterionId: string, comment: string}>
	 */
	private static function parseCriterionComments(mixed $rows): array
	{
		if (!is_array($rows))
		{
			return [];
		}

		$result = [];
		foreach ($rows as $row)
		{
			if (!is_array($row))
			{
				continue;
			}

			$result[] = [
				'criterionId' => (string)($row['criterion_id'] ?? ''),
				'comment' => (string)($row['comment'] ?? ''),
			];
		}

		return $result;
	}

	/**
	 * @return array<int, array{scriptIds: string[], criterionIds: string[], comment: string}>
	 */
	private static function parseCrossScriptPatterns(mixed $rows): array
	{
		if (!is_array($rows))
		{
			return [];
		}

		$result = [];
		foreach ($rows as $row)
		{
			if (!is_array($row))
			{
				continue;
			}

			$result[] = [
				'scriptIds' => self::toStringList($row['script_ids'] ?? null),
				'criterionIds' => self::toStringList($row['criterion_ids'] ?? null),
				'comment' => (string)($row['comment'] ?? ''),
			];
		}

		return $result;
	}

	/**
	 * @return array<int, array{priority: int, scriptIds: string[], criterionIds: string[], text: string, example: string}>
	 */
	private static function parseRecommendations(mixed $rows): array
	{
		if (!is_array($rows))
		{
			return [];
		}

		$result = [];
		foreach ($rows as $row)
		{
			if (!is_array($row))
			{
				continue;
			}

			$result[] = [
				'priority' => (int)($row['priority'] ?? 0),
				'scriptIds' => self::toStringList($row['script_ids'] ?? null),
				'criterionIds' => self::toStringList($row['criterion_ids'] ?? null),
				'text' => (string)($row['text'] ?? ''),
				'example' => (string)($row['example'] ?? ''),
			];
		}

		return $result;
	}

	/**
	 * @return string[]
	 */
	private static function toStringList(mixed $value): array
	{
		if (!is_array($value))
		{
			return [];
		}

		return array_values(array_map(static fn($item) => (string)$item, $value));
	}

	protected static function getJobFinishEventBuilder(): GenerateManagerSummaryEvent
	{
		return new GenerateManagerSummaryEvent();
	}

	protected static function onAfterSuccessfulJobFinish(Result $result, ?Context $context = null): void
	{
		if (!$result->isSuccess())
		{
			return;
		}

		// Delivery target is the recipient who clicked the button: the situational message lives in a
		// 1:1 private bot dialog whose id equals that user id, so userId identifies the same dialog.
		$userId = (int)$result->getUserId();
		if ($userId <= 0)
		{
			return;
		}

		$payload = $result->getPayload();
		if (!$payload instanceof ManagerSummaryPayload)
		{
			return;
		}

		$managerId = (int)($result->getTarget()?->getEntityId() ?? 0);
		$context = self::buildRenderContext($managerId, $payload);
		$message = (new ManagerSummaryFormatter())->format($payload, $context);
		if ($message === '')
		{
			return;
		}

		(new CallScoringV2SummaryBot())->deliverManagerSummary($userId, $message);
	}
}
