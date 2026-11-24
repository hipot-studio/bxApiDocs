<?php

namespace Bitrix\AI;

use Bitrix\AI\Cache\EngineResultCache;
use Bitrix\AI\Engine\Enum\Category;
use Bitrix\AI\Engine\IEngine;
use Bitrix\AI\Engine\IQueue;
use Bitrix\AI\Engine\ThirdParty;
use Bitrix\AI\Engine\ResponseFormat;
use Bitrix\AI\Facade\Analytics;
use Bitrix\AI\Facade\User;
use Bitrix\AI\History\Manager;
use Bitrix\AI\Limiter\LimitControlService;
use Bitrix\AI\Model\MonthlyPoolReceiptTable;
use Bitrix\AI\Model\QueueTable;
use Bitrix\AI\Payload\IPayload;
use Bitrix\AI\Role\RoleManager;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Security\Random;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

final class QueueJob
{
	private const MONTHLY_REFUND_CODE = 'ai:monthly-refund';
	private const EVENT_SUCCESS = 'onQueueJobExecute';
	private const EVENT_FAIL = 'onQueueJobFail';
	private const HISTORY_WRITE_ENABLED = 'Y';
	private const HISTORY_WRITE_DISABLED = 'N';
	private const HISTORY_WRITE_CLAIMED_ENABLED = 'A';
	private const HISTORY_WRITE_CLAIMED_DISABLED = 'B';
	private const HISTORY_WRITE_FINALIZING_ENABLED = 'C';
	private const HISTORY_WRITE_FINALIZING_DISABLED = 'D';
	private const HISTORY_WRITE_REFUND_ENABLED = 'R';
	private const HISTORY_WRITE_REFUND_DISABLED = 'S';
	private const HISTORY_WRITE_REOPENED_ENABLED = 'E';
	private const HISTORY_WRITE_REOPENED_DISABLED = 'F';
	private const HISTORY_WRITE_REPEAT_CLAIMED_ENABLED = 'G';
	private const HISTORY_WRITE_REPEAT_CLAIMED_DISABLED = 'H';
	private const HISTORY_WRITE_REPEAT_PENDING_ENABLED = 'I';
	private const HISTORY_WRITE_REPEAT_PENDING_DISABLED = 'J';

	public const ERROR_EXECUTE = 'EXECUTE_ERROR';
	public const ERROR_INVALID_JSON = 'INVALID_JSON';
	public const ERROR_FAIL_PROCESSING = 'FAIL_PROCESSING_ERROR';

	public const DEFAULT_TTL = 14400;
	public const MAX_TTL = 86400;
	private const CALLBACK_CLAIM_TTL = 900;
	private const CALLBACK_PATH = '/bitrix/services/main/ajax.php?action=ai.api.queue.callbackBody&hash={hash}&rid={hash}';
	private const CLOUD_CALLBACK_PATH = '/bitrix/services/main/ajax.php?action=ai.controller.integration.b24cloudai.callbackSuccess&hash={hash}&rid={hash}';
	private const THIRDPARTY_CALLBACK_PATH = '/bitrix/services/main/ajax.php?action=ai.controller.integration.thirdparty.callbackSuccess&hash={hash}&rid={hash}';
	private const CALLBACK_ERROR_PATH = '/bitrix/services/main/ajax.php?action=ai.api.queue.callbackError&hash={hash}&rid={hash}';
	private const CLOUD_CALLBACK_ERROR_PATH = '/bitrix/services/main/ajax.php?action=ai.controller.integration.b24cloudai.callbackError&hash={hash}&rid={hash}';
	private const THIRDPARTY_CALLBACK_ERROR_PATH = '/bitrix/services/main/ajax.php?action=ai.controller.integration.thirdparty.callbackError&hash={hash}&rid={hash}';

	private ?int $id = null;
	private ?string $hash = null;
	private ?string $cacheHash = null;
	private ?Error $error = null;
	private bool $apiRequestCompleted = true;
	/** Whether this instance took over a row created by an earlier attempt. */
	private bool $reusedRow = false;
	private bool $repeatedRequestStarted = false;
	private bool $repeatedRequestPending = false;
	private string $historyWriteState = self::HISTORY_WRITE_DISABLED;
	private ?DateTime $stateExpireDate = null;
	private int $ttl = self::DEFAULT_TTL;
	private Context $context;
	private IEngine $engine;
	protected LimitControlService $limitControlService;

	private function __construct() {}

	/**
	 * Agent. Clears all expired jobs, returns agent's full name.
	 *
	 * @return string
	 */
	public static function clearOldAgent(): string
	{
		$limit = 100;

		// Rows can have EXPIRE_DATE = NULL (rolling deploy against an older writer,
		// or an interrupted updater backfill), so a fallback on DATE_CREATE is required
		// to still catch and clean up those rows.
		$res = QueueTable::query()
			->setSelect(['HASH'])
			->where(
				Query::filter()
					->logic('or')
					->where('EXPIRE_DATE', '<', new DateTime())
					->where(
						Query::filter()
							->logic('and')
							->whereNull('EXPIRE_DATE')
							->where('DATE_CREATE', '<', DateTime::createFromTimestamp(time() - self::DEFAULT_TTL))
					)
			)
			->setOrder('ID')
			->setLimit($limit)
			->exec();

		// Expired finalizations repeat only a durable, idempotent monthly receipt refund.
		// Other terminal side effects must not be repeated.
		while ($row = $res->fetch())
		{
			self::createFromHash($row['HASH']);
		}

		self::clearOldMonthlyPoolReceipts($limit);

		return __CLASS__ . '::' . __FUNCTION__ . '();';
	}

	private static function clearOldMonthlyPoolReceipts(int $limit): void
	{
		try
		{
			if (!Application::getConnection()->isTableExists(MonthlyPoolReceiptTable::getTableName()))
			{
				return;
			}
			$cutoff = (new \DateTimeImmutable('first day of this month'))->modify('-3 months');
			(new Limiter\Repository\MonthlyPoolRepository())->cleanupReceiptsBefore(
				$cutoff->format('Y-m'),
				$limit,
			);
		}
		catch (\Throwable $exception)
		{
			try
			{
				(new \Bitrix\Main\Diag\LoggerFactory())
					->createById('ai.limiter.shared_monthly_pool')
					?->error('Unable to clean up old monthly pool receipts.', ['exception' => $exception])
				;
			}
			catch (\Throwable)
			{
			}
		}
	}

	/**
	 * Creates Queue Job instance within from Engine.
	 *
	 * @param IEngine $engine Engine instance.
	 * @param int|null $ttl Job lifetime in seconds, from 1 to MAX_TTL; null takes the Engine's
	 *   getQueueJobTtl() if it has one, otherwise DEFAULT_TTL.
	 * @return self
	 * @throws \InvalidArgumentException If $ttl is out of range.
	 */
	public static function createWithinFromEngine(IEngine $engine, ?int $ttl = null): self
	{
		if ($ttl !== null && ($ttl <= 0 || $ttl > self::MAX_TTL))
		{
			throw new \InvalidArgumentException(
				sprintf('Queue job TTL must be between 1 and %d seconds', self::MAX_TTL)
			);
		}

		$self = new self();
		$self->engine = $engine;
		$self->ttl = $ttl ?? (
			method_exists($engine, 'getQueueJobTtl') ? $engine->getQueueJobTtl() : self::DEFAULT_TTL
		);

		return $self;
	}

	/**
	 * Whether a queue row is expired: by EXPIRE_DATE, or (when EXPIRE_DATE is missing —
	 * e.g. a row written before the column existed) by DATE_CREATE + DEFAULT_TTL as a fallback.
	 *
	 * @param DateTime|null $expireDate Row's EXPIRE_DATE, if set.
	 * @param DateTime $dateCreate Row's DATE_CREATE.
	 * @return bool
	 */
	private static function isRowExpired(?DateTime $expireDate, DateTime $dateCreate): bool
	{
		return $expireDate !== null
			? $expireDate < new DateTime()
			: $dateCreate < DateTime::createFromTimestamp(time() - self::DEFAULT_TTL);
	}

	private static function isClaimedHistoryWriteState(string $state): bool
	{
		return in_array(
			$state,
			[
				self::HISTORY_WRITE_CLAIMED_ENABLED,
				self::HISTORY_WRITE_CLAIMED_DISABLED,
				self::HISTORY_WRITE_REPEAT_CLAIMED_ENABLED,
				self::HISTORY_WRITE_REPEAT_CLAIMED_DISABLED,
			],
			true,
		);
	}

	private static function isFinalizingHistoryWriteState(string $state): bool
	{
		return in_array(
			$state,
			[
				self::HISTORY_WRITE_FINALIZING_ENABLED, self::HISTORY_WRITE_FINALIZING_DISABLED,
				self::HISTORY_WRITE_REFUND_ENABLED, self::HISTORY_WRITE_REFUND_DISABLED,
			],
			true,
		);
	}

	private static function isReopenedHistoryWriteState(string $state): bool
	{
		return in_array(
			$state,
			[self::HISTORY_WRITE_REOPENED_ENABLED, self::HISTORY_WRITE_REOPENED_DISABLED],
			true,
		);
	}

	private static function isRepeatPendingHistoryWriteState(string $state): bool
	{
		return in_array(
			$state,
			[self::HISTORY_WRITE_REPEAT_PENDING_ENABLED, self::HISTORY_WRITE_REPEAT_PENDING_DISABLED],
			true,
		);
	}

	private static function incrementDate(?DateTime $date): DateTime
	{
		$timestamp = max(
			($date?->getTimestamp() ?? 0) + 1,
			time() + self::CALLBACK_CLAIM_TTL,
		);

		return DateTime::createFromTimestamp($timestamp);
	}

	private static function isHistoryWriteEnabled(string $state): bool
	{
		return in_array(
			$state,
			[
				self::HISTORY_WRITE_ENABLED,
				self::HISTORY_WRITE_CLAIMED_ENABLED,
				self::HISTORY_WRITE_FINALIZING_ENABLED,
				self::HISTORY_WRITE_REFUND_ENABLED,
				self::HISTORY_WRITE_REOPENED_ENABLED,
				self::HISTORY_WRITE_REPEAT_CLAIMED_ENABLED,
				self::HISTORY_WRITE_REPEAT_PENDING_ENABLED,
			],
			true,
		);
	}

	/**
	 * Job data with the parameters that belong to the charge rather than to the request.
	 *
	 * The limit is spent before the queue is reached, and a BAAS charge hands back an id of its
	 * own each time. Left in, that id makes every attempt look like a different request — no
	 * repeat is ever recognised, and the result cache never hits either, because this same
	 * fingerprint is its key. The id stays in the stored row: the failure paths roll back by it.
	 *
	 * @param array $data Job data as it will be stored.
	 * @return array Same data, fit to be fingerprinted.
	 */
	private static function withoutPerChargeParameters(array $data): array
	{
		unset($data['PARAMETERS'][Engine\Engine::PARAM_CONSUMPTION_ID]);

		return $data;
	}

	/**
	 * Latest unfinished and unexpired job with the same request fingerprint, if there is one.
	 *
	 * Being unfinished is expressed by the row's existence outside finalization: a finished job
	 * is deleted, and a finalizing one never accepts a callback again and is deleted right after
	 * its terminal events. A repeat joining it — sent from a listener of those events or
	 * concurrently — would keep the rid of a request whose result nothing will accept anymore.
	 * The expiry rule of isRowExpired() is repeated here as a condition of the query rather
	 * than applied to fetched rows — one fingerprint accumulates expired rows that the cleanup
	 * agent has not reached yet, and TTL is per job, so an alive row with a long TTL can sit
	 * arbitrarily far down the list. Whenever isRowExpired() changes, this condition changes
	 * with it.
	 *
	 * Alive rows are walked newest first until the wire body matches. Taking only the newest
	 * one would miss a fit: a single job fingerprint legitimately holds several alive rows —
	 * the body carries context the job data never sees — so bodies A and B alternating inside
	 * one TTL would each keep creating new jobs. The walk streams rows and stops at the first
	 * match, which for the ordinary case is the first row fetched.
	 *
	 * @param string $cacheHash Fingerprint of the job.
	 * @param string $requestHash Fingerprint of the body as it goes on the wire. A row whose
	 *   stored fingerprint differs is not a repeat, however well the job fingerprints match.
	 * @return array|null
	 */
	private static function findAliveRowByCacheHash(string $cacheHash, string $requestHash): ?array
	{
		$rows = QueueTable::query()
			->setSelect(['ID', 'HASH', 'HISTORY_WRITE', 'ENGINE_CUSTOM_SETTINGS', 'EXPIRE_DATE'])
			->where('CACHE_HASH', $cacheHash)
			->whereNotIn(
				'HISTORY_WRITE',
				[
					self::HISTORY_WRITE_FINALIZING_ENABLED, self::HISTORY_WRITE_FINALIZING_DISABLED,
					self::HISTORY_WRITE_REFUND_ENABLED, self::HISTORY_WRITE_REFUND_DISABLED,
				],
			)
			->where(
				Query::filter()
					->logic('or')
					->where('EXPIRE_DATE', '>', new DateTime())
					->where(
						Query::filter()
							->logic('and')
							->whereNull('EXPIRE_DATE')
							->where('DATE_CREATE', '>', DateTime::createFromTimestamp(time() - self::DEFAULT_TTL))
					)
			)
			->setOrder(['ID' => 'DESC'])
			->exec();

		// The fingerprint of the stored data is not the fingerprint of the wire body: context
		// messages are collected by an event at send time and never reach the row. Keeping the
		// identity of a request whose body has since changed is worse than not keeping it —
		// the receiver answers such a repeat with a conflict, and the request fails outright.
		while ($row = $rows->fetch())
		{
			if (($row['ENGINE_CUSTOM_SETTINGS']['REQUEST_HASH'] ?? null) === $requestHash)
			{
				return $row;
			}
		}

		return null;
	}

	/** Persists a tracked refund for the existing cleanup agent without submitting an AI request. */
	public static function deferMonthlyRefund(Limiter\SharedMonthlyReservation $reservation): string
	{
		if (!$reservation->isTracked())
		{
			throw new \InvalidArgumentException('Only a tracked monthly reservation can be deferred.');
		}
		$service = \Bitrix\Main\DI\ServiceLocator::getInstance()->get(Limiter\SharedMonthlyPoolService::class);
		$stored = $service->findReservation($reservation->getId(), $reservation->getUserId());
		if (
			$stored === null
			|| $stored->getPeriod() !== $reservation->getPeriod()
			|| $stored->getCost() !== $reservation->getCost()
		)
		{
			throw new \InvalidArgumentException('The deferred monthly reservation does not match its receipt.');
		}

		return self::persistMonthlyRefundIdentity($reservation->getId(), $reservation->getUserId());
	}

	private static function persistMonthlyRefundIdentity(string $receiptId, int $userId): string
	{
		if (
			$userId <= 0
			|| !preg_match(
				'/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i',
				$receiptId,
			)
		)
		{
			throw new \InvalidArgumentException('Invalid deferred monthly receipt identity.');
		}

		$service = \Bitrix\Main\DI\ServiceLocator::getInstance()->get(Limiter\SharedMonthlyPoolService::class);

		return $service->persistReservationRefund($receiptId, $userId, static function () use ($receiptId, $userId): string {
			$hash = QueueTable::generateHash();
			$result = QueueTable::add([
				'HASH' => $hash,
				'ENGINE_CLASS' => self::class,
				'ENGINE_CODE' => self::MONTHLY_REFUND_CODE,
				'PAYLOAD_CLASS' => self::class,
				'PAYLOAD' => '-',
				'CACHE_HASH' => $hash,
				'PARAMETERS' => ['receiptId' => $receiptId, 'userId' => $userId],
				'HISTORY_WRITE' => self::HISTORY_WRITE_REFUND_DISABLED,
				'DATE_CREATE' => new DateTime(),
				'EXPIRE_DATE' => DateTime::createFromTimestamp(time() - 1),
			]);
			if (!$result->isSuccess())
			{
				throw new \RuntimeException('Unable to persist the deferred monthly AI refund.');
			}

			return $hash;
		});
	}

	private static function retryDeferredMonthlyRefund(array $row): void
	{
		try
		{
			$service = \Bitrix\Main\DI\ServiceLocator::getInstance()->get(Limiter\SharedMonthlyPoolService::class);
			$reservation = $service->findReservation(
				(string)($row['PARAMETERS']['receiptId'] ?? ''),
				(int)($row['PARAMETERS']['userId'] ?? 0),
			);
			if ($reservation !== null)
			{
				$service->releaseReservation($reservation);
			}
			QueueTable::delete((int)$row['ID']);
		}
		catch (\Throwable)
		{
			// The persisted receipt stays available to the next cleanup-agent run.
		}
	}

	/**
	 * Creates Queue Job object by hash, and return it (if exists).
	 *
	 * An expired job is failed and removed instead of being returned, unless $allowExpired
	 * is set — success callbacks pass true so a valid late result is still delivered while
	 * the row is alive (the work is done and consumption is spent).
	 *
	 * @param string $hash Queue job hash.
	 * @param bool $allowExpired Return the job even if its TTL has passed.
	 * @return static|null
	 */
	public static function createFromHash(string $hash, bool $allowExpired = false): ?self
	{
		$row = QueueTable::query()
			->setSelect(['*'])
			->setFilter(['=HASH' => $hash])
			->setLimit(1)
			->fetch()
		;
		if ($row)
		{
			if (
				$row['ENGINE_CLASS'] === self::class
				&& $row['ENGINE_CODE'] === self::MONTHLY_REFUND_CODE
				&& $row['HISTORY_WRITE'] === self::HISTORY_WRITE_REFUND_DISABLED
			)
			{
				self::retryDeferredMonthlyRefund($row);

				return null;
			}
			$historyWriteState = (string)($row['HISTORY_WRITE'] ?? self::HISTORY_WRITE_DISABLED);
			$rowExpired = self::isRowExpired($row['EXPIRE_DATE'], $row['DATE_CREATE']);

			/** @var IPayload $payloadClass */
			/** @var IEngine&IQueue $engineClass */

			$payloadClass = $row['PAYLOAD_CLASS'];
			$payload = $payloadClass::unpack($row['PAYLOAD']);
			$context = null;
			$contextLoadError = null;
			try
			{
				$context = Context::unpack($row['CONTEXT']);
			}
			catch (\InvalidArgumentException $exception)
			{
				$contextLoadError = $exception->getMessage();
			}

			$engine = $context === null ? null : Engine::getByCode($row['ENGINE_CODE'], $context);
			if ($context === null || $engine === null || $payload === null)
			{
				Manager::writeDiagnosticHistory([
					'RESULT_TEXT' => '[QUEUE_JOB_LOAD_FAILED] hash=' . $hash
						. ', contextInvalid=' . ($contextLoadError !== null ? 'yes' : 'no')
						. ($contextLoadError !== null ? ', contextError=' . $contextLoadError : '')
						. ', engineNull=' . ($engine === null ? 'yes' : 'no')
						. ', payloadNull=' . ($payload === null ? 'yes' : 'no'),
					'CONTEXT_MODULE' => $context?->getModuleId() ?: 'ai',
					'CONTEXT_ID' => $context?->getContextId() ?: '-',
					'ENGINE_CODE' => $row['ENGINE_CODE'] ?? '',
					'PAYLOAD_CLASS' => $row['PAYLOAD_CLASS'] ?: '-',
					'CREATED_BY_ID' => $context?->getUserId() ?? 0,
				]);

				// A broken row cannot go through expire() (nothing to notify or refund),
				// so an expired one is removed right away — this lets clearOldAgent() rely
				// on this method without a second delete of its own.
				if (!$allowExpired && $rowExpired)
				{
					QueueTable::delete($row['ID']);
				}

				return null;
			}

			$engineCustomSettings = $row['ENGINE_CUSTOM_SETTINGS'] ?? [];
			if (isset($engineCustomSettings['JSON_RESPONSE_MODE']))
			{
				$engine->setResponseJsonMode((bool)$engineCustomSettings['JSON_RESPONSE_MODE']);
			}
			if (isset($engineCustomSettings['ANALYTIC_DATA']))
			{
				$engine->setAnalyticData($engineCustomSettings['ANALYTIC_DATA']);
			}
			if (isset($engineCustomSettings['SHOULD_SKIP_AGREEMENT']) && $engineCustomSettings['SHOULD_SKIP_AGREEMENT'])
			{
				$engine->skipAgreement();
			}
			if (!empty($engineCustomSettings['HIDDEN_TOKENS']) && \is_array($engineCustomSettings['HIDDEN_TOKENS']))
			{
				$payload->setProcessedReplacements($engineCustomSettings['HIDDEN_TOKENS']);
			}

			$engine->setPayload($payload);
			$engine->setParameters($row['PARAMETERS']);
			$engine->setHistoryState(self::isHistoryWriteEnabled($historyWriteState));
			$engine->setHistoryGroupId($row['HISTORY_GROUP_ID']);

			if (
				$engine->getCategory() === Category::VISION->value
				&& !empty($engineCustomSettings['IMAGES'])
				&& is_array($engineCustomSettings['IMAGES'])
			)
			{
				$images = array_filter(array_map(
					Image\ImageReference::fromStorable(...),
					$engineCustomSettings['IMAGES'],
				));
				$engine->setImages($images);
			}

			$queueJob = new self();

			$queueJob->id = $row['ID'];
			$queueJob->hash = $row['HASH'];
			$queueJob->cacheHash = $row['CACHE_HASH'];
			$queueJob->context = $context;
			$queueJob->engine = $engine->getIEngine();
			$queueJob->historyWriteState = $historyWriteState;
			$queueJob->stateExpireDate = $row['EXPIRE_DATE'];

			$expireDate = $row['EXPIRE_DATE'];
			if ($expireDate !== null)
			{
				$queueJob->ttl = $expireDate->getTimestamp() - $row['DATE_CREATE']->getTimestamp();
			}

			// Callback endpoints load a job purely by hash, so an expired-but-not-yet-cleaned-up
			// row must be rejected here too, not just by the periodic clearOldAgent() agent.
			// Success callbacks opt out via $allowExpired: a delivered valid result wins over TTL.
			if (!$allowExpired && $rowExpired)
			{
				if (self::isFinalizingHistoryWriteState($historyWriteState))
				{
					$queueJob->deleteExpiredFinalization();
				}
				elseif (self::isClaimedHistoryWriteState($historyWriteState))
				{
					$queueJob->expireClaimed();
				}
				else
				{
					$queueJob->expire();
				}

				return null;
			}

			return $queueJob;
		}

		Manager::writeDiagnosticHistory([
			'RESULT_TEXT' => '[QUEUE_JOB_NOT_FOUND] No queue record found for hash=' . $hash,
		]);

		return null;
	}

	/**
	 * Registers new Queue Job.
	 *
	 * @param array|null $requestParams Body of the request as it goes on the wire, save for the
	 *   parts derived from the job itself. Passing it is what allows a repeat to keep the
	 *   identity of the first attempt; without it every registration creates a new job, as before.
	 * @return self
	 */
	public function register(?array $requestParams = null): self
	{
		if ($this->hash)
		{
			return $this;
		}

		$this->historyWriteState = $this->engine->shouldWriteHistory()
			? self::HISTORY_WRITE_ENABLED
			: self::HISTORY_WRITE_DISABLED
		;
		$data = [
			'ENGINE_CLASS' => $this->engine::class,
			'ENGINE_CODE' => $this->engine->getCode(),
			'PAYLOAD_CLASS' => $this->engine->getPayload()::class,
			'PAYLOAD' => $this->engine->getPayload()->pack(),
			'CONTEXT' => $this->engine->getContext()->pack(),
			'PARAMETERS' => $this->engine->getParameters(),
			'HISTORY_WRITE' => $this->historyWriteState,
			'HISTORY_GROUP_ID' => $this->engine->getHistoryGroupId(),
			'ENGINE_CUSTOM_SETTINGS' => [
				'JSON_RESPONSE_MODE' => $this->engine->getResponseJsonMode(),
				'ANALYTIC_DATA' => $this->engine->getAnalyticData(),
				'SHOULD_SKIP_AGREEMENT' => $this->engine->shouldSkipAgreement(),
				'HIDDEN_TOKENS' => $this->engine->getPayload()->getTokenProcessor()->getReplacements(),
			],
		];
		if ($this->engine->getCategory() === Category::VISION->value)
		{
			$data['ENGINE_CUSTOM_SETTINGS']['IMAGES'] = array_map(
				fn(Image\ImageReference $img) => $img->toStorable(),
				$this->engine->getImages(),
			);
		}

		$cacheHash = md5(serialize(self::withoutPerChargeParameters($data)));

		// Kept out of $data on purpose: the cache hash is the fingerprint of the job, and adding
		// the wire body to it would change the result cache key of every engine at once.
		$requestHash = $requestParams === null ? null : md5(serialize($requestParams));

		$aliveRow = $requestHash === null ? null : self::findAliveRowByCacheHash($cacheHash, $requestHash);
		if ($aliveRow !== null)
		{
			// A repeat of the same request keeps the identity of the first attempt: the receiver
			// recognises the retry by rid, and rid is this hash.
			$this->id = (int)$aliveRow['ID'];
			$this->hash = $aliveRow['HASH'];
			$this->context = $this->engine->getContext();
			$this->cacheHash = $cacheHash;
			$this->reusedRow = true;
			$this->historyWriteState = (string)$aliveRow['HISTORY_WRITE'];
			$this->stateExpireDate = $aliveRow['EXPIRE_DATE'];

			// The limit was charged before the request got here, and this attempt turned out not
			// to be a request at all. The charge of the attempt that owns the row stays: it is
			// the one the failure paths will roll back.
			$usage = new Limiter\Usage($this->engine->getContext());
			$consumptionId = $this->engine->getConsumptionId();
			try
			{
				$this->getLimitControlService()->rollbackConsumption(
					$usage,
					$this->engine->getPayload()->getCost(),
					$consumptionId,
				);
			}
			catch (\Throwable $refundError)
			{
				if (!preg_match(
					'/\Aai:shared_monthly_pool:v1:([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}'
					. '-[89ab][0-9a-f]{3}-[0-9a-f]{12})\z/i',
					$consumptionId,
					$matches,
				))
				{
					throw $refundError;
				}
				try
				{
					self::persistMonthlyRefundIdentity($matches[1], $usage->getUserId());
				}
				catch (\Throwable $persistError)
				{
					throw new \RuntimeException(
						'Unable to defer repeated monthly AI refund ' . $consumptionId . ': '
						. $persistError->getMessage(),
						0,
						$refundError,
					);
				}
			}
			return $this;
		}

		$hash = QueueTable::generateHash();
		if ($requestHash !== null)
		{
			$data['ENGINE_CUSTOM_SETTINGS']['REQUEST_HASH'] = $requestHash;
		}
		$data['HASH'] = $hash;
		$data['CACHE_HASH'] = $cacheHash;
		$data['EXPIRE_DATE'] = DateTime::createFromTimestamp(time() + $this->ttl);

		$result = QueueTable::add($data);

		if ($result->isSuccess())
		{
			$this->id = $result->getId();
			$this->hash = $hash;
			$this->context = $this->engine->getContext();
			$this->cacheHash = $cacheHash;
			$this->stateExpireDate = $data['EXPIRE_DATE'];
		}
		else
		{
			throw new SystemException(implode(' ', $result->getErrorMessages()));
		}

		return $this;
	}

	/**
	 * Returns hash, if job was registered.
	 *
	 * @return string|null
	 */
	public function getHash(): ?string
	{
		return $this->hash;
	}

	/**
	 * Returns cache hash, if job was registered.
	 *
	 * @return string|null
	 */
	public function getCacheHash(): ?string
	{
		return $this->cacheHash;
	}

	/** Opens a state window in which a fast callback may take over the previous owner. */
	public function beginRepeatedRequest(): void
	{
		if (!$this->reusedRow || !$this->id)
		{
			return;
		}
		$this->repeatedRequestStarted = true;
		while (true)
		{
			if (self::isRepeatPendingHistoryWriteState($this->historyWriteState))
			{
				$this->repeatedRequestPending = true;

				return;
			}
			if (
				self::isFinalizingHistoryWriteState($this->historyWriteState)
				|| (
					!self::isClaimedHistoryWriteState($this->historyWriteState)
					&& !self::isReopenedHistoryWriteState($this->historyWriteState)
					&& !in_array(
						$this->historyWriteState,
						[self::HISTORY_WRITE_ENABLED, self::HISTORY_WRITE_DISABLED],
						true,
					)
				)
			)
			{
				return;
			}

			$pendingState = self::isHistoryWriteEnabled($this->historyWriteState)
				? self::HISTORY_WRITE_REPEAT_PENDING_ENABLED
				: self::HISTORY_WRITE_REPEAT_PENDING_DISABLED;
			if ($this->compareAndSetHistoryWriteState(
				$this->id,
				$this->historyWriteState,
				$pendingState,
				$this->stateExpireDate,
			))
			{
				$this->repeatedRequestPending = true;

				return;
			}

			$row = QueueTable::query()
				->setSelect(['HISTORY_WRITE', 'EXPIRE_DATE'])
				->where('ID', $this->id)
				->fetch();
			if (!$row)
			{
				return;
			}
			$this->historyWriteState = (string)$row['HISTORY_WRITE'];
			$this->stateExpireDate = $row['EXPIRE_DATE'];
		}
	}

	/**
	 * Releases the callback owner that existed before the repeated request started. A callback
	 * arriving during the request changes the pending state first and therefore keeps ownership.
	 */
	public function confirmRepeatedRequestSent(): void
	{
		if (!$this->repeatedRequestStarted || !$this->repeatedRequestPending || !$this->id)
		{
			return;
		}

		$pendingState = self::isHistoryWriteEnabled($this->historyWriteState)
			? self::HISTORY_WRITE_REPEAT_PENDING_ENABLED
			: self::HISTORY_WRITE_REPEAT_PENDING_DISABLED;
		$releasedState = self::isHistoryWriteEnabled($this->historyWriteState)
			? self::HISTORY_WRITE_REOPENED_ENABLED
			: self::HISTORY_WRITE_REOPENED_DISABLED;
		$releasedExpireDate = self::incrementDate($this->stateExpireDate);
		if ($this->compareAndSetState(
			$this->id,
			$pendingState,
			$releasedState,
			$this->stateExpireDate,
			$releasedExpireDate,
		))
		{
			$this->historyWriteState = $releasedState;
			$this->stateExpireDate = $releasedExpireDate;
		}
		$this->repeatedRequestPending = false;
	}

	/** Leaves the pending window available to the previous owner after a rejected request. */
	public function abortRepeatedRequest(): void
	{
		if (!$this->repeatedRequestStarted || !$this->repeatedRequestPending || !$this->id)
		{
			return;
		}

		$this->repeatedRequestPending = false;
	}

	/**
	 * Returns callback url for job. When job will complete, this url must receive result data.
	 *
	 * @return string
	 */
	public function getCallbackUrl(): string
	{
		if ($this->engine instanceof ThirdParty)
		{
			/** @see \Bitrix\AI\Controller\Integration\Thirdparty::callbackSuccessAction */
			return $this->getCallbackUrlTemplate(self::THIRDPARTY_CALLBACK_PATH);
		}

		if (!Loader::includeModule('bitrix24'))
		{
			return $this->getCallbackUrlTemplate(self::CLOUD_CALLBACK_PATH);
		}

		return $this->getCallbackUrlTemplate(self::CALLBACK_PATH);
	}

	/**
	 * Returns error callback url for job. If job will fail with error, this url must receive result data.
	 *
	 * @return string
	 */
	public function getErrorCallbackUrl(): string
	{
		if ($this->engine instanceof ThirdParty)
		{
			/** @see \Bitrix\AI\Controller\Integration\Thirdparty::callbackErrorAction */
			return $this->getCallbackUrlTemplate(self::THIRDPARTY_CALLBACK_ERROR_PATH);
		}

		if (!Loader::includeModule('bitrix24'))
		{
			return $this->getCallbackUrlTemplate(self::CLOUD_CALLBACK_ERROR_PATH);
		}

		return $this->getCallbackUrlTemplate(self::CALLBACK_ERROR_PATH);
	}

	/**
	 * Returns template url for callbacks.
	 *
	 * @param string $path Relative path.
	 * @return string
	 */
	private function getCallbackUrlTemplate(string $path): string
	{
		$url = Config::getValue('public_url');
		if ($url)
		{
			$url = rtrim(trim($url), '/');
		}
		else
		{
			$url = UrlManager::getInstance()->getHostUrl();
		}

		return str_replace('{hash}', $this->hash, $url . $path);
	}

	/**
	 * Executes Queue Job and removes Job: writes history, sends success events to backend
	 * and PULL listeners. An exception while processing the result goes to handleError() with ERROR_EXECUTE code.
	 *
	 * @param mixed $rawResult External raw result.
	 * @return void
	 */
	public function execute(mixed $rawResult): void
	{
		if (!$this->tryClaim())
		{
			return;
		}

		try
		{
			if ($this->engine->getPayload()->shouldUseCache())
			{
				$this->engine->setCache(true);
			}
			$cacheManager = new EngineResultCache($this->getCacheHash());
			if($this->engine->isCache() && !$cacheManager->getExists()){
				$cacheManager->store($rawResult);
			}

			$analyticData = $this->engine->getAnalyticData();
			$status = null;
			if (Analytics::isVoiceWidgetAudio($this->engine, $analyticData))
			{
				try
				{
					$status = Analytics::getVoiceWidgetResultStatus(
						$this->engine->getResultFromRaw($rawResult)->getPrettifiedData()
					);
				}
				catch (\Throwable)
				{
					$status = Analytics::VOICE_WIDGET_STATUS_ERROR_B24;
				}
			}

			$result = $this->engine->getResultFromRaw($rawResult);
		}
		catch (\Throwable $e)
		{
			if ($this->handleClaimedError($e->getMessage(), self::ERROR_EXECUTE))
			{
				$this->delete();
			}

			return;
		}
		if (!$this->tryFinalize())
		{
			return;
		}

		try
		{
			Analytics::engineGenerateResultEvent(
				'generate',
				$this->engine,
				$analyticData,
				$status
			);
		}
		catch (\Throwable)
		{
			// Best effort
		}

		try
		{
			$this->engine->writeHistory($result);
		}
		catch (\Throwable)
		{
			// Manager already attempted diagnostic write to b_ai_history
		}

		try
		{
			if ($this->engine->getPayload()->getRole() !== null)
			{
				$langCode = $this->context->getLanguage()?->getCode() ?? User::getUserLanguage();
				$roleManager = new RoleManager($this->context->getUserId(), $langCode);
				$roleManager->addRecentRole($this->engine->getPayload()->getRole());
			}
		}
		catch (\Throwable)
		{
			// Best effort
		}

		try
		{
			$this->sendBackendEvent($result, self::EVENT_SUCCESS);
		}
		catch (\Throwable)
		{
			// Best effort
		}

		try
		{
			$this->sendFrontendEvent($result, self::EVENT_SUCCESS);
		}
		catch (\Throwable)
		{
			// Best effort
		}

		$this->delete();
	}

	/**
	 * Fails Queue Job on a local processing error and removes Job: writes diagnostic history
	 * and sends fail events. Unlike fail(), consumption is not rolled back.
	 *
	 * @param string $message Error message.
	 * @param string $code Error code.
	 * @return void
	 */
	public function handleError(string $message, string $code = 'PROCESSING_ERROR'): void
	{
		if (!$this->tryClaim())
		{
			return;
		}

		if ($this->handleClaimedError($message, $code))
		{
			$this->delete();
		}
	}

	private function handleClaimedError(string $message, string $code, bool $refund = false): bool
	{
		if (!$this->tryFinalize($refund))
		{
			return false;
		}

		$this->writeDiagnosticError($message, $code);

		$result = new Result(null, null);
		try
		{
			$this->sendBackendEvent($result, self::EVENT_FAIL);
		}
		catch (\Throwable)
		{
			// Best effort
		}

		try
		{
			$this->sendFrontendEvent($result, self::EVENT_FAIL);
		}
		catch (\Throwable)
		{
			// Best effort
		}

		return true;
	}

	private function writeDiagnosticError(string $message, string $code): void
	{
		$this->error = new Error($message, $code);

		$data = [
			'RESULT_TEXT' => '[ERROR] ' . $code . ' ' . $message,
		];
		try
		{
			$context = $this->engine->getContext();
			$data += [
				'CONTEXT_MODULE' => $context->getModuleId(),
				'CONTEXT_ID' => $context->getContextId(),
				'ENGINE_CLASS' => $this->engine::class,
				'ENGINE_CODE' => $this->engine->getCode(),
				'PAYLOAD_CLASS' => $this->engine->getPayload()::class,
				'CREATED_BY_ID' => $context->getUserId(),
			];
		}
		catch (\Throwable)
		{
		}

		try
		{
			Manager::writeDiagnosticHistory($data);
		}
		catch (\Throwable)
		{
			// Best effort
		}
	}

	/**
	 * Fails Queue Job by provider's error and removes Job. Does nothing if the Job is already removed,
	 * for example by a concurrent callback or expiration. Listeners receive a localized
	 * AI_ENGINE_ERROR_PROVIDER or AI_ENGINE_ERROR_OTHER error instead of the raw one.
	 * Consumption is rolled back only if the provider reports `api_request_completed` as false.
	 *
	 * @param mixed $rawError External raw error: `message` (or `error`), `code`, `api_request_completed`.
	 * @return void
	 */
	public function fail(mixed $rawError): void
	{
		if (!$this->tryClaim())
		{
			return;
		}

		if (isset($rawError['api_request_completed']))
		{
			$this->apiRequestCompleted = (bool)$rawError['api_request_completed'];
		}

		try
		{
			$errorMessage = $rawError['message'] ?? null;
			if (!is_string($errorMessage) || $errorMessage === '')
			{
				$errorField = $rawError['error'] ?? null;
				$errorMessage = is_string($errorField) ? $errorField : 'Unknown Error';
			}
			$historyError = new Error($errorMessage, $rawError['code'] ?? '');

			$rawErrorCode = $rawError['code'] ?? null;
			$errorCode = (int)$rawErrorCode;

			Loc::loadLanguageFile(__DIR__ . '/Engine.php');

			if (
				$errorCode === 100
				|| $errorCode >= 500
				|| $rawErrorCode === Engine\Engine::ERROR_CODE_COULD_NOT_LOCK
			)
			{
				$this->error = new Error(Loc::getMessage('AI_ENGINE_ERROR_PROVIDER'), 'AI_ENGINE_ERROR_PROVIDER');
			}
			else
			{
				$this->error = new Error(Loc::getMessage('AI_ENGINE_ERROR_OTHER'),'AI_ENGINE_ERROR_OTHER');
			}

			$analyticData = $this->engine->getAnalyticData();
		}
		catch (\Throwable $e)
		{
			$finalized = $this->handleClaimedError(
				'Error processing failure callback: ' . $e->getMessage(),
				self::ERROR_FAIL_PROCESSING,
				!$this->apiRequestCompleted,
			);
			if ($finalized)
			{
				if ($this->rollbackClaimedConsumption())
				{
					$this->delete();
				}
			}

			return;
		}
		if (!$this->tryFinalize(!$this->apiRequestCompleted))
		{
			return;
		}

		$errorHistoryWritten = true;
		try
		{
			$this->engine->writeErrorInHistory($historyError);
		}
		catch (\Throwable $e)
		{
			$errorHistoryWritten = false;
			$this->writeDiagnosticError(
				'Error processing failure callback: ' . $e->getMessage(),
				self::ERROR_FAIL_PROCESSING,
				!$this->apiRequestCompleted,
			);
		}

		if ($errorHistoryWritten)
		{
			try
			{
				Analytics::sendVoiceWidgetAudioError(
					$this->engine,
					$analyticData,
					$this->error->getCode() === 'AI_ENGINE_ERROR_PROVIDER'
						? Analytics::VOICE_WIDGET_STATUS_ERROR_PROVIDER
						: Analytics::VOICE_WIDGET_STATUS_ERROR_B24,
				);
			}
			catch (\Throwable)
			{
				// Best effort
			}
		}

		$result = new Result(null, null);
		try
		{
			$this->sendBackendEvent($result, self::EVENT_FAIL);
		}
		catch (\Throwable)
		{
			// Best effort
		}

		try
		{
			$this->sendFrontendEvent($result, self::EVENT_FAIL);
		}
		catch (\Throwable)
		{
			// Best effort
		}

		if ($this->rollbackClaimedConsumption())
		{
			$this->delete();
		}
	}

	private function rollbackClaimedConsumption(): bool
	{
		if ($this->apiRequestCompleted)
		{
			return true;
		}

		$tracked = str_starts_with($this->engine->getConsumptionId(), 'ai:shared_monthly_pool:v1:');
		try
		{
			$this->getLimitControlService()->rollbackConsumption(
				new Limiter\Usage($this->engine->getContext()),
				$this->engine->getPayload()->getCost(),
				$this->engine->getConsumptionId()
			);
		}
		catch (\Throwable)
		{
			return !$tracked;
		}
		return true;
	}

	/**
	 * Returns TTL in seconds for each Queue Job.
	 *
	 * @return int
	 */
	public function getTTL(): int
	{
		return $this->ttl;
	}

	/**
	 * Fails Queue Job as expired, rolls back consumption and removes it.
	 *
	 * @return void
	 */
	private function expire(): void
	{
		if (!$this->tryClaim())
		{
			return;
		}

		$this->processExpiration();
	}

	private function expireClaimed(): void
	{
		if (!$this->tryReclaimExpiredClaim())
		{
			return;
		}

		$this->processExpiration();
	}

	private function processExpiration(): void
	{
		if (!$this->tryFinalize(true))
		{
			return;
		}

		$this->error = new Error('Hash expired', 'HASH_EXPIRED');
		$result = new Result(null, null);
		$this->sendBackendEvent($result, self::EVENT_FAIL);
		$this->sendFrontendEvent($result, self::EVENT_FAIL);

		$this->apiRequestCompleted = false;
		if ($this->rollbackClaimedConsumption())
		{
			$this->delete();
		}
	}

	/**
	 * Atomically marks the row as being processed while keeping its request identity visible.
	 * The two claimed states preserve whether history was enabled. Only the caller whose
	 * compare-and-swap changed the state may advance to finalization.
	 *
	 * @return bool
	 */
	private function tryClaim(): bool
	{
		if (!$this->id)
		{
			return false;
		}

		while (true)
		{
			$claimedState = match ($this->historyWriteState)
			{
				self::HISTORY_WRITE_ENABLED => self::HISTORY_WRITE_CLAIMED_ENABLED,
				self::HISTORY_WRITE_DISABLED => self::HISTORY_WRITE_CLAIMED_DISABLED,
				self::HISTORY_WRITE_REOPENED_ENABLED,
				self::HISTORY_WRITE_REPEAT_PENDING_ENABLED => self::HISTORY_WRITE_REPEAT_CLAIMED_ENABLED,
				self::HISTORY_WRITE_REOPENED_DISABLED,
				self::HISTORY_WRITE_REPEAT_PENDING_DISABLED => self::HISTORY_WRITE_REPEAT_CLAIMED_DISABLED,
				default => null,
			};
			if ($claimedState === null)
			{
				return false;
			}

			$claimExpireDate = (
				self::isReopenedHistoryWriteState($this->historyWriteState)
				|| self::isRepeatPendingHistoryWriteState($this->historyWriteState)
			)
				? self::incrementDate($this->stateExpireDate)
				: DateTime::createFromTimestamp(time() + self::CALLBACK_CLAIM_TTL);
			if ($this->compareAndSetState(
				$this->id,
				$this->historyWriteState,
				$claimedState,
				$this->stateExpireDate,
				$claimExpireDate,
			))
			{
				$this->historyWriteState = $claimedState;
				$this->stateExpireDate = $claimExpireDate;

				return true;
			}

			$row = QueueTable::query()
				->setSelect(['HISTORY_WRITE', 'EXPIRE_DATE'])
				->where('ID', $this->id)
				->fetch();
			if (!$row)
			{
				return false;
			}
			$this->historyWriteState = (string)$row['HISTORY_WRITE'];
			$this->stateExpireDate = $row['EXPIRE_DATE'];
		}
	}

	/**
	 * Reserves the single terminal side-effect attempt. An expired finalization is removed
	 * without events; tracked failure states retain the idempotent refund intent.
	 */
	private function tryFinalize(bool $refund = false): bool
	{
		if (!$this->id)
		{
			return false;
		}
		$finalizingState = match ($this->historyWriteState)
		{
			self::HISTORY_WRITE_CLAIMED_ENABLED,
			self::HISTORY_WRITE_REPEAT_CLAIMED_ENABLED => self::HISTORY_WRITE_FINALIZING_ENABLED,
			self::HISTORY_WRITE_CLAIMED_DISABLED,
			self::HISTORY_WRITE_REPEAT_CLAIMED_DISABLED => self::HISTORY_WRITE_FINALIZING_DISABLED,
			default => null,
		};
		if ($finalizingState === null)
		{
			return false;
		}

		if ($refund && str_starts_with($this->engine->getConsumptionId(), 'ai:shared_monthly_pool:v1:'))
		{
			$finalizingState = $finalizingState === self::HISTORY_WRITE_FINALIZING_ENABLED
				? self::HISTORY_WRITE_REFUND_ENABLED : self::HISTORY_WRITE_REFUND_DISABLED;
		}

		$finalizationExpireDate = DateTime::createFromTimestamp(time() + self::CALLBACK_CLAIM_TTL);
		if (!$this->compareAndSetState(
			$this->id,
			$this->historyWriteState,
			$finalizingState,
			$this->stateExpireDate,
			$finalizationExpireDate,
		))
		{
			return $this->tryFinalizePendingRepeat($finalizingState, $finalizationExpireDate);
		}

		$this->historyWriteState = $finalizingState;
		$this->stateExpireDate = $finalizationExpireDate;

		return true;
	}

	private function tryFinalizePendingRepeat(string $finalizingState, DateTime $finalizationExpireDate): bool
	{
		$row = QueueTable::query()
			->setSelect(['HISTORY_WRITE', 'EXPIRE_DATE'])
			->where('ID', $this->id)
			->fetch();
		if (
			!$row
			|| !self::isRepeatPendingHistoryWriteState((string)$row['HISTORY_WRITE'])
			|| $row['EXPIRE_DATE'] != $this->stateExpireDate
		)
		{
			return false;
		}

		if (!$this->compareAndSetState(
			$this->id,
			(string)$row['HISTORY_WRITE'],
			$finalizingState,
			$row['EXPIRE_DATE'],
			$finalizationExpireDate,
		))
		{
			return false;
		}

		$this->historyWriteState = $finalizingState;
		$this->stateExpireDate = $finalizationExpireDate;

		return true;
	}

	private function compareAndSetState(
		int $id,
		string $from,
		string $to,
		?DateTime $expectedExpireDate,
		DateTime $newExpireDate,
	): bool
	{
		$connection = Application::getConnection();
		if ($expectedExpireDate === null)
		{
			$sql = new SqlExpression(
				'UPDATE ?# SET HISTORY_WRITE = ?s, EXPIRE_DATE = ?s '
				. 'WHERE ID = ?i AND HISTORY_WRITE = ?s AND EXPIRE_DATE IS NULL',
				QueueTable::getTableName(),
				$to,
				$newExpireDate->format('Y-m-d H:i:s'),
				$id,
				$from,
			);
		}
		else
		{
			$sql = new SqlExpression(
				'UPDATE ?# SET HISTORY_WRITE = ?s, EXPIRE_DATE = ?s '
				. 'WHERE ID = ?i AND HISTORY_WRITE = ?s AND EXPIRE_DATE = ?s',
				QueueTable::getTableName(),
				$to,
				$newExpireDate->format('Y-m-d H:i:s'),
				$id,
				$from,
				$expectedExpireDate->format('Y-m-d H:i:s'),
			);
		}
		$connection->queryExecute($sql->compile());

		return $connection->getAffectedRowsCount() > 0;
	}

	private function compareAndSetHistoryWriteState(
		int $id,
		string $from,
		string $to,
		?DateTime $expectedExpireDate,
	): bool
	{
		$connection = Application::getConnection();
		if ($expectedExpireDate === null)
		{
			$sql = new SqlExpression(
				'UPDATE ?# SET HISTORY_WRITE = ?s '
				. 'WHERE ID = ?i AND HISTORY_WRITE = ?s AND EXPIRE_DATE IS NULL',
				QueueTable::getTableName(),
				$to,
				$id,
				$from,
			);
		}
		else
		{
			$sql = new SqlExpression(
				'UPDATE ?# SET HISTORY_WRITE = ?s '
				. 'WHERE ID = ?i AND HISTORY_WRITE = ?s AND EXPIRE_DATE = ?s',
				QueueTable::getTableName(),
				$to,
				$id,
				$from,
				$expectedExpireDate->format('Y-m-d H:i:s'),
			);
		}

		$connection->queryExecute($sql->compile());

		return $connection->getAffectedRowsCount() > 0;
	}

	/**
	 * Takes over a claim whose lease expired. Extending the lease is the compare-and-swap:
	 * only one cleanup worker may publish the failure and return consumption. If that worker
	 * dies too, the next cleanup attempt can recover the row after the new lease expires.
	 */
	private function tryReclaimExpiredClaim(): bool
	{
		if (
			!$this->id
			|| !self::isClaimedHistoryWriteState($this->historyWriteState)
			|| $this->stateExpireDate === null
		)
		{
			return false;
		}

		$connection = Application::getConnection();
		$now = new DateTime();
		$claimExpireDate = DateTime::createFromTimestamp(time() + self::CALLBACK_CLAIM_TTL);
		$connection->queryExecute(
			(new SqlExpression(
				'UPDATE ?# SET EXPIRE_DATE = ?s '
				. 'WHERE ID = ?i AND HISTORY_WRITE = ?s AND EXPIRE_DATE = ?s AND EXPIRE_DATE < ?s',
				QueueTable::getTableName(),
				$claimExpireDate->format('Y-m-d H:i:s'),
				$this->id,
				$this->historyWriteState,
				$this->stateExpireDate->format('Y-m-d H:i:s'),
				$now->format('Y-m-d H:i:s'),
			))->compile()
		);

		if ($connection->getAffectedRowsCount() === 0)
		{
			return false;
		}

		$this->stateExpireDate = $claimExpireDate;

		return true;
	}

	private function deleteExpiredFinalization(): void
	{
		if (
			!$this->id
			|| !self::isFinalizingHistoryWriteState($this->historyWriteState)
			|| $this->stateExpireDate === null
		)
		{
			return;
		}

		if (in_array(
			$this->historyWriteState,
			[self::HISTORY_WRITE_REFUND_ENABLED, self::HISTORY_WRITE_REFUND_DISABLED],
			true,
		))
		{
			$this->apiRequestCompleted = false;
			if (!$this->rollbackClaimedConsumption())
			{
				return;
			}
		}

		$connection = Application::getConnection();
		$connection->queryExecute(
			(new SqlExpression(
				'DELETE FROM ?# '
				. 'WHERE ID = ?i AND HISTORY_WRITE = ?s AND EXPIRE_DATE = ?s AND EXPIRE_DATE < ?s',
				QueueTable::getTableName(),
				$this->id,
				$this->historyWriteState,
				$this->stateExpireDate->format('Y-m-d H:i:s'),
				(new DateTime())->format('Y-m-d H:i:s'),
			))->compile()
		);
	}

	/**
	 * Sends event about Job result to backend listeners.
	 *
	 * @param Result $result Result instance.
	 * @param string $eventName Event name.
	 * @return void
	 */
	private function sendBackendEvent(Result $result, string $eventName): void
	{
		$event = new Event('ai', $eventName, [
			'queue' => $this->hash,
			'engine' => $this->engine,
			'result' => $result,
			'error' => $this->error,
		]);
		$event->send();
	}

	/**
	 * Sends event about Job result to frontend listeners.
	 *
	 * @param Result $result Result instance.
	 * @param string $eventName Event name.
	 * @return void
	 */
	private function sendFrontendEvent(Result $result, string $eventName): void
	{
		if ($this->context->getUserId() && Loader::includeModule('pull'))
		{
			\Bitrix\Pull\Event::add($this->context->getUserId(), [
				'module_id' => 'ai',
				'command' => $eventName,
				'params' => [
					'hash' => $this->hash,
					'error' => $this->error ? [
						'code' => $this->error->getCode(),
						'message' => $this->error->getMessage(),
					] : null,
					'data' => [
						'result' => $result->getData($this->engine->getResponseFormat()),
						'last' => $this->engine->shouldWriteHistory()
							? Manager::getLastItem($this->context)
							: Manager::getFakeItem($result->getPrettifiedData(), $this->engine)
						,
						'queue' => $this->hash,
					]
				],
			]);
		}
	}

	/**
	 * Cancels current Queue Job.
	 * Removes Job from Queue and does not callbacks.
	 *
	 * Called by every path that ends before the request goes out — a cached result, a missing
	 * cloud registration. Left in place such a row would sit unfinished until its TTL, and the
	 * cleanup agent would then fail it: an invented error event for a request that never went
	 * wrong, and a rollback of a charge that was spent for good. The charge is not touched
	 * here — an answer served from the cache is still an answer the portal asked for.
	 *
	 * A row taken over from an earlier attempt is left alone: that attempt is still in flight
	 * and its callback needs the row.
	 *
	 * @return void
	 */
	public function cancel(): void
	{
		if ($this->reusedRow)
		{
			return;
		}

		$this->delete();
	}

	/**
	 * Removes current Queue Job.
	 *
	 * @return void
	 */
	private function delete(): void
	{
		if ($this->id)
		{
			QueueTable::delete($this->id)->isSuccess();
		}
	}

	private function getLimitControlService(): LimitControlService
	{
		if (empty($this->limitControlService))
		{
			$this->limitControlService = new LimitControlService();
		}

		return $this->limitControlService;
	}

}
