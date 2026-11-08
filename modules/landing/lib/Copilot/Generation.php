<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot;

use Bitrix\Landing;
use Bitrix\Landing\Agent;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\GenerationTimeSliceException;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSite;
use Bitrix\Landing\Copilot\Generation\Scenario\IScenario;
use Bitrix\Landing\Copilot\Generation\Scenario\Scenarist;
use Bitrix\Landing\Copilot\Generation\Timer;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Copilot\Generation\Type\StepStatuses;
use Bitrix\Landing\Copilot\Model\GenerationsTable;
use Bitrix\Landing\Copilot\Model\StepsTable;
use Bitrix\Landing\Copilot\Services\FirstSiteGenerationService;
use Bitrix\Landing\Copilot\Services\GenerationErrorStatusMapper;
use Bitrix\Landing\Metrika;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\ORM\Query\Filter;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

/**
 * This class is responsible for generating site content using various managers and connectors.
 * It manages the generation process, scenario execution, metrika analytics, and error handling.
 *
 * An instance is either full or narrow: initByIdWithoutHtmlBlocks() skips HTML_BLOCKS, every other
 * initializer reads them. A narrow instance refuses getHtmlBlocks() with \LogicException and keeps
 * the stored blocks intact on save(), so it must stay inside the read path that asked for it -
 * re-read with initById() before handing it to code that works with the blocks.
 */
class Generation
{
	private const EVENT_ERROR = 'onCopilotError';
	private const EVENT_TIMER = 'onCopilotTimeIsOver';
	private const EVENT_GENERATION_CREATE = 'onGenerationCreate';
	private const EVENT_GENERATION_ERROR = 'onGenerationError';
	private const EVENT_GENERATION_FINISH = 'onGenerationFinish';

	private const DATA_METRIKA_KEY = 'metrikaFields';
	private const DATA_LAST_ERROR_KEY = 'lastError';
	private const DATA_LAST_ERROR_CODE_KEY = 'lastErrorCode';
	private const DATA_LAST_FAILURE_KIND_KEY = 'lastFailureKind';
	private const DATA_ERROR_MARK_ATTEMPTS_KEY = 'errorMarkWriteAttempts';

	/** Runs allowed to retry a step whose Error mark could not be written; after them nothing retries it. */
	private const MAX_ERROR_MARK_WRITE_ATTEMPTS = 3;

	/** The run ended with a generation error of the domain: the reason is in DATA_LAST_ERROR_CODE_KEY. */
	public const FAILURE_KIND_DOMAIN = 'domain';
	/** The run ended with a failure of the infrastructure, which says nothing about the request itself. */
	public const FAILURE_KIND_NON_DOMAIN = 'nonDomain';

	private int $id;
	private IScenario $scenario;
	private Data\Site $siteData;
	private ?array $data = null;
	/** @var list<Data\HtmlBlock> */
	private array $htmlBlocks = [];
	/** False when the instance was initialized without reading HTML_BLOCKS. */
	private bool $htmlBlocksLoaded = true;
	private int $step;
	private int $authorId;
	private ?DateTime $dateFinished = null;

	private Scenarist $scenarist;
	private Timer $timer;
	private ?Generation\Event $event = null;
	private bool $generationCreateEventSent = false;
	private ?Metrika\FieldsDto $metrikaFields = null;
	private Metrika\MetrikaProviderParamService $metrikaProviderParamService;

	/**
	 * Generation constructor.
	 * Initializes authorId, siteData, and timer.
	 */
	public function __construct()
	{
		$this->authorId = Landing\Manager::getUserId();

		$this->siteData = new Data\Site();
		$this->timer = new Timer();

		$this->metrikaProviderParamService = new Metrika\MetrikaProviderParamService();
	}

	/**
	 * Returns the ID of the user who created the site.
	 *
	 * @return int
	 */
	public function getAuthorId(): int
	{
		return $this->authorId;
	}

	/**
	 * Overrides the author id explicitly.
	 *
	 * Useful for runtimes where the effective tool caller user id
	 * is passed separately from the current global Bitrix user.
	 *
	 * @param int $authorId
	 *
	 * @return Generation
	 */
	public function setAuthorId(int $authorId): self
	{
		if ($authorId > 0)
		{
			$this->authorId = $authorId;
		}

		return $this;
	}

	/**
	 * Sets the scenario for this generation.
	 *
	 * @param IScenario $scenario
	 *
	 * @return Generation
	 */
	public function setScenario(IScenario $scenario): self
	{
		$this->scenario = $scenario;

		return $this;
	}

	/**
	 * Gets the scenario for this generation.
	 *
	 * @return IScenario|null
	 */
	public function getScenario(): ?IScenario
	{
		return $this->scenario ?? null;
	}

	/**
	 * Sets the site data for this generation.
	 *
	 * @param Data\Site $siteData
	 *
	 * @return Generation
	 */
	public function setSiteData(Data\Site $siteData): self
	{
		$this->siteData = $siteData;

		return $this;
	}

	/**
	 * Gets the site data for this generation.
	 *
	 * @return Data\Site
	 */
	public function getSiteData(): Data\Site
	{
		return $this->siteData;
	}

	/**
	 * @return list<Data\HtmlBlock>
	 *
	 * @throws \LogicException When the instance is a narrow one (initByIdWithoutHtmlBlocks()) and the
	 *   blocks were never read - a programmer error of the calling path, see the class docblock.
	 */
	public function getHtmlBlocks(): array
	{
		if (!$this->htmlBlocksLoaded)
		{
			// silent [] here would be indistinguishable from "there are none" and would let a
			// read-modify-write of this instance erase the stored blocks
			throw new \LogicException(
				'Generation was initialized without HTML_BLOCKS: use initById() to read them.'
			);
		}

		return $this->htmlBlocks;
	}

	/**
	 * @param list<Data\HtmlBlock> $htmlBlocks
	 */
	public function setHtmlBlocks(array $htmlBlocks): void
	{
		foreach ($htmlBlocks as $htmlBlock)
		{
			if (!$htmlBlock instanceof Data\HtmlBlock)
			{
				throw new \InvalidArgumentException('Generation::setHtmlBlocks() accepts only HtmlBlock instances.');
			}
		}

		$this->htmlBlocks = array_values($htmlBlocks);
		$this->htmlBlocksLoaded = true;
	}

	/**
	 * Gets custom data for this generation.
	 *
	 * @param string|null $key Optional key to retrieve a specific value.
	 *
	 * @return mixed|null Returns the value for the given key, all data if key is null, or null if not set.
	 */
	public function getData(?string $key = null): mixed
	{
		if (!isset($key))
		{
			return $this->data;
		}

		return $this->data[$key] ?? null;
	}

	/**
	 * Sets custom data for this generation.
	 *
	 * @param string $key Data key.
	 * @param mixed $data Data value.
	 *
	 * @return void
	 */
	public function setData(string $key, mixed $data): void
	{
		if (!isset($this->data))
		{
			$this->data = [];
		}

		$this->data[$key] = $data;
	}

	/**
	 * Deletes a key from the custom data.
	 *
	 * @param string $key Data key to delete.
	 *
	 * @return void
	 */
	public function deleteData(string $key): void
	{
		if (is_array($this->data) && array_key_exists($key, $this->data))
		{
			unset($this->data[$key]);
		}
	}

	/**
	 * Sets the wishes for the site data.
	 *
	 * @param Data\Wishes $wishes
	 *
	 * @return Generation
	 */
	public function setWishes(Data\Wishes $wishes): self
	{
		$this->siteData->setWishes($wishes);

		return $this;
	}

	/**
	 * Gets the generation ID.
	 *
	 * @return int|null
	 */
	public function getId(): ?int
	{
		return $this->id ?? null;
	}

	/**
	 * Gets the current step of the generation.
	 *
	 * @return int|null
	 */
	public function getStep(): ?int
	{
		return $this->step ?? null;
	}

	/**
	 * Gets the timer object for this generation.
	 *
	 * @return Timer
	 */
	public function getTimer(): Timer
	{
		return $this->timer;
	}

	/**
	 * Sets metrika fields for analytics and stores them in custom data.
	 *
	 * @param Metrika\FieldsDto $fields
	 *
	 * @return Generation
	 */
	public function setMetrikaFields(Metrika\FieldsDto $fields): self
	{
		$this->metrikaFields = $fields;
		$this->setData(self::DATA_METRIKA_KEY, $this->metrikaFields->toArray());

		return $this;
	}

	/**
	 * Gets metrika fields for analytics.
	 *
	 * @return Metrika\FieldsDto|null
	 */
	private function getMetrikaFields(): ?Metrika\FieldsDto
	{
		$saved =
			$this->getData(self::DATA_METRIKA_KEY)
				? Metrika\FieldsDto::fromArray($this->getData(self::DATA_METRIKA_KEY))
				: null
		;
		if (!$saved)
		{
			return $this->metrikaFields;
		}

		return $saved->addFields($this->metrikaFields);
	}

	/**
	 * Gets the Metrika analytics object for the given event.
	 *
	 * @param Metrika\Events $event
	 *
	 * @return Metrika\Metrika
	 */
	public function getMetrika(Metrika\Events $event): Metrika\Metrika
	{
		$metrika = new Metrika\Metrika(
			$this->scenario->getAnalyticCategory(),
			$event,
			Metrika\Tools::Ai,
		);

		$this->metrikaProviderParamService->setParams($metrika, $event);

		$fields = $this->getMetrikaFields();
		if (isset($fields))
		{
			foreach ($fields->params ?? [] as $param)
			{
				if (count($param) === 3)
				{
					$metrika->setParam(
						(int)$param[0],
						(string)$param[1],
						(string)$param[2],
					);
				}
			}

			if (isset($fields->subSection))
			{
				$metrika->setSubSection($fields->subSection);
			}
		}

		return $metrika;
	}

	/**
	 * Tries to find an existing generation by ID and initializes this instance with its data.
	 *
	 * @param int $generationId Generation ID.
	 *
	 * @return bool True if found and initialized, false otherwise.
	 */
	public function initById(int $generationId): bool
	{
		return $this->initByIdInternal($generationId, true);
	}

	/**
	 * Answers like initById(), but skips HTML_BLOCKS - a heavy mediumtext that grows while the site
	 * is being generated. For read paths that never call getHtmlBlocks(), status polling above all.
	 *
	 * The rest of the payload is still read, so this answers about a generation; whether the caller
	 * may have one at all is findDescriptorById().
	 *
	 * @param int $generationId Generation ID.
	 *
	 * @return bool True if found and initialized, false otherwise.
	 */
	public function initByIdWithoutHtmlBlocks(int $generationId): bool
	{
		return $this->initByIdInternal($generationId, false);
	}

	private function initByIdInternal(int $generationId, bool $withHtmlBlocks): bool
	{
		if ($generationId <= 0)
		{
			return false;
		}

		FirstSiteGenerationService::setCurrentGenerationId($generationId);

		$filter =
			Query::filter()
				->where('ID', '=', $generationId)
		;

		return $this->initExists($filter, $withHtmlBlocks);
	}

	/**
	 * Tries to find an existing generation by site ID and scenario, and initializes this instance with its data.
	 *
	 * @param int $siteId Site ID.
	 * @param IScenario $scenario Scenario instance.
	 *
	 * @return bool True if found and initialized, false otherwise.
	 */
	public function initBySiteId(int $siteId, IScenario $scenario): bool
	{
		$filter =
			Query::filter()
				->where('SCENARIO', '=', $scenario::class)
				->where('SITE_ID', '=', $siteId)
		;

		return $this->initExists($filter);
	}

	/**
	 * Tells if a generation of the scenario exists for the site, without loading its data.
	 * Answers like initBySiteId(), but does not read the heavy fields of the generation.
	 *
	 * @param int $siteId Site ID.
	 * @param IScenario $scenario Scenario instance.
	 *
	 * @return bool True if such a generation exists, false otherwise.
	 */
	public static function existsBySiteId(int $siteId, IScenario $scenario): bool
	{
		$generation = GenerationsTable::query()
			->where('SCENARIO', '=', $scenario::class)
			->where('SITE_ID', '=', $siteId)
			// SITE_DATA is written as a serialized array when the generation is created, so NULL there
			// marks a record the initialization path cannot use: initExists() leaves siteData unset and
			// initScenarist() gives up on it. The predicate keeps the answer of this method equal to the
			// answer of initBySiteId() without reading the heavy fields of the generation
			->whereNotNull('SITE_DATA')
			->setSelect(['ID'])
			->setLimit(1)
			->fetch()
		;

		return (bool)$generation;
	}

	/**
	 * Reads the identity of a generation without its payload.
	 * Answers like initById() about existence, scenario, author and both terminal marks,
	 * but does not read the heavy fields of the generation.
	 *
	 * Cheap enough to run on every request; once the job is known to be theirs,
	 * initByIdWithoutHtmlBlocks() loads what the answer needs.
	 *
	 * @param int $generationId Generation ID.
	 *
	 * @return Generation\Descriptor|null Descriptor of the generation, or null when initById() would fail too.
	 */
	public static function findDescriptorById(int $generationId): ?Generation\Descriptor
	{
		if ($generationId <= 0)
		{
			return null;
		}

		$generation = GenerationsTable::query()
			->where('ID', '=', $generationId)
			// SITE_DATA is written as a serialized array when the generation is created, so NULL there
			// marks a record the initialization path cannot use: initExists() leaves siteData unset and
			// initScenarist() gives up on it. The predicate keeps the answer of this method equal to the
			// answer of initById() without reading the heavy fields of the generation
			->whereNotNull('SITE_DATA')
			->setSelect(['ID', 'SCENARIO', 'CREATED_BY_ID', 'DATE_FINISHED'])
			->setLimit(1)
			->fetch()
		;

		if (!$generation || !class_exists($generation['SCENARIO']))
		{
			return null;
		}

		return new Generation\Descriptor(
			id: (int)$generation['ID'],
			scenarioClass: $generation['SCENARIO'],
			authorId: (int)$generation['CREATED_BY_ID'],
			isFinished: ($generation['DATE_FINISHED'] ?? null) instanceof DateTime,
			hasError: self::hasErroredStep((int)$generation['ID']),
		);
	}

	/**
	 * Tells if a step of the generation ended with an error, without reading the generation itself.
	 *
	 * The mark is persisted per step in its own table, indexed by generation, so the answer costs
	 * one light read. It is not the same predicate as Scenarist::isError(): a row whose step has
	 * left the scenario map counts here and not there, and that direction only drops a wait for a
	 * job nothing is going to advance.
	 *
	 * @param int $generationId Generation ID.
	 *
	 * @return bool True when at least one step of the generation is marked as errored.
	 */
	private static function hasErroredStep(int $generationId): bool
	{
		$step = StepsTable::query()
			->setSelect(['ID'])
			->where('GENERATION_ID', '=', $generationId)
			->where('STATUS', '=', StepStatuses::Error->value)
			->setLimit(1)
			->fetch()
		;

		return (bool)$step;
	}

	/**
	 * Initializes this instance with data from an existing generation matching the filter.
	 * @param Filter\ConditionTree $filter ORM filter for the query.
	 * @param bool $withHtmlBlocks False to skip the heavy HTML_BLOCKS field.
	 *
	 * @return bool True if found and initialized, false otherwise.
	 */
	private function initExists(Filter\ConditionTree $filter, bool $withHtmlBlocks = true): bool
	{
		$select = ['ID', 'SCENARIO', 'STEP', 'SITE_DATA', 'DATA', 'CREATED_BY_ID', 'DATE_FINISHED'];
		if ($withHtmlBlocks)
		{
			$select[] = 'HTML_BLOCKS';
		}

		$generation = GenerationsTable::query()
			->where($filter)
			->setSelect($select)
			->fetch()
		;

		if (!$generation)
		{
			return false;
		}

		$this->id = (int)$generation['ID'];
		$this->authorId = (int)$generation['CREATED_BY_ID'];
		$this->generationCreateEventSent = true;

		$dateFinished = $generation['DATE_FINISHED'] ?? null;
		$this->dateFinished = $dateFinished instanceof DateTime ? $dateFinished : null;

		if (isset($generation['STEP']))
		{
			$this->step = (int)$generation['STEP'];
		}

		if (!class_exists($generation['SCENARIO']))
		{
			return false;
		}
		$this->setScenario(new ($generation['SCENARIO'])());

		if (
			isset($generation['SITE_DATA'])
			&& is_array($generation['SITE_DATA'])
		)
		{
			$this->siteData = Data\Site::fromArray($generation['SITE_DATA']);
		}

		if (
			is_array($generation['DATA'])
			&& !empty($generation['DATA'])
		)
		{
			$this->data = $generation['DATA'];
		}

		$this->htmlBlocksLoaded = $withHtmlBlocks;
		$storedHtmlBlocks = $withHtmlBlocks ? ($generation['HTML_BLOCKS'] ?? null) : null;
		$this->hydrateHtmlBlocks(is_array($storedHtmlBlocks) ? $storedHtmlBlocks : null);

		if (!$this->initScenarist())
		{
			return false;
		}

		return true;
	}

	/**
	 * Runs the generation process.
	 *
	 * @return bool False if an error occurs while executing, true otherwise.
	 */
	public function execute(): bool
	{
		if (!$this->prepareExecution())
		{
			return false;
		}

		return $this->executePreparedScenario();
	}

	/**
	 * Persist/initialize and validate prerequisites before execution.
	 */
	private function prepareExecution(): bool
	{
		if (!isset($this->id) && !$this->save())
		{
			return false;
		}

		FirstSiteGenerationService::setCurrentGenerationId($this->id ?? null);

		return $this->isExecutable() && $this->initScenarist();
	}

	/**
	 * Execute scenario under lock after prepare step has succeeded.
	 */
	private function executePreparedScenario(): bool
	{
		return $this->runWithLock(function (): bool
		{
			$isSuccess = true;

			try
			{
				$this->runScenario();
			}
			catch (\Throwable $e)
			{
				$isSuccess = $this->handleExecutionException($e);
			}

			return $isSuccess;
		});
	}

	/**
	 * Run scenarist and finalize generation when all steps are completed.
	 */
	private function runScenario(): void
	{
		$this->timer->start();
		$this->deleteAgent();

		$this->scenarist
			->onStepChange(fn(int $newStep) => $this->step = $newStep)
			->onSave(fn() => $this->save())
			->execute()
		;

		if ($this->scenarist->isFinished())
		{
			$this->onFinish();
		}
		elseif (!$this->scenarist->isError())
		{
			$this->setAgent();
		}
	}

	/**
	 * Map execution exceptions to side effects and final result status.
	 *
	 * Whether the generation keeps an executor is decided once, for every kind of failure alike, and
	 * before the first side effect: the writes below - the metrika of the error, the hook of the
	 * scenario, the save of the state - all go to the same database whose loss suppresses the Error
	 * mark of the step, so a decision made after them is a decision that a broken connection can skip.
	 * The answer given to the reader of the generation follows the same decision, or the run would be
	 * continued by an agent while the editor has already been told to stop watching it.
	 */
	private function handleExecutionException(\Throwable $e): bool
	{
		$keepsExecutor = $this->decideExecutorRetention($e);

		if ($e instanceof GenerationException)
		{
			try
			{
				$this->rememberLastError($e);
				$this->rememberLastErrorCode($e);
				$this->rememberFailureKind(self::FAILURE_KIND_DOMAIN);
				$this->sendMetrikaError($e);
				// the scenario hook may mark the generation data (analytics idempotency across resumes),
				// so the data is persisted after it, not before
				$this->scenario->onGenerationError($this, $e);
				$this->save(false);
			}
			finally
			{
				// The executor is returned after the state of the failure has been written and even when
				// writing it has thrown: setAgent() is a write of its own, so putting it first would let
				// its failure cost the run the whole record of what happened, while putting it outside
				// the guard would let any of the writes above cost the generation its executor.
				$this->finishDomainFailure($keepsExecutor);
			}

			return false;
		}

		// The cooperative exit by the time budget records nothing - it is not a failure, and the step it
		// leaves behind is still executable.
		if (!($e instanceof GenerationTimeSliceException))
		{
			$this->rememberNonDomainFailure();
		}

		// Data a step has written - the applied operations of the change flow, the diagnostic reason of
		// a failed one - lives on the generation object until something persists it. Only the domain
		// branch above did, so a non-domain failure lost it: the next poll reloaded the data as it was
		// before the failed run and answered without what that run had recorded. The write is guarded,
		// because a failure of the write must not replace the exception that has to travel on, and it
		// carries the kind above out with it.
		$this->saveDataQuietly();

		if ($keepsExecutor)
		{
			$this->keepExecutor();

			return false;
		}

		if ($e instanceof \Exception)
		{
			$this->getEvent()->sendError(
				self::EVENT_ERROR,
				$e->getMessage(),
			);

			return false;
		}

		throw $e;
	}

	/**
	 * The one rule about the executor of a failed run, asked before the run records anything.
	 *
	 * The generation keeps an executor when it is going to be continued: after a cooperative exit by
	 * the time budget, which is no failure at all, and after any failure that left no errored step in
	 * the database - it may have come from outside the step loop (the scenario bookkeeping, a save,
	 * the finish hook), or its Error mark may have been suppressed on the way to the database. In both
	 * cases the step stays executable for the next run, and dropping the agent would leave the
	 * generation running with nothing to reach it.
	 * "Errored" therefore means errored in the database, not merely on the step object: an errored step
	 * of the database is the one case that needs no agent, because a repeating agent would find nothing
	 * to execute and only cost an extra run.
	 *
	 * A suppressed mark is also counted here, and that is the only place the counting can happen: only
	 * a run that has just suppressed the mark knows it did. The count is what the ceiling of the
	 * retries is read from below, and it travels to the database with the state of the failure.
	 */
	private function decideExecutorRetention(\Throwable $e): bool
	{
		if ($e instanceof GenerationTimeSliceException)
		{
			// The cooperative exit is not counted and never limited: long generations are made of these
			// exits, and a ceiling over them would stop the main scenario halfway through.
			return true;
		}

		if ($this->isError() && !$this->isErrorPersisted())
		{
			$this->countErrorMarkWriteAttempt();
		}

		return !$this->isErrorPersisted();
	}

	/**
	 * Executor and answer of a failure that is going to be continued: the agent that reaches the still
	 * executable step, and the non-terminal event, the same one the cooperative time budget exit sends.
	 * The terminal event must not be sent here - its listener in the editor stops observing the
	 * generation, while this generation still has a run ahead of it.
	 */
	private function keepExecutor(): void
	{
		$this->setAgent();
		$this->getEvent()->send(self::EVENT_TIMER);
	}

	/**
	 * Closes a domain failure by the decision above: the terminal event is sent only to a generation
	 * nothing is going to continue.
	 */
	private function finishDomainFailure(bool $keepsExecutor): void
	{
		if ($keepsExecutor)
		{
			$this->keepExecutor();

			return;
		}

		$this->getEvent()->send(self::EVENT_GENERATION_ERROR);
	}

	/**
	 * Counts one run whose Error mark did not reach the database.
	 *
	 * The agent scheduled for such a run deletes itself on the next pass, so nothing counts the passes
	 * but this: a mark that keeps failing to be written would otherwise re-execute the step - and its
	 * requests to the model, and the quota they spend - every ten seconds forever.
	 */
	private function countErrorMarkWriteAttempt(): void
	{
		$this->setData(self::DATA_ERROR_MARK_ATTEMPTS_KEY, $this->getErrorMarkWriteAttempts() + 1);
	}

	private function getErrorMarkWriteAttempts(): int
	{
		return max(0, (int)$this->getData(self::DATA_ERROR_MARK_ATTEMPTS_KEY));
	}

	/**
	 * Whether the attempts to write the Error mark of a step are spent.
	 *
	 * The count lives in the data of the generation and is written by the same guarded save as the rest
	 * of the failure. A run that cannot write the data either leaves the count as it was, and the
	 * generation degrades to what it did before the ceiling existed - retried until the database
	 * recovers. That is the most a failure that cannot record anything about itself can do.
	 */
	private function isErrorMarkWriteGivenUp(): bool
	{
		return $this->getErrorMarkWriteAttempts() >= self::MAX_ERROR_MARK_WRITE_ATTEMPTS;
	}

	/**
	 * Persists the generation without letting a failed write outweigh the exception being handled.
	 *
	 * Called on the failure paths that have no domain error to report: there the exception on its way
	 * out is the meaningful one, and a save that fails on the same broken connection must not replace
	 * it with an error about saving.
	 */
	private function saveDataQuietly(): void
	{
		try
		{
			$this->save(false);
		}
		catch (\Throwable)
		{
			// the original exception is the one that must reach the caller
		}
	}

	/**
	 * Acquire lock, run operation, and always release lock in finally.
	 */
	private function runWithLock(callable $operation): bool
	{
		$connection = Application::getConnection();
		if (!$connection->lock($this->getLockName()))
		{
			$this->setAgent();

			return false;
		}

		try
		{
			return (bool)$operation();
		}
		finally
		{
			$connection->unlock($this->getLockName());
		}
	}

	/**
	 * Checks if the generation is executable (all required properties are set).
	 *
	 * @return bool
	 */
	private function isExecutable(): bool
	{
		return isset(
			$this->id,
			$this->siteData,
			$this->scenario,
		);
	}

	/**
	 * Gets the lock name for this generation.
	 *
	 * @return string
	 */
	private function getLockName(): string
	{
		return 'landing_copilot_generation_' . ($this->id ?? 0);
	}

	/**
	 * Schedules an agent to retry execution.
	 *
	 * Executor seam of the generation: which failure keeps an agent and which one is terminal is the
	 * rule handleExecutionException() is about, and it is observable only here - the scheduling itself
	 * goes to the agent table and cannot be reached from a test.
	 *
	 * @return void
	 */
	protected function setAgent(): void
	{
		Agent::addUniqueAgent('executeGeneration', [$this->id], 60, 10);
	}

	/**
	 * Removes the scheduled agent for this generation.
	 *
	 * @return void
	 */
	private function deleteAgent(): void
	{
		Agent::deleteUniqueAgent('executeGeneration', [$this->id]);
	}

	/**
	 * Finishes the generation process and marks it as completed.
	 *
	 * @return void
	 */
	public function finish(): void
	{
		if (!$this->initScenarist())
		{
			return;
		}

		$this->scenarist->finish();
		$this->onFinish();
	}

	/**
	 * Checks if the generation is finished: by the persisted finish mark or by scenario steps.
	 *
	 * @return bool
	 */
	public function isFinished(): bool
	{
		if ($this->dateFinished !== null)
		{
			return true;
		}

		if (!$this->initScenarist())
		{
			return false;
		}

		return $this->scenarist->isFinished();
	}

	/**
	 * Tells if the finish mark was already in the base when this instance was initialized.
	 * The mark is persisted after the hooks of the scenario are called, so during them the answer
	 * is "yes" only for a repeated run of an already finished generation.
	 *
	 * @return bool
	 */
	public function isFinishedPersisted(): bool
	{
		return $this->dateFinished !== null;
	}

	/**
	 * Handles actions to perform when the generation is finished.
	 *
	 * @return void
	 */
	private function onFinish(): void
	{
		$this->persistDateFinished();
		$this->getEvent()->send(self::EVENT_GENERATION_FINISH);
	}

	/**
	 * Persists the finish mark once; repeated calls keep the first value.
	 *
	 * @return void
	 */
	private function persistDateFinished(): void
	{
		if ($this->dateFinished !== null || !isset($this->id))
		{
			return;
		}

		$dateFinished = new DateTime();
		$result = GenerationsTable::update($this->id, ['DATE_FINISHED' => $dateFinished]);
		if ($result->isSuccess())
		{
			$this->dateFinished = $dateFinished;
		}
	}

	/**
	 * Checks if at least one scenario step has an error and was not executed.
	 *
	 * @return bool
	 */
	public function isError(): bool
	{
		if (!$this->initScenarist())
		{
			return true;
		}

		return $this->scenarist->isError();
	}

	/**
	 * Same failure as isError(), but only when the Error status has also reached the database.
	 *
	 * A mark that lives on the step object alone leaves the step executable for the next run, so the
	 * generation keeps an executor and is going to continue. Everything that treats a failure as
	 * terminal - dropping the agent in handleExecutionException(), the terminal answer and the release
	 * of the chat reservation in the AI-site use cases - must ask this predicate, or it would end a
	 * generation that is still going to run.
	 *
	 * A scenarist that cannot be initialized answers true, exactly as isError() does and for the same
	 * reason: prepareExecution() gives up before executePreparedScenario(), no agent is ever set, and
	 * there is nothing left to continue the generation.
	 *
	 * A generation whose attempts to write the mark are spent answers true for that same reason, and
	 * not because the mark has reached the database - it has not. The predicate says "no executor is
	 * going to continue this generation", and after the last attempt none is: without this the endless
	 * retrying would only be traded for an endless "running" answer to everyone reading the generation.
	 *
	 * @return bool True when a step is errored and its Error status is persisted.
	 */
	public function isErrorPersisted(): bool
	{
		if ($this->isErrorMarkWriteGivenUp())
		{
			return true;
		}

		if (!$this->initScenarist())
		{
			return true;
		}

		return $this->scenarist->isErrorPersisted();
	}

	/**
	 * Prepares the scenario to restart generation after an error by clearing errors.
	 *
	 * @return $this
	 */
	public function clearErrors(): self
	{
		if ($this->initScenarist())
		{
			$this->scenarist->clearErrors();
		}
		$this->clearLastError();
		$this->deleteData(self::DATA_LAST_ERROR_CODE_KEY);
		$this->deleteData(self::DATA_LAST_FAILURE_KIND_KEY);
		$this->deleteData(self::DATA_ERROR_MARK_ATTEMPTS_KEY);
		if (isset($this->id))
		{
			$this->save(false);
		}

		return $this;
	}

	private function rememberLastError(GenerationException $exception): void
	{
		if ($exception->getErrorCode() !== GenerationErrors::requestQuotaExceeded)
		{
			$this->clearLastError();

			return;
		}

		$errorText = trim((string)($exception->getParams()['errorText'] ?? ''));
		if ($errorText === '')
		{
			$this->clearLastError();

			return;
		}

		$this->setData(self::DATA_LAST_ERROR_KEY, [
			'type' => 'limit',
			'text' => $errorText,
			'sliderCode' => $this->extractFeaturePromoterSliderCode($errorText),
			'metrikaStatus' => $this->normalizeMetrikaStatus($exception->getParams()['metrikaStatus'] ?? null),
		]);
	}

	private function clearLastError(): void
	{
		$this->deleteData(self::DATA_LAST_ERROR_KEY);
	}

	/**
	 * Remembers which generation error ended the run.
	 *
	 * The errored step alone does not say why it failed, and the two reasons a step can fail for are
	 * answered differently: a step that refused the request and a step that could not perform its check
	 * at all must not reach the user as the same refusal. The code is the only part of the exception
	 * that survives the run, and it carries no free text, so it is safe to keep.
	 */
	private function rememberLastErrorCode(GenerationException $exception): void
	{
		$this->setData(self::DATA_LAST_ERROR_CODE_KEY, $exception->getErrorCode()->value);
	}

	/**
	 * Remembers a failure that carries no generation error at all: an infrastructure one.
	 *
	 * Without it the absence of an error code is ambiguous - a run that never reached the domain and an
	 * older generation that predates the code look the same - and a consumer reading the step alone
	 * would report a step that refused the request where the step only failed to perform its check.
	 * The error code of the previous failure is dropped with it, or that code would outlive the run it
	 * describes. The limit record goes with the code, and for a stronger reason: it is read before the
	 * step and before the kind, so a limit refusal left behind by an earlier run would name the quota
	 * as the reason of a failure that never asked the domain anything.
	 *
	 * The kind travels with the data of the generation, so a failure whose data write is suppressed
	 * leaves no kind behind. When the Error status of its step was not written either, nothing is lost:
	 * the step stays executable and the failure is terminal for no one. When the status was written and
	 * only the data was not - two writes to two tables, which fail apart - the classification falls back
	 * to what it was before the kind existed: an absent error code read as a refusal. That is the most a
	 * consumer can do with a failure that recorded nothing about itself.
	 */
	private function rememberNonDomainFailure(): void
	{
		$this->clearLastError();
		$this->deleteData(self::DATA_LAST_ERROR_CODE_KEY);
		$this->rememberFailureKind(self::FAILURE_KIND_NON_DOMAIN);
	}

	private function rememberFailureKind(string $kind): void
	{
		$this->setData(self::DATA_LAST_FAILURE_KIND_KEY, $kind);
	}

	/**
	 * Whether the last failure of the generation was an infrastructure one rather than a refusal of the
	 * domain. Single place the rule lives in: consumers ask the generation instead of reading its data
	 * and deciding for themselves.
	 *
	 * False for a generation that recorded nothing about its failure, which is all that is known about
	 * the runs that predate the mark.
	 */
	public function isLastFailureNonDomain(): bool
	{
		return $this->getData(self::DATA_LAST_FAILURE_KIND_KEY) === self::FAILURE_KIND_NON_DOMAIN;
	}

	private function normalizeMetrikaStatus(mixed $status): ?string
	{
		if ($status instanceof Metrika\Statuses)
		{
			return $status->value;
		}

		if (!is_scalar($status))
		{
			return null;
		}

		$status = trim((string)$status);

		return $status !== '' ? $status : null;
	}

	private function extractFeaturePromoterSliderCode(string $text): ?string
	{
		if (preg_match('/[?&]FEATURE_PROMOTER=([a-z0-9_\\-]+)/i', $text, $matches) !== 1)
		{
			return null;
		}

		return (string)$matches[1];
	}

	/**
	 * Initializes the Scenarist object for this generation.
	 *
	 * @return bool True if successfully initialized, false otherwise.
	 */
	private function initScenarist(): bool
	{
		if (!isset(
			$this->id,
			$this->scenario,
			$this->siteData,
		))
		{
			return false;
		}

		if (!isset($this->scenarist))
		{
			$this->scenarist = new Scenarist(
				$this->scenario,
				$this,
			);
		}

		return true;
	}

	/**
	 * Persists the current generation state immediately.
	 *
	 * Useful for steps that create external entities and must checkpoint
	 * their identifiers before the step fully completes.
	 */
	public function persistState(): bool
	{
		return $this->save();
	}

	public function persistBeforeStart(bool $sendCreateEvent = false): bool
	{
		return $this->save($sendCreateEvent);
	}

	public function sendGenerationCreateEvent(): void
	{
		if (isset($this->id) && !$this->generationCreateEventSent)
		{
			$this->getEvent()->send(self::EVENT_GENERATION_CREATE);
			$this->generationCreateEventSent = true;
		}
	}

	public function discardBeforeStart(): bool
	{
		if (!isset($this->id) || $this->generationCreateEventSent)
		{
			return false;
		}

		$res = GenerationsTable::delete($this->id);
		if (!$res->isSuccess())
		{
			return false;
		}

		unset($this->id);

		return true;
	}

	/**
	 * Saves the current generation state to the database.
	 *
	 * Storage seam of the generation, kept apart for the same reason the step status write is: what a
	 * failed run records about itself - the kind of the failure, its error code, the count of the
	 * suppressed marks - is a rule of its own, observable without a database only through this call.
	 *
	 * @return bool True on success, false otherwise.
	 */
	protected function save(bool $sendCreateEvent = true): bool
	{
		if (!isset(
			$this->scenario,
			$this->siteData,
		))
		{
			return false;
		}

		$siteData = $this->siteData->toArray();
		if ($this->shouldStoreLeanSiteData())
		{
			unset(
				$siteData['wishes'],
				$siteData['blocks'],
				$siteData['titleStyleClass'],
				$siteData['colors'],
				$siteData['fonts'],
			);
		}

		$fields = [
			'SCENARIO' => $this->scenario::class,
			'STEP' => $this->step ?? null,
			'SITE_ID' => $this->siteData->getSiteId(),
			'SITE_DATA' => $siteData,
			'DATA' => $this->getData(),
			'CREATED_BY_ID' => $this->getAuthorId(),
		];

		// an instance initialized without HTML_BLOCKS knows nothing about the stored blocks and must not erase them
		if ($this->htmlBlocksLoaded)
		{
			$fields['HTML_BLOCKS'] = $this->serializeHtmlBlocks();
		}

		if (isset($this->id) && $this->id)
		{
			$res = GenerationsTable::update($this->id, $fields);
			if ($res->isSuccess())
			{
				return true;
			}
		}
		else
		{
			$res = GenerationsTable::add($fields);
			if ($res->isSuccess())
			{
				$this->id = $res->getId();
				if ($sendCreateEvent)
				{
					$this->sendGenerationCreateEvent();
				}

				return true;
			}
		}

		return false;
	}

	private function shouldStoreLeanSiteData(): bool
	{
		return $this->scenario instanceof CreateAiSite;
	}

	/**
	 * Gets the event object for sending front-end and back-end events.
	 *
	 * @return Generation\Event
	 */
	public function getEvent(): Generation\Event
	{
		if (!$this->event)
		{
			$this->event = new Generation\Event($this);
		}

		if (isset($this->siteData))
		{
			$this->event
				->setSiteId($this->siteData->getSiteId())
				->setLandingId($this->siteData->getLandingId())
			;
		}

		return $this->event;
	}

	/**
	 * Checks if a generation with the given ID exists.
	 *
	 * @param int $id Generation ID.
	 *
	 * @return bool True if exists, false otherwise.
	 */
	public static function checkExists(int $id): bool
	{
		static $generations = [];
		if (!isset($generations[$id]))
		{
			$generation = GenerationsTable::query()
				->where('ID', '=', $id)
				->fetch()
			;

			$generations[$id] = (bool)$generation;
		}

		return $generations[$id];
	}

	/**
	 * Gets block data for the given blocks as a JSON string.
	 *
	 * @param array $blocks Array of block objects.
	 *
	 * @return string JSON-encoded block data, or empty string on error.
	 */
	public function getBlocksData(array $blocks): string
	{
		$siteData = $this->getSiteData();
		$imagesSet = $siteData->getImagesSet();

		$blocksData = [];
		foreach ($blocks as $block)
		{
			$blockData = [
				'id' => $block->getId(),
				'anchor' => $block->getAnchor($block->getId()),
				'images' => $imagesSet[$block->getId()] ?? [],
			];
			$blocksData[] = $blockData;
		}
		try
		{
			$blockDataEncoded = Json::encode($blocksData);
		}
		catch (ArgumentException $e)
		{
			$blockDataEncoded = '';
		}

		return $blockDataEncoded;
	}

	/**
	 * Sends metrika analytics for an error during generation.
	 * Steps without an analytic event are not covered by analytics, errors included.
	 *
	 * @param GenerationException $e
	 *
	 * @return void
	 */
	private function sendMetrikaError(GenerationException $e): void
	{
		$analyticEvent = $this->scenarist->getCurrentStep()?->step->getAnalyticEvent();
		if ($analyticEvent === null)
		{
			return;
		}

		$metrika = $this->getMetrika($analyticEvent);
		$metrika->setStatus(GenerationErrorStatusMapper::resolve($e));
		$metrika->send();
	}

	/**
	 * @param list<array<string, mixed>>|null $stored
	 */
	private function hydrateHtmlBlocks(?array $stored): void
	{
		if ($stored === null)
		{
			$this->htmlBlocks = [];

			return;
		}

		$htmlBlocks = [];
		foreach ($stored as $item)
		{
			$htmlBlocks[] = Data\HtmlBlock::fromArray($item);
		}

		$this->htmlBlocks = $htmlBlocks;
	}

	/**
	 * @return list<array<string, mixed>>|null
	 */
	private function serializeHtmlBlocks(): ?array
	{
		if ($this->htmlBlocks === [])
		{
			return null;
		}

		return array_map(static fn(Data\HtmlBlock $htmlBlock): array => $htmlBlock->toArray(), $this->htmlBlocks);
	}
}
