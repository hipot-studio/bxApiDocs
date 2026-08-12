<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Connector\AI;
use Bitrix\Landing\Copilot\Connector\AI\Prompt;
use Bitrix\Landing\Copilot\Connector\Schema;
use Bitrix\Landing\AI\SiteBuilder\Prompt\PromptCodeCatalog;
use Bitrix\Landing\Copilot\Data\Site;
use Bitrix\Landing\Copilot\Generation\Log;
use Bitrix\Landing\Copilot\Generation\Request;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Base\RuntimeRequestQuotaProvider;
use Bitrix\Landing\Copilot\Generation\Step\Helper\ChangeAiSiteHtmlContractValidator;
use Bitrix\Landing\Copilot\Generation\Step\Helper\ChangeAiSiteHtmlQualityDiagnostics;
use Bitrix\Landing\Copilot\Generation\Step\Helper\ChangeAiSitePlacementContextBuilder;
use Bitrix\Landing\Copilot\Generation\Step\Request\RequestMultiple;
use Bitrix\Landing\Copilot\Generation\Type\RequestEntities;
use Bitrix\Landing\Copilot\Generation\Type\RequestEntityDto;
use Bitrix\Landing\Copilot\Generation\Type\RequestQuotaDto;
use Bitrix\Landing\Copilot\Model\RequestToEntitiesTable;
use Bitrix\Landing\Metrika;
use Bitrix\Main\ORM\Query\Query;

class RequestImproveChangeAiSiteBlockHtml extends RequestMultiple implements RuntimeRequestQuotaProvider
{
	private const ENTITY_NODE_CODE = 'change_ai_site_html_block_improve';
	private const ACTION_ADD = 'add_block';
	private const IMPROVE_STATUS_SUCCESS = 'success';
	private const IMPROVE_STATUS_FALLBACK = 'fallback_base_html';

	public function __construct()
	{
		parent::__construct();
		if (class_exists(self::getConnectorClass()))
		{
			$this->connector = new (self::getConnectorClass())();
		}
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
		$requestCount = 0;
		foreach (ChangeAiSiteState::getHtmlBlocks($this->generation) as $item)
		{
			if (is_array($item) && self::isProspectiveImproveQuotaItem($item))
			{
				$requestCount++;
			}
		}

		return $requestCount > 0
			? new RequestQuotaDto(static::getConnectorClass(), $requestCount)
			: null
		;
	}

	public function getAnalyticEvent(): ?Metrika\Events
	{
		return Metrika\Events::textsGeneration;
	}

	protected function getEntitiesToRequest(): array
	{
		if (!isset($this->siteData))
		{
			return [];
		}

		if (!empty($this->entities))
		{
			return $this->entities;
		}

		$landingId = max(0, (int)$this->siteData->getLandingId());
		if ($landingId <= 0)
		{
			return [];
		}

		$filter = Query::filter()->logic('or');
		foreach ($this->getHtmlBlocksIndexed() as $position => $item)
		{
			if (!$this->shouldImprove($item))
			{
				continue;
			}

			$promptMarkers = $this->buildPromptMarkers($item);
			$this->registerEntity($filter, $landingId, (int)$position, $item, $this->resolveImprovePromptCode(), $promptMarkers);
		}

		if (!empty($this->entities))
		{
			$this->bindExistingRequestIds($filter);
		}

		return $this->entities;
	}

	protected function sendRequests(): bool
	{
		if (!isset($this->siteData, $this->stepId))
		{
			return false;
		}

		foreach ($this->getEntitiesToRequest() as $entity)
		{
			if (!$this->isRequestEntityReady($entity) || isset($entity->requestId))
			{
				continue;
			}

			$this->assertTimerNotExceeded();

			$prompt = new Prompt($entity->prompt);
			$prompt->setMarkers($entity->promptMarkers ?? []);
			$prompt->setSchema(new Schema\CreateAiSiteTailwindBlockHtml());

			$request = $this->sendEntityRequestWithRetry(
				$entity,
				function () use ($entity, $prompt): ?Request
				{
					$request = new Request($this->generation->getId(), $this->stepId);
					if (Log::isEnabled())
					{
						(new Log($this->generation->getId()))->addRequest(
							(int)$this->stepId,
							$this->buildRequestLogDataType($entity),
							$prompt->getMarkers(),
						);
					}

					return $request->send($prompt, $this->connector) ? $request : null;
				},
			);
			if ($request)
			{
				$this->requests[$request->getId()] = $request;
				$entity->requestId = $request->getId();

				RequestToEntitiesTable::add([
					'REQUEST_ID' => $request->getId(),
					'ENTITY_TYPE' => RequestEntities::HtmlBlock->value,
					'LANDING_ID' => $entity->landingId,
					'BLOCK_ID' => $entity->blockId,
					'NODE_CODE' => $entity->nodeCode,
					'POSITION' => $entity->position,
				]);

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
		$htmlBlocks = $this->getHtmlBlocksIndexed();
		$relations = $this->loadRequestRelations();
		$updated = false;

		foreach ($this->requests as $request)
		{
			$position = $this->resolvePositionByRequest($request, $relations);
			if ($position === null || !isset($htmlBlocks[$position]))
			{
				continue;
			}

			if (!$this->shouldImprove($htmlBlocks[$position]))
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
				$this->applyFallback($htmlBlocks[$position], $request->getError()?->getMessage() ?? 'Improve request failed.');
				$this->markRequestApplied($request);
				$updated = true;
				continue;
			}

			$html = $this->extractHtmlFromRequestResult($request->getResult());
			$contractValidation = $this->validateHtmlContract($html);
			if (!$contractValidation['isValid'])
			{
				$this->applyFallback(
					$htmlBlocks[$position],
					'Improve response is invalid.',
					$contractValidation['blockingDiagnostics'],
				);
				$this->markRequestApplied($request);
				$updated = true;
				continue;
			}

			if (Log::isEnabled())
			{
				(new Log($this->generation->getId()))->addResponse(
					(int)$this->stepId,
					$this->buildResponseLogDataType($htmlBlocks[$position], (int)$position),
					$request->getResult(),
				);
			}

			$htmlBlocks[$position]['generatedHtml'] = $html;
			$htmlBlocks[$position]['improved'] = true;
			$htmlBlocks[$position]['improveStatus'] = self::IMPROVE_STATUS_SUCCESS;
			$htmlBlocks[$position]['improveError'] = '';
			$htmlBlocks[$position]['qualityDiagnostics'] = $this->inspectQualityDiagnostics($html, $htmlBlocks[$position]);
			unset($htmlBlocks[$position]['contractDiagnostics']);
			$this->markRequestApplied($request);
			$updated = true;
		}

		if ($updated)
		{
			ChangeAiSiteState::setHtmlBlocks($this->generation, $this->sortHtmlBlocks($htmlBlocks));
		}

		return $updated;
	}

	protected function handleExpiredRequest(Request $request): bool
	{
		$htmlBlocks = $this->getHtmlBlocksIndexed();
		$position = $this->resolvePositionByRequest($request, $this->loadRequestRelations());
		if ($position !== null && isset($htmlBlocks[$position]) && $this->shouldImprove($htmlBlocks[$position]))
		{
			$this->applyFallback($htmlBlocks[$position], 'Improve request timed out.');
			ChangeAiSiteState::setHtmlBlocks($this->generation, $this->sortHtmlBlocks($htmlBlocks));
			$this->changed = true;
		}

		$this->markRequestApplied($request);

		return true;
	}

	protected function handleEntitySendFailure(RequestEntityDto $entity, string $reason): bool
	{
		$htmlBlocks = $this->getHtmlBlocksIndexed();
		$position = (int)($entity->position ?? -1);
		if ($position < 0 || !isset($htmlBlocks[$position]) || !$this->shouldImprove($htmlBlocks[$position]))
		{
			return false;
		}

		$this->applyFallback($htmlBlocks[$position], $reason);
		if (!$this->attachAppliedEntityErrorRequest($entity, RequestEntities::HtmlBlock, $reason))
		{
			return false;
		}

		ChangeAiSiteState::setHtmlBlocks($this->generation, $this->sortHtmlBlocks($htmlBlocks));

		return true;
	}

	private function shouldImprove(array $item): bool
	{
		if (trim((string)($item['generatedHtml'] ?? '')) === '')
		{
			return false;
		}
		if (trim((string)($item['generationError'] ?? '')) !== '')
		{
			return false;
		}
		if (!empty($item['improved']) || trim((string)($item['improveStatus'] ?? '')) !== '')
		{
			return false;
		}
		if ($this->isAddBlockItem($item))
		{
			return true;
		}

		return in_array($this->resolveEditMode($item), [
			ChangeAiSiteState::EDIT_MODE_BALANCED,
			ChangeAiSiteState::EDIT_MODE_GLOBAL,
		], true);
	}

	private static function isProspectiveImproveQuotaItem(array $item): bool
	{
		if (trim((string)($item['generationError'] ?? '')) !== '')
		{
			return false;
		}
		if (!empty($item['improved']) || trim((string)($item['improveStatus'] ?? '')) !== '')
		{
			return false;
		}
		if (trim((string)($item['actionType'] ?? '')) === self::ACTION_ADD)
		{
			return true;
		}

		return in_array(trim((string)($item['editMode'] ?? '')), [
			ChangeAiSiteState::EDIT_MODE_BALANCED,
			ChangeAiSiteState::EDIT_MODE_GLOBAL,
		], true);
	}

	private function resolveImprovePromptCode(): string
	{
		return PromptCodeCatalog::CHANGE_AI_SITE_BLOCK_HTML_IMPROVE;
	}

	private function buildPromptMarkers(array $item): array
	{
		return ChangeAiSiteState::buildPromptMarkers([
			'{{block_id}}' => (string)((int)($item['blockId'] ?? 0)),
			'{{user_input}}' => trim((string)($item['instruction'] ?? ChangeAiSiteState::resolveRawInput($this->generation))),
			'{{global_constraints}}' => ChangeAiSiteState::resolveGlobalConstraints($this->generation),
			'{{style_context}}' => ChangeAiSiteState::resolvePromptStyleContext($this->generation),
			'{{design_brief}}' => ChangeAiSiteState::resolveDesignBrief($this->generation),
			'{{edit_mode}}' => $this->isAddBlockItem($item) ? 'add_block' : $this->resolveEditMode($item),
			'{{placement_context}}' => $this->isAddBlockItem($item) ? $this->buildPlacementContext($item) : '',
			'{{current_html}}' => trim((string)($item['generatedHtml'] ?? '')),
		]);
	}

	private function registerEntity(
		object $filter,
		int $landingId,
		int $position,
		array $item,
		string $promptCode,
		array $promptMarkers,
	): void
	{
		$key = self::createEntityKey($landingId, $position);
		$this->entities[$key] = new RequestEntityDto(
			$landingId,
			(int)($item['blockId'] ?? 0),
				self::ENTITY_NODE_CODE,
				$position,
				$promptCode,
				null,
				$promptMarkers,
			);
		$this->appendEntityFilter($filter, $landingId, (int)($item['blockId'] ?? 0), $position);
	}

	private function getHtmlBlocksIndexed(): array
	{
		$indexed = [];
		foreach (ChangeAiSiteState::getHtmlBlocks($this->generation) as $position => $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$indexed[(int)$position] = $item;
		}

		return $indexed;
	}

	private function sortHtmlBlocks(array $htmlBlocks): array
	{
		ksort($htmlBlocks);

		return array_values($htmlBlocks);
	}

	private function appendEntityFilter(object $filter, int $landingId, int $blockId, int $position): void
	{
		$filter->where(
			Query::filter()
				->where('LANDING_ID', '=', $landingId)
				->where('BLOCK_ID', '=', $blockId)
				->where('NODE_CODE', '=', self::ENTITY_NODE_CODE)
				->where('POSITION', '=', $position)
				->where('ENTITY_TYPE', '=', RequestEntities::HtmlBlock->value)
		);
	}

	private function bindExistingRequestIds(object $filter): void
	{
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
			$key = self::createEntityKey((int)$row['LANDING_ID'], (int)$row['POSITION']);
			if (isset($this->entities[$key]))
			{
				$this->entities[$key]->requestId = (int)$row['REQUEST_ID'];
			}
		}
	}

	protected function loadRequestRelations(): array
	{
		$relations = [];
		$res = RequestToEntitiesTable::query()
			->setSelect([
				'REQUEST_ID',
				'POSITION',
			])
			->where('NODE_CODE', '=', self::ENTITY_NODE_CODE)
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

	private function resolvePositionByRequest(Request $request, array $relations): ?int
	{
		$requestId = (int)($request->getId() ?? 0);
		if ($requestId <= 0 || !isset($relations[$requestId]))
		{
			return null;
		}

		return (int)($relations[$requestId]['POSITION'] ?? -1);
	}

	/**
	 * @param list<string> $contractDiagnostics
	 */
	private function applyFallback(array &$item, string $reason, array $contractDiagnostics = []): void
	{
		$item['improved'] = false;
		$item['improveStatus'] = self::IMPROVE_STATUS_FALLBACK;
		$item['improveError'] = trim($reason);
		$item['qualityDiagnostics'] = $this->inspectQualityDiagnostics((string)($item['generatedHtml'] ?? ''), $item);
		if ($contractDiagnostics !== [])
		{
			$item['contractDiagnostics'] = $contractDiagnostics;
		}
		else
		{
			unset($item['contractDiagnostics']);
		}
	}

	private function extractHtmlFromRequestResult(mixed $result): string
	{
		if (!is_array($result))
		{
			return '';
		}

		return trim((string)($result['html'] ?? ''));
	}

	private function buildResponseLogDataType(array $item, int $position): string
	{
		return 'AI response: improved ChangeAiSite block HTML, block '
			. (int)($item['blockId'] ?? 0)
			. ', position '
			. $position
		;
	}

	private function isRequestEntityReady(RequestEntityDto $entity): bool
	{
		return isset(
			$entity->landingId,
			$entity->blockId,
			$entity->nodeCode,
			$entity->position,
			$entity->prompt,
		);
	}

	private function buildRequestLogDataType(RequestEntityDto $entity): string
	{
		return 'AI request: improved ChangeAiSite block HTML, block '
			. (int)$entity->blockId
			. ', position '
			. (int)$entity->position
		;
	}

	protected function markRequestApplied(Request $request): void
	{
		if (!$request->isApplied())
		{
			$request->setApplied();
		}
	}

	private function isAddBlockItem(array $item): bool
	{
		return trim((string)($item['actionType'] ?? '')) === self::ACTION_ADD;
	}

	private function resolveEditMode(array $item): string
	{
		$editMode = trim((string)($item['editMode'] ?? ''));
		if (in_array($editMode, [
			ChangeAiSiteState::EDIT_MODE_TARGETED,
			ChangeAiSiteState::EDIT_MODE_BALANCED,
			ChangeAiSiteState::EDIT_MODE_GLOBAL,
		], true))
		{
			return $editMode;
		}

		return ChangeAiSiteState::resolveActionEditMode(
			$this->generation,
			(string)($item['actionId'] ?? ''),
			(int)($item['blockId'] ?? 0),
		);
	}

	private function buildPlacementContext(array $item): string
	{
		return (new ChangeAiSitePlacementContextBuilder())->build(
			$item,
			ChangeAiSiteState::getCandidates($this->generation),
		);
	}

	private function inspectQualityDiagnostics(string $html, array $item): array
	{
		return (new ChangeAiSiteHtmlQualityDiagnostics())->inspect(
			$html,
			ChangeAiSiteState::getStyleContext($this->generation),
			$this->isAddBlockItem($item),
		);
	}

	/**
	 * @return array{isValid: bool, blockingDiagnostics: list<string>, warningDiagnostics: list<string>}
	 */
	private function validateHtmlContract(string $html): array
	{
		if (!ChangeAiSiteState::isHtmlContractValidationEnabled($this->generation))
		{
			return [
				'isValid' => true,
				'blockingDiagnostics' => [],
				'warningDiagnostics' => [],
			];
		}

		return (new ChangeAiSiteHtmlContractValidator())->validate($html);
	}

	protected static function createEntityKey(int $landingId, int $position): string
	{
		return "l{$landingId}_change_improve_html_{$position}";
	}
}
