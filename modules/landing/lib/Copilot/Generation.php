<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot;

use Bitrix\Landing;
use Bitrix\Landing\Agent;
use Bitrix\Landing\Copilot\Connector\Chat\ChatBotMessageDto;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Scenario\IScenario;
use Bitrix\Landing\Copilot\Generation\Scenario\Scenarist;
use Bitrix\Landing\Copilot\Generation\Timer;
use Bitrix\Landing\Copilot\Model\GenerationsTable;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\ORM\Query\Filter;
use Bitrix\Main\Web\Json;

/**
 * This class is responsible for generating site content using various managers and connectors.
 */
class Generation
{
	private const EVENT_ERROR = 'onCopilotError';
	private const EVENT_TIMER = 'onCopilotTimeIsOver';
	private const EVENT_GENERATION_CREATE = 'onGenerationCreate';
	private const EVENT_GENERATION_ERROR = 'onGenerationError';
	private const EVENT_GENERATION_FINISH = 'onGenerationFinish';

	private int $id;
	private ?int $chatId = null;
	private IScenario $scenario;
	private Data\Site $siteData;
	private ?array $data = null;
	private int $step;
	private int $authorId;

	private Scenarist $scenarist;
	private Timer $timer;
	protected ?Generation\Event $event = null;

	public function __construct()
	{
		$this->authorId = Landing\Manager::getUserId();

		$this->siteData = new Data\Site();
		$this->timer = new Timer();
	}

	/**
	 * ID of user who created the site
	 * @return int
	 */
	public function getAuthorId(): int
	{
		return $this->authorId;
	}

	/**
	 * If generation start by chat - set ID
	 * @param int $chatId
	 * @return Generation
	 */
	public function setChatId(int $chatId): self
	{
		if ($chatId > 0)
		{
			$this->chatId = $chatId;
		}

		return $this;
	}

	/**
	 * If generation started by chat - get ID
	 * @return int|null
	 */
	public function getChatId(): ?int
	{
		return $this->chatId;
	}

	/**
	 * @param IScenario $scenario
	 * @return Generation
	 */
	public function setScenario(IScenario $scenario): self
	{
		$this->scenario = $scenario;

		return $this;
	}

	/**
	 * @return IScenario|null
	 */
	public function getScenario(): ?IScenario
	{
		return $this->scenario ?? null;
	}

	public function setSiteData(Data\Site $siteData): self
	{
		$this->siteData = $siteData;

		return $this;
	}

	public function getSiteData(): Data\Site
	{
		return $this->siteData;
	}

	/**
	 * Get custom data for generations
	 * @param string|null $key
	 * @return mixed|null - null if key not set
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
	 * Set custom data for generations
	 * @param string $key
	 * @param mixed $data
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
	 * Delete one key from data
	 * @param string $key
	 * @return void
	 */
	public function deleteData(string $key): void
	{
		if (is_array($this->data) && array_key_exists($key, $this->data))
		{
			unset($this->data[$key]);
		}
	}

	public function setWishes(Data\Wishes $wishes): self
	{
		$this->siteData->setWishes($wishes);

		return $this;
	}

	public function getId(): ?int
	{
		return $this->id ?? null;
	}

	public function getStep(): ?int
	{
		return $this->step ?? null;
	}

	public function getTimer(): Timer
	{
		return $this->timer;
	}

	/**
	 * Try to find exists generation and init current by them data
	 *
	 * @param int $generationId
	 *
	 * @return bool
	 */
	public function initById(int $generationId): bool
	{
		if ($generationId <= 0)
		{
			return false;
		}

		$filter =
			Query::filter()
				->where('ID', '=', $generationId)
		;

		return $this->initExists($filter);
	}

	/**
	 * Try to find exists generation by site ID and init current by them data
	 *
	 * @param int $siteId
	 * @param IScenario $scenario
	 * @return bool
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

	private function initExists(Filter\ConditionTree $filter): bool
	{
		$generation = GenerationsTable::query()
			->where($filter)
			->setSelect(['ID', 'SCENARIO', 'STEP', 'CHAT_ID', 'SITE_DATA', 'DATA', 'CREATED_BY_ID'])
			->fetch()
		;

		if (!$generation)
		{
			return false;
		}

		$this->id = (int)$generation['ID'];
		$this->authorId = (int)$generation['CREATED_BY_ID'];

		if (isset($generation['STEP']))
		{
			$this->step = (int)$generation['STEP'];
		}

		if (isset($generation['CHAT_ID']))
		{
			$this->setChatId((int)$generation['CHAT_ID']);
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

		if (!$this->initScenarist())
		{
			return false;
		}

		return true;
	}

	/**
	 * Run process.
	 * @return bool - false if error while executing
	 */
	public function execute(): bool
	{
		if (!isset($this->id) && !$this->save())
		{
			return false;
		}

		$connection = Application::getConnection();
		if (!$connection->lock($this->getLockName()))
		{
			$this->setAgent();

			return false;
		}

		if (
			!$this->isExecutable()
			|| !$this->initScenarist()
		)
		{
			return false;
		}

		$this->timer->start();
		$this->deleteAgent();

		try
		{
			$this->scenarist
				->onStepChange(fn(int $newStep) => $this->step = $newStep)
				->onSave(fn() => $this->save())
				->execute()
			;

			if ($this->scenarist->isFinished())
			{
				$this->onFinish();
			}
		}
		catch (GenerationException $e)
		{
			$this->scenario->getChatbot()?->sendErrorMessage(new ChatBotMessageDto(
				$this->getChatId() ?? 0,
				$this->id,
				$e->getCodeObject(),
				$e->getParams(),
			));
			$this->getEvent()->send(self::EVENT_GENERATION_ERROR);

			return false;
		}
		catch (\RuntimeException $e)
		{
			$this->setAgent();
			$this->getEvent()->send(self::EVENT_TIMER);

			return false;
		}
		catch (\Exception $e)
		{
			$this->getEvent()->sendError(
				self::EVENT_ERROR,
				$e->getMessage(),
			);

			return false;
		}

		$connection->unlock($this->getLockName());

		return true;
	}

	private function isExecutable(): bool
	{
		return isset(
			$this->id,
			$this->siteData,
			$this->scenario,
		);
	}

	private function getLockName(): string
	{
		return 'landing_copilot_generation_' . ($this->id ?? 0);
	}

	private function setAgent(): void
	{
		Agent::addUniqueAgent('executeGeneration', [$this->id], 60, 10);
	}

	private function deleteAgent(): void
	{
		Agent::deleteUniqueAgent('executeGeneration', [$this->id]);
	}

	/**
	 * Finish all generation processed, mark as completed
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
	 * Check if scenario execute all steps
	 * @return bool
	 */
	public function isFinished(): bool
	{
		if (!$this->initScenarist())
		{
			return false;
		}

		return $this->scenarist->isFinished();
	}

	private function onFinish(): void
	{
		$this->getEvent()->send(self::EVENT_GENERATION_FINISH);
	}

	/**
	 * Check if at least one scenario step has error and not execute
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
	 * Prepare scenario to restart generation after error
	 * @return $this
	 */
	public function clearErrors(): self
	{
		if ($this->initScenarist())
		{
			$this->scenarist->clearErrors();
		}

		return $this;
	}

	private function initScenarist(): bool
	{
		if (!isset(
			$this->id,
			$this->scenario,
			$this->siteData
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

	private function save(): bool
	{
		if (!isset(
			$this->scenario,
			$this->siteData,
		))
		{
			return false;
		}

		$fields = [
			'SCENARIO' => $this->scenario::class,
			'STEP' => $this->step ?? null,
			'CHAT_ID' => $this->getChatId(),
			'SITE_ID' => $this->siteData->getSiteId(),
			'SITE_DATA' => $this->siteData->toArray(),
			'DATA' => $this->getData(),
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
				$this->getEvent()->send(self::EVENT_GENERATION_CREATE);

				return true;
			}
		}

		return false;
	}

	/**
	 * Get object for send front and backend events
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
}