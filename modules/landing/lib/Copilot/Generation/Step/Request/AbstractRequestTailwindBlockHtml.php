<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step\Request;

use Bitrix\Landing\AI\SiteBuilder\Dto\TailwindBlockSpecDto;
use Bitrix\Landing\AI\SiteBuilder\Html\AiFontAwesomeIconNormalizer;
use Bitrix\Landing\AI\SiteBuilder\Html\AiBlockHtmlPayloadProcessor;
use Bitrix\Landing\Copilot\Connector\AI;
use Bitrix\Landing\Copilot\Connector\AI\Prompt;
use Bitrix\Landing\Copilot\Connector\Schema;
use Bitrix\Landing\Copilot\Data\Site;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Log;
use Bitrix\Landing\Copilot\Generation\Request;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Base\RuntimeRequestQuotaProvider;
use Bitrix\Landing\Copilot\Generation\Step\Helper\CrmFormColorNormalizer;
use Bitrix\Landing\Copilot\Generation\Step\Helper\CrmFormHtmlPreparer;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Copilot\Generation\Type\RequestEntities;
use Bitrix\Landing\Copilot\Generation\Type\RequestEntityDto;
use Bitrix\Landing\Copilot\Generation\Type\RequestQuotaDto;
use Bitrix\Landing\Copilot\Model\RequestToEntitiesTable;
use Bitrix\Landing\Metrika;
use Bitrix\Main\ORM\Query\Query;

abstract class AbstractRequestTailwindBlockHtml extends RequestMultiple implements RuntimeRequestQuotaProvider
{
	private const CRM_FORM_MARKER = '#CRM_FORM#';
	private const CRM_FORM_COLORS_ATTR = 'data-crm-form-colors';
	private const CRM_FORM_CONTRACT_ROOT_NODE_ID = 'create-ai-site-crm-form-contract-root';

	public function __construct()
	{
		parent::__construct();
		$this->initializeConnector();
	}

	public static function getConnectorClass(): string
	{
		return AI\CreateAiSiteText::class;
	}

	public static function getRequestQuota(Site $siteData): ?RequestQuotaDto
	{
		return null;
	}

	public function getRuntimeRequestQuota(Site $siteData): ?RequestQuotaDto
	{
		$requestCount = count(CreateAiSiteState::getHtmlBlocks($this->generation));
		if ($requestCount <= 0)
		{
			return null;
		}

		return new RequestQuotaDto(static::getConnectorClass(), $requestCount);
	}

	public function getAnalyticEvent(): ?Metrika\Events
	{
		return Metrika\Events::textsGeneration;
	}

	protected function sendRequests(): bool
	{
		if (!isset($this->siteData, $this->stepId))
		{
			return false;
		}

		foreach ($this->getEntitiesToRequest() as $entity)
		{
			if (!$this->canSendEntityRequest($entity))
			{
				continue;
			}

			$this->assertTimerNotExceeded();
			$request = $this->sendEntityRequestWithRetry(
				$entity,
				fn(): ?Request => $this->dispatchEntityRequest($entity),
			);
			if ($request)
			{
				$this->attachRequestToEntity($entity, $request);

				// Persist sync responses as soon as possible, so resume logic keeps visible progress.
				if ($request->isReceived() && $this->applyResponses())
				{
					$this->changed = true;
				}
			}
		}

		return true;
	}

	protected function applyResponses(): bool
	{
		$htmlBlocks = $this->getHtmlBlocksIndexed(true);
		$relations = $this->loadRequestRelations();
		$changed = false;

		foreach ($this->requests as $request)
		{
			$position = $this->resolveRequestPosition($request, $relations);
			if ($position === null || !isset($htmlBlocks[$position]))
			{
				continue;
			}

			if ($this->isBlockAlreadyProcessed($htmlBlocks[$position]))
			{
				$this->markRequestApplied($request);
				continue;
			}

			if (!$request->isReceived() && !$request->isApplied())
			{
				continue;
			}

			if ($request->getError() !== null)
			{
				if ($this->handleErroredRequest($request, $position, $htmlBlocks[$position]))
				{
					$changed = true;
				}
				continue;
			}

			$html = $this->normalizeGeneratedHtml(
				$this->extractGeneratedHtml($request),
				$htmlBlocks[$position],
			);
			if (!$this->isGeneratedHtmlValid($html))
			{
				if ($this->handleInvalidResponse($request, $position, $htmlBlocks[$position]))
				{
					$changed = true;
				}
				continue;
			}

			if (Log::isEnabled())
			{
				(new Log($this->generation->getId()))->addResponse(
					(int)$this->stepId,
					$this->getResponseLogDataType($position),
					$request->getResult(),
				);
			}

			$this->applyGeneratedHtml($htmlBlocks[$position], $html);
			$this->markRequestApplied($request);
			$changed = true;
		}

		if ($changed)
		{
			CreateAiSiteState::setHtmlBlocks($this->generation, $this->sortHtmlBlocks($htmlBlocks));
		}

		return $changed;
	}

	protected function appendEntityFilter(object $filter, int $landingId, int $position): void
	{
		$filter->where(
			Query::filter()
				->where('LANDING_ID', '=', $landingId)
				->where('NODE_CODE', '=', $this->getEntityNodeCode())
				->where('POSITION', '=', $position)
				->where('ENTITY_TYPE', '=', RequestEntities::HtmlBlock->value)
		);
	}

	protected function bindExistingRequestIds(object $filter): void
	{
		if (empty($this->entities))
		{
			return;
		}

		$res = RequestToEntitiesTable::query()
			->setSelect([
				'REQUEST_ID',
				'LANDING_ID',
				'POSITION',
			])
			->where($filter)
			->where('STEP_REF.GENERATION_ID', '=', $this->generation->getId())
			->where('STEP_REF.STEP', '=', $this->stepId)
			->where('REQUEST_REF.DELETED', '=', 'N')
			->exec()
		;
		while ($row = $res->fetch())
		{
			$key = static::createEntityKey((int)$row['LANDING_ID'], (int)$row['POSITION']);
			if (isset($this->entities[$key]))
			{
				$this->entities[$key]->requestId = (int)$row['REQUEST_ID'];
			}
		}
	}

	protected function registerEntity(object $filter, int $landingId, int $position, string $promptCode, array $promptMarkers): void
	{
		$key = static::createEntityKey($landingId, $position);
		$this->entities[$key] = new RequestEntityDto(
			$landingId,
			0,
			$this->getEntityNodeCode(),
			$position,
			$promptCode,
			null,
			$promptMarkers,
		);
		$this->appendEntityFilter($filter, $landingId, $position);
	}

	protected function getHtmlBlocksIndexed(bool $initialize = false): array
	{
		$indexed = [];
		foreach (CreateAiSiteState::getHtmlBlocks($this->generation) as $item)
		{
			$position = (int)($item['position'] ?? -1);
			if ($position < 0)
			{
				continue;
			}

			$item['improved'] = !empty($item['improved']);
			$item['aiMeta'] = AiBlockHtmlPayloadProcessor::normalizeAiMeta($item['aiMeta'] ?? null);
			$indexed[$position] = $this->normalizeHtmlBlockState($item);
		}

		if ($initialize)
		{
			foreach (CreateAiSiteState::getStructure($this->generation) as $position => $spec)
			{
				$position = (int)$position;
				if (isset($indexed[$position]))
				{
					$indexed[$position]['spec'] ??= $spec;
					$indexed[$position] = $this->normalizeHtmlBlockState($indexed[$position]);
					continue;
				}

				$indexed[$position] = $this->createEmptyHtmlBlock($position, $spec);
			}
		}

		ksort($indexed);

		return $indexed;
	}

	/**
	 * @return array<int, array{REQUEST_ID: int, POSITION: int}>
	 */
	protected function loadRequestRelations(): array
	{
		$relations = [];
		$res = RequestToEntitiesTable::query()
			->setSelect([
				'REQUEST_ID',
				'POSITION',
			])
			->where('NODE_CODE', '=', $this->getEntityNodeCode())
			->where('ENTITY_TYPE', '=', RequestEntities::HtmlBlock->value)
			->where('STEP_REF.GENERATION_ID', '=', $this->generation->getId())
			->where('STEP_REF.STEP', '=', $this->stepId)
			->where('REQUEST_REF.DELETED', '=', 'N')
			->exec()
		;
		while ($row = $res->fetch())
		{
			$relations[(int)$row['REQUEST_ID']] = $row;
		}

		return $relations;
	}

	protected function sortHtmlBlocks(array $htmlBlocks): array
	{
		ksort($htmlBlocks);

		return array_values($htmlBlocks);
	}

	protected function getResponseLogDataType(int $position): string
	{
		return 'AI response: Tailwind block HTML, block ' . ($position + 1);
	}

	protected function getRequestLogDataType(int $position): string
	{
		return 'AI request: Tailwind block HTML, block ' . ($position + 1);
	}

	protected function markRequestApplied(Request $request): void
	{
		if (!$request->isApplied())
		{
			$request->setApplied();
		}
	}

	protected function canSendEntityRequest(RequestEntityDto $entity): bool
	{
		return isset($entity->landingId, $entity->position, $entity->prompt) && !isset($entity->requestId);
	}

	protected function dispatchEntityRequest(RequestEntityDto $entity): ?Request
	{
		$prompt = new Prompt((string)$entity->prompt);
		$prompt->setMarkers($entity->promptMarkers ?? []);
		$prompt->setSchema(new Schema\CreateAiSiteTailwindBlockHtml());

		$request = new Request($this->generation->getId(), $this->stepId);
		if (Log::isEnabled())
		{
			(new Log($this->generation->getId()))->addRequest(
				(int)$this->stepId,
				$this->getRequestLogDataType((int)$entity->position),
				$prompt->getMarkers(),
			);
		}

		return $request->send($prompt, $this->connector) ? $request : null;
	}

	protected function retryEntityRequest(int $position, Request $failedRequest): ?Request
	{
		$entity = $this->findEntityByPosition($position);
		if (!$entity)
		{
			return null;
		}

		$request = $this->dispatchEntityRequest($entity);
		if (!$request)
		{
			return null;
		}

		$this->attachRequestToEntity($entity, $request);
		$failedRequest->setDeleted();
		$failedRequestId = $failedRequest->getId();
		if ($failedRequestId !== null)
		{
			unset($this->requests[$failedRequestId]);
		}

		return $request;
	}

	protected function extractGeneratedHtml(Request $request): string
	{
		$result = $request->getResult();

		return is_array($result) ? trim((string)($result['html'] ?? '')) : '';
	}

	protected function isGeneratedHtmlValid(string $html): bool
	{
		if ($html === '' || mb_stripos($html, '<section') === false)
		{
			return false;
		}

		if (!str_contains($html, self::CRM_FORM_MARKER))
		{
			return true;
		}

		return str_contains($html, self::CRM_FORM_COLORS_ATTR)
			&& (new CrmFormHtmlPreparer())->hasMarkerColorContract($html, self::CRM_FORM_CONTRACT_ROOT_NODE_ID)
		;
	}

	protected function normalizeGeneratedHtml(string $html, array $htmlBlock): string
	{
		if ($html === '')
		{
			return $html;
		}

		$html = AiFontAwesomeIconNormalizer::normalizeHtml($html);
		if (!str_contains($html, self::CRM_FORM_MARKER))
		{
			return $html;
		}

		$html = (new CrmFormHtmlPreparer())->prepareMarkerContract(
			$html,
			self::CRM_FORM_CONTRACT_ROOT_NODE_ID,
			CrmFormColorNormalizer::buildContextFromHtmlBlock($htmlBlock, $html),
		);

		return AiFontAwesomeIconNormalizer::normalizeHtml($html);
	}

	protected function handleErroredRequest(Request $request, int $position, array &$htmlBlock): bool
	{
		throw new GenerationException(
			GenerationErrors::notCorrectResponse,
			$request->getError()?->getMessage() ?? 'Tailwind block HTML request failed.',
		);
	}

	protected function handleInvalidResponse(Request $request, int $position, array &$htmlBlock): bool
	{
		throw new GenerationException(
			GenerationErrors::notCorrectResponse,
			$this->getInvalidResponseMessage(),
		);
	}

	protected function normalizeHtmlBlockState(array $htmlBlock): array
	{
		return $htmlBlock;
	}

	/**
	 * @param array<int, array{REQUEST_ID: int, POSITION: int}> $relations
	 */
	protected function resolveRequestPosition(Request $request, array $relations): ?int
	{
		$relation = $relations[$request->getId()] ?? null;
		if (!is_array($relation))
		{
			return null;
		}

		$position = (int)($relation['POSITION'] ?? -1);

		return $position >= 0 ? $position : null;
	}

	protected function findEntityByPosition(int $position): ?RequestEntityDto
	{
		foreach ($this->getEntitiesToRequest() as $entity)
		{
			if ((int)($entity->position ?? -1) === $position)
			{
				return $entity;
			}
		}

		return null;
	}

	protected function handleEntitySendFailure(RequestEntityDto $entity, string $reason): bool
	{
		$position = (int)($entity->position ?? -1);
		if ($position < 0)
		{
			return false;
		}

		$htmlBlocks = $this->getHtmlBlocksIndexed(true);
		if (!isset($htmlBlocks[$position]))
		{
			return false;
		}

		if (!$this->applyEntitySendFailureToHtmlBlock($entity, $htmlBlocks[$position], $reason))
		{
			return false;
		}

		if (!$this->attachAppliedEntityErrorRequest($entity, RequestEntities::HtmlBlock, $reason))
		{
			return false;
		}

		CreateAiSiteState::setHtmlBlocks($this->generation, $this->sortHtmlBlocks($htmlBlocks));

		return true;
	}

	private function attachRequestToEntity(RequestEntityDto $entity, Request $request): void
	{
		$requestId = $request->getId();
		if ($requestId === null)
		{
			return;
		}

		$this->requests[$requestId] = $request;
		$entity->requestId = $requestId;

		RequestToEntitiesTable::add([
			'REQUEST_ID' => $requestId,
			'ENTITY_TYPE' => RequestEntities::HtmlBlock->value,
			'LANDING_ID' => $entity->landingId,
			'NODE_CODE' => $this->getEntityNodeCode(),
			'POSITION' => $entity->position,
		]);
	}

	private function createEmptyHtmlBlock(int $position, mixed $spec): array
	{
		return $this->normalizeHtmlBlockState([
			'position' => $position,
			'spec' => $spec,
			'html' => '',
			'blockId' => 0,
			'anchor' => '',
			'improved' => false,
			'aiMeta' => AiBlockHtmlPayloadProcessor::getEmptyAiMeta(),
		]);
	}

	/**
	 * @return array{json: string, data: array}|null
	 */
	protected function resolveSpecData(mixed $spec): ?array
	{
		$dto = TailwindBlockSpecDto::fromArray(is_array($spec) ? $spec : []);
		if ($dto === null)
		{
			return null;
		}

		$json = $dto->toJson(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
		if ($json === null)
		{
			return null;
		}

		return [
			'json' => $json,
			'data' => $dto->toArray(),
		];
	}

	abstract protected function getEntityNodeCode(): string;

	abstract protected function isBlockAlreadyProcessed(array $htmlBlock): bool;

	abstract protected function applyGeneratedHtml(array &$htmlBlock, string $html): void;

	abstract protected function applyEntitySendFailureToHtmlBlock(
		RequestEntityDto $entity,
		array &$htmlBlock,
		string $reason,
	): bool;

	abstract protected function getInvalidResponseMessage(): string;

	abstract protected static function createEntityKey(int $landingId, int $position): string;
}
