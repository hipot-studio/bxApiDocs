<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot;

use Bitrix\Landing;
use Bitrix\Landing\Agent;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSite;
use Bitrix\Landing\Copilot\Generation\Scenario\IScenario;
use Bitrix\Landing\Copilot\Generation\Scenario\Scenarist;
use Bitrix\Landing\Copilot\Generation\Timer;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Copilot\Model\GenerationsTable;
use Bitrix\Landing\Copilot\Services\FirstSiteGenerationService;
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

	private int $id;
	private IScenario $scenario;
	private Data\Site $siteData;
	private ?array $data = null;
	/** @var list<Data\HtmlBlock> */
	private array $htmlBlocks = [];
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
	 */
	public function getHtmlBlocks(): array
	{
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
		if ($generationId <= 0)
		{
			return false;
		}

		FirstSiteGenerationService::setCurrentGenerationId($generationId);

		$filter =
			Query::filter()
				->where('ID', '=', $generationId)
		;

		return $this->initExists($filter);
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
	 * Initializes this instance with data from an existing generation matching the filter.
	 * @param Filter\ConditionTree $filter ORM filter for the query.
	 *
	 * @return bool True if found and initialized, false otherwise.
	 */
	private function initExists(Filter\ConditionTree $filter): bool
	{
		$generation = GenerationsTable::query()
			->where($filter)
			->setSelect(['ID', 'SCENARIO', 'STEP', 'SITE_DATA', 'DATA', 'HTML_BLOCKS', 'CREATED_BY_ID', 'DATE_FINISHED'])
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

		$storedHtmlBlocks = $generation['HTML_BLOCKS'] ?? null;
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
	 */
	private function handleExecutionException(\Throwable $e): bool
	{
		if ($e instanceof GenerationException)
		{
			$this->rememberLastError($e);
			$this->save(false);
			$this->sendMetrikaError($e);
			$this->getEvent()->send(self::EVENT_GENERATION_ERROR);

			return false;
		}

		if ($e instanceof \RuntimeException)
		{
			$this->setAgent();
			$this->getEvent()->send(self::EVENT_TIMER);

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
	 * @return void
	 */
	private function setAgent(): void
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
	 * @return bool True on success, false otherwise.
	 */
	private function save(bool $sendCreateEvent = true): bool
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
			'HTML_BLOCKS' => $this->serializeHtmlBlocks(),
			'CREATED_BY_ID' => $this->getAuthorId(),
		];

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
	 *
	 * @param GenerationException $e
	 *
	 * @return void
	 */
	private function sendMetrikaError(GenerationException $e): void
	{
		$errorCode = $e->getErrorCode();
		/**
		 * @var Metrika\Statuses $status
		 */
		$status = match ($errorCode)
		{
			GenerationErrors::requestQuotaExceeded => $e->getParams()['metrikaStatus'] ?? Metrika\Statuses::ErrorB24,
			GenerationErrors::restrictedRequest => $e->getParams()['metrikaStatus'] ?? Metrika\Statuses::ErrorContentPolicy,
			GenerationErrors::notExistResponse,
			GenerationErrors::notFullyResponse,
			GenerationErrors::notCorrectResponse => Metrika\Statuses::ErrorProvider,
			default => Metrika\Statuses::ErrorB24,
		};

		$step = $this->scenarist->getCurrentStep();
		$analyticEvent = $step?->step->getAnalyticEvent() ?? Metrika\Events::unknown;

		$metrika = $this->getMetrika($analyticEvent);
		$metrika->setStatus($status);
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
