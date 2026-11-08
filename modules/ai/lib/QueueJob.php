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
	private const EVENT_SUCCESS = 'onQueueJobExecute';
	private const EVENT_FAIL = 'onQueueJobFail';

	public const ERROR_EXECUTE = 'EXECUTE_ERROR';
	public const ERROR_INVALID_JSON = 'INVALID_JSON';
	public const ERROR_FAIL_PROCESSING = 'FAIL_PROCESSING_ERROR';

	public const DEFAULT_TTL = 14400;
	public const MAX_TTL = 86400;
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

		// Every selected row is expired, and createFromHash() handles both outcomes itself:
		// a loadable job goes through expire(), a broken one (engine/payload failed to unpack)
		// is deleted right away — no second delete needed here.
		while ($row = $res->fetch())
		{
			self::createFromHash($row['HASH']);
		}

		return __CLASS__ . '::' . __FUNCTION__ . '();';
	}

	/**
	 * Creates Queue Job instance within from Engine.
	 *
	 * @param IEngine $engine Engine instance.
	 * @return self
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
	 * Being unfinished is expressed by the row's mere existence: a finished job is deleted.
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
			->setSelect(['ID', 'HASH', 'ENGINE_CUSTOM_SETTINGS'])
			->where('CACHE_HASH', $cacheHash)
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
			/** @var IPayload $payloadClass */
			/** @var IEngine&IQueue $engineClass */

			$payloadClass = $row['PAYLOAD_CLASS'];
			$payload = $payloadClass::unpack($row['PAYLOAD']);
			$context = Context::unpack($row['CONTEXT']);

			$engine = Engine::getByCode($row['ENGINE_CODE'], $context);
			if ($engine === null || $payload === null)
			{
				Manager::writeDiagnosticHistory([
					'RESULT_TEXT' => '[QUEUE_JOB_LOAD_FAILED] hash=' . $hash
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
				if (!$allowExpired && self::isRowExpired($row['EXPIRE_DATE'], $row['DATE_CREATE']))
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
			$engine->setHistoryState($row['HISTORY_WRITE'] === 'Y');
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

			$expireDate = $row['EXPIRE_DATE'];
			if ($expireDate !== null)
			{
				$queueJob->ttl = $expireDate->getTimestamp() - $row['DATE_CREATE']->getTimestamp();
			}

			// Callback endpoints load a job purely by hash, so an expired-but-not-yet-cleaned-up
			// row must be rejected here too, not just by the periodic clearOldAgent() agent.
			// Success callbacks opt out via $allowExpired: a delivered valid result wins over TTL.
			if (!$allowExpired && self::isRowExpired($expireDate, $row['DATE_CREATE']))
			{
				$queueJob->expire();

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

		$data = [
			'ENGINE_CLASS' => $this->engine::class,
			'ENGINE_CODE' => $this->engine->getCode(),
			'PAYLOAD_CLASS' => $this->engine->getPayload()::class,
			'PAYLOAD' => $this->engine->getPayload()->pack(),
			'CONTEXT' => $this->engine->getContext()->pack(),
			'PARAMETERS' => $this->engine->getParameters(),
			'HISTORY_WRITE' => $this->engine->shouldWriteHistory() ? 'Y' : 'N',
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

			// The limit was charged before the request got here, and this attempt turned out not
			// to be a request at all. The charge of the attempt that owns the row stays: it is
			// the one the failure paths will roll back.
			$this->getLimitControlService()->rollbackConsumption(
				new Limiter\Usage($this->engine->getContext()),
				$this->engine->getPayload()->getCost(),
				$this->engine->getConsumptionId()
			);

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
	 * Executes Queue Job and removes Job.
	 *
	 * @param mixed $rawResult External raw result.
	 * @return void
	 */
	public function execute(mixed $rawResult): void
	{
		if ($this->engine->getPayload()->shouldUseCache())
		{
			$this->engine->setCache(true);
		}
		$cacheManager = new EngineResultCache($this->getCacheHash());
		if($this->engine->isCache() && !$cacheManager->getExists()){
			$cacheManager->store($rawResult);
		}

		Analytics::engineGenerateResultEvent(
			'generate',
			$this->engine,
			$this->engine->getAnalyticData()
		);

		try
		{
			$result = $this->engine->getResultFromRaw($rawResult);

			// Soft error — writeHistory failure should not prevent success events
			try
			{
				$this->engine->writeHistory($result);
			}
			catch (\Throwable)
			{
				// Manager already attempted diagnostic write to b_ai_history
			}

			if ($this->engine->getPayload()->getRole() !== null)
			{
				$langCode = $this->context->getLanguage()?->getCode() ?? User::getUserLanguage();
				$roleManager = new RoleManager($this->context->getUserId(), $langCode);
				$roleManager->addRecentRole($this->engine->getPayload()->getRole());
			}

			$this->sendBackendEvent($result, self::EVENT_SUCCESS);
			$this->sendFrontendEvent($result, self::EVENT_SUCCESS);
			$this->delete();
		}
		catch (\Throwable $e)
		{
			$this->handleError($e->getMessage(), self::ERROR_EXECUTE);
		}
	}

	public function handleError(string $message, string $code = 'PROCESSING_ERROR'): void
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

		Manager::writeDiagnosticHistory($data);

		try
		{
			$this->sendBackendEvent(new Result(null, null), self::EVENT_FAIL);
			$this->sendFrontendEvent(new Result(null, null), self::EVENT_FAIL);
		}
		catch (\Throwable)
		{
			// Best effort
		}

		$this->delete();
	}

	/**
	 * Fails Queue Job and removes Job.
	 *
	 * @param mixed $rawError External raw error.
	 * @return void
	 */
	public function fail(mixed $rawError): void
	{
		$errorMessage = $rawError['message'] ?? null;
		if (!is_string($errorMessage) || $errorMessage === '')
		{
			$errorField = $rawError['error'] ?? null;
			$errorMessage = is_string($errorField) ? $errorField : 'Unknown Error';
		}
		$this->error = new Error($errorMessage, $rawError['code'] ?? '');
		$this->engine->writeErrorInHistory($this->error);

		if (isset($rawError['api_request_completed']))
		{
			$this->apiRequestCompleted = (bool)$rawError['api_request_completed'];
		}

		$rawErrorCode = $rawError['code'] ?? null;
		$errorCode = (int)$rawErrorCode;

		Loc::loadLanguageFile(__DIR__ . '/Engine.php');

		if ($errorCode === 100 || $errorCode >= 500 || $rawErrorCode === Engine\Engine::ERROR_CODE_COULD_NOT_LOCK)
		{
			$this->error = new Error(Loc::getMessage('AI_ENGINE_ERROR_PROVIDER'), 'AI_ENGINE_ERROR_PROVIDER');
		}
		else
		{
			$this->error = new Error(Loc::getMessage('AI_ENGINE_ERROR_OTHER'),'AI_ENGINE_ERROR_OTHER');
		}

		$this->sendBackendEvent(new Result(null, null), self::EVENT_FAIL);
		$this->sendFrontendEvent(new Result(null, null), self::EVENT_FAIL);

		if (!$this->apiRequestCompleted)
		{
			$this->getLimitControlService()->rollbackConsumption(
				new Limiter\Usage($this->engine->getContext()),
				$this->engine->getPayload()->getCost(),
				$this->engine->getConsumptionId()
			);
		}

		$this->delete();
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
		// Delete is the claim: expire() is reachable from concurrent requests (a late callback
		// racing the agent tick, or a retried callback), and only the caller that actually
		// removed the row may send fail events and roll back consumption — otherwise both would.
		if (!$this->tryDelete())
		{
			return;
		}

		$this->error = new Error('Hash expired', 'HASH_EXPIRED');

		$result = new Result(null, null);
		$this->sendBackendEvent($result, self::EVENT_FAIL);
		$this->sendFrontendEvent($result, self::EVENT_FAIL);

		$this->getLimitControlService()->rollbackConsumption(
			new Limiter\Usage($this->engine->getContext()),
			$this->engine->getPayload()->getCost(),
			$this->engine->getConsumptionId()
		);
	}

	/**
	 * Removes the row and reports whether this call actually deleted it.
	 * Raw DELETE is used because ORM delete() gives no affected-rows contract.
	 *
	 * @return bool
	 */
	private function tryDelete(): bool
	{
		if (!$this->id)
		{
			return false;
		}

		$connection = Application::getConnection();
		$connection->queryExecute(
			(new SqlExpression('DELETE FROM ?# WHERE ID = ?i', QueueTable::getTableName(), $this->id))->compile()
		);

		return $connection->getAffectedRowsCount() > 0;
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
