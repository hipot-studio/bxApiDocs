<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\Step\Request\RequestMultiple;
use Bitrix\Landing\Copilot\Generation\Step\Helper\ChangeAiSiteDomHelper;
use Bitrix\Landing\Copilot\Generation\Step\Helper\ChangeAiSiteHtmlContractValidator;
use Bitrix\Landing\Copilot\Generation\Step\Helper\ChangeAiSiteHtmlQualityDiagnostics;
use Bitrix\Landing\Copilot\Generation\Step\Helper\ChangeAiSitePlacementContextBuilder;
use Bitrix\Landing\AI\SiteBuilder\Prompt\PromptCodeCatalog;
use Bitrix\Landing\Copilot\Connector\AI;
use Bitrix\Landing\Copilot\Connector\AI\Prompt;
use Bitrix\Landing\Copilot\Connector\Schema;
use Bitrix\Landing\Copilot\Data\Site;
use Bitrix\Landing\Copilot\Generation\Log;
use Bitrix\Landing\Copilot\Generation\Request;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Base\RuntimeRequestQuotaProvider;
use Bitrix\Landing\Copilot\Generation\Type\RequestEntities;
use Bitrix\Landing\Copilot\Generation\Type\RequestEntityDto;
use Bitrix\Landing\Copilot\Generation\Type\RequestQuotaDto;
use Bitrix\Landing\Copilot\Model\RequestToEntitiesTable;
use Bitrix\Landing\Metrika;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Web\DOM;

class RequestChangeAiSiteBlockHtml extends RequestMultiple implements RuntimeRequestQuotaProvider
{
	private const ENTITY_NODE_CODE = 'change_ai_site_html_block';
	private const ERROR_HTML_GENERATION_FAILED = 'BLOCK_HTML_GENERATION_FAILED';
	private const ERROR_HTML_INVALID_RESPONSE = 'BLOCK_HTML_INVALID_RESPONSE';
	private const ACTION_ADD = 'add_block';
	private const CRM_FORM_MARKER = '#CRM_FORM#';
	private const CRM_FORM_COLORS_ATTR = 'data-crm-form-colors';
	private const ROOT_PROMPT_NODE_ID = 'change-ai-site-request-html-root';
	private const B24_FORM_CLASS = 'bitrix24forms';
	private const B24_FORM_CLASS_ALT = 'bitrix24form';
	private const DEFAULT_CRM_COLORS = [
		'primary' => '#ffffff',
		'primaryText' => '#333333',
		'text' => '#ffffff',
		'background' => '#ffffff00',
		'fieldBorder' => '#ffffff00',
		'fieldBackground' => '#00000011',
		'fieldFocusBackground' => '#00000011',
	];

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
		foreach ($this->getHtmlBlocksIndexed() as $item)
		{
			if (self::isHtmlGenerationPendingItem($item))
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

		$htmlBlocks = $this->getHtmlBlocksIndexed();
		$filter = Query::filter()->logic('or');
		$userInput = ChangeAiSiteState::resolveRawInput($this->generation);
		$globalConstraints = ChangeAiSiteState::resolveGlobalConstraints($this->generation);
		foreach ($htmlBlocks as $entityKey => $item)
		{
			if (!$this->isHtmlGenerationPending($item))
			{
				continue;
			}

			$this->appendPendingEntity($filter, $landingId, $entityKey, $item, $userInput, $globalConstraints);
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
			if (!$this->isRequestEntityReady($entity))
			{
				continue;
			}

			if (isset($entity->requestId))
			{
				continue;
			}

			$this->assertExecutionTimeAvailable();

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
			if ($this->applyRequestResponse($request, $relations, $htmlBlocks))
			{
				$updated = true;
			}
		}

		if ($updated)
		{
			ChangeAiSiteState::setHtmlBlocks(
				$this->generation,
				$this->mergeHtmlBlocksWithIndexed($htmlBlocks),
			);
		}

		return $updated;
	}

	private function applyRequestResponse(Request $request, array $relations, array &$htmlBlocks): bool
	{
		$relation = $relations[$request->getId()] ?? null;
		if (!$relation)
		{
			return false;
		}

		$entityKey = $this->resolveHtmlBlockKeyByRelation($relation, $htmlBlocks);
		if ($entityKey === '' || !isset($htmlBlocks[$entityKey]))
		{
			return false;
		}

		if (!$this->isHtmlGenerationPending($htmlBlocks[$entityKey]))
		{
			$this->markRequestApplied($request);

			return false;
		}

		if (!$request->isReceived() && !$request->isApplied())
		{
			return false;
		}

		$requestError = $request->getError();
		if ($requestError !== null)
		{
			return $this->markRequestError(
				$request,
				$htmlBlocks,
				$entityKey,
				self::ERROR_HTML_GENERATION_FAILED,
			);
		}

		$html = $this->extractHtmlFromRequestResult($request->getResult());
		$contractValidation = $this->validateHtmlContract($html);
		if (!$contractValidation['isValid'])
		{
			return $this->markRequestError(
				$request,
				$htmlBlocks,
				$entityKey,
				self::ERROR_HTML_INVALID_RESPONSE,
				$contractValidation['blockingDiagnostics'],
			);
		}

		if (Log::isEnabled())
		{
			(new Log($this->generation->getId()))->addResponse(
				(int)$this->stepId,
				$this->buildResponseLogDataType($htmlBlocks[$entityKey]),
				$request->getResult(),
			);
		}

		$htmlBlocks[$entityKey]['generatedHtml'] = $html;
		$htmlBlocks[$entityKey]['qualityDiagnostics'] = $this->inspectQualityDiagnostics(
			$html,
			$this->isAddBlockItem($htmlBlocks[$entityKey]),
		);
		unset($htmlBlocks[$entityKey]['generationError']);
		unset($htmlBlocks[$entityKey]['contractDiagnostics']);
		$this->markRequestApplied($request);

		return true;
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

	private function resolveBlockPromptCode(): string
	{
		return PromptCodeCatalog::CHANGE_AI_SITE_BLOCK_HTML;
	}

	private function buildBlockPromptMarkers(
		int $blockId,
		string $currentHtml,
		string $userInput,
		string $globalConstraints,
		string $editMode,
	): array
	{
		return ChangeAiSiteState::buildPromptMarkers([
			'{{block_id}}' => (string)$blockId,
			'{{user_input}}' => $userInput,
			'{{current_html}}' => $currentHtml,
			'{{global_constraints}}' => $globalConstraints,
			'{{style_context}}' => ChangeAiSiteState::resolvePromptStyleContext($this->generation),
			'{{design_brief}}' => ChangeAiSiteState::resolveDesignBrief($this->generation),
			'{{edit_mode}}' => $editMode,
		]);
	}

	private function resolveAddBlockPromptCode(): string
	{
		return PromptCodeCatalog::CHANGE_AI_SITE_ADD_BLOCK_HTML;
	}

	private function buildAddBlockPromptMarkers(
		string $userInput,
		string $globalConstraints,
		string $placementContext,
	): array
	{
		return ChangeAiSiteState::buildPromptMarkers([
			'{{user_input}}' => $userInput,
			'{{global_constraints}}' => $globalConstraints,
			'{{placement_context}}' => $placementContext,
			'{{style_context}}' => ChangeAiSiteState::resolvePromptStyleContext($this->generation),
			'{{design_brief}}' => ChangeAiSiteState::resolveDesignBrief($this->generation),
		]);
	}

	private function appendPendingEntity(
		object $filter,
		int $landingId,
		string $entityKey,
		array $item,
		string $userInput,
		string $globalConstraints,
	): void
	{
		$blockId = (int)($item['blockId'] ?? 0);
		$position = (int)($item['_entityPosition'] ?? 0);
		$currentHtml = (string)($item['originalHtml'] ?? '');
		if ($this->isCrmBlockHtml($currentHtml))
		{
			$currentHtml = $this->normalizeCurrentHtmlForPrompt($currentHtml);
		}
		$effectiveInput = $this->resolvePromptInputForItem($item, $userInput);

		$promptMarkers = $this->isAddBlockItem($item)
			? $this->buildAddBlockPromptMarkers(
				$effectiveInput,
				$globalConstraints,
				$this->buildPlacementContext($item),
			)
			: $this->buildBlockPromptMarkers(
				$blockId,
				$currentHtml,
				$effectiveInput,
				$globalConstraints,
				$this->resolveEditModeForItem($item),
			)
		;
		$promptCode = $this->isAddBlockItem($item)
			? $this->resolveAddBlockPromptCode()
			: $this->resolveBlockPromptCode()
		;

		$requestEntityKey = self::createEntityKey($landingId, $blockId, $position);
		$this->entities[$requestEntityKey] = new RequestEntityDto(
			$landingId,
			$blockId,
				self::ENTITY_NODE_CODE,
				$position,
				$promptCode,
				null,
				$promptMarkers,
			);

		$this->appendEntityFilter($filter, $landingId, $blockId, $position);
	}

	/**
	 * @param list<string> $contractDiagnostics
	 */
	private function markRequestError(
		Request $request,
		array &$htmlBlocks,
		string $entityKey,
		string $error,
		array $contractDiagnostics = [],
	): bool
	{
		$htmlBlocks[$entityKey]['generatedHtml'] = '';
		$htmlBlocks[$entityKey]['generationError'] = $error;
		if ($contractDiagnostics !== [])
		{
			$htmlBlocks[$entityKey]['contractDiagnostics'] = $contractDiagnostics;
		}
		else
		{
			unset($htmlBlocks[$entityKey]['contractDiagnostics']);
		}
		$this->markRequestApplied($request);

		return true;
	}

	private function extractHtmlFromRequestResult(mixed $result): string
	{
		if (!is_array($result))
		{
			return '';
		}

		return trim((string)($result['html'] ?? ''));
	}

	private function buildResponseLogDataType(array $item): string
	{
		return 'AI response: ChangeAiSite block HTML, block '
			. (int)($item['blockId'] ?? 0)
			. ', position '
			. (int)($item['_entityPosition'] ?? 0)
		;
	}

	private function isHtmlGenerationPending(array $item): bool
	{
		return self::isHtmlGenerationPendingItem($item);
	}

	private static function isHtmlGenerationPendingItem(array $item): bool
	{
		return trim((string)($item['generatedHtml'] ?? '')) === ''
			&& trim((string)($item['generationError'] ?? '')) === ''
		;
	}

	private function resolvePromptInputForBlock(int $blockId, string $fallbackInput): string
	{
		$instruction = ChangeAiSiteState::resolveBlockInstruction($this->generation, $blockId);

		return self::selectPromptInput($instruction, $fallbackInput);
	}

	private function resolvePromptInputForItem(array $item, string $fallbackInput): string
	{
		$inlineInstruction = trim((string)($item['instruction'] ?? ''));
		if ($inlineInstruction !== '')
		{
			return $inlineInstruction;
		}

		$actionInstruction = ChangeAiSiteState::resolveActionInstruction(
			$this->generation,
			(string)($item['actionId'] ?? ''),
		);
		if ($actionInstruction !== '')
		{
			return $actionInstruction;
		}

		return $this->resolvePromptInputForBlock((int)($item['blockId'] ?? 0), $fallbackInput);
	}

	private static function selectPromptInput(string $blockInstruction, string $fallbackInput): string
	{
		$blockInstruction = trim($blockInstruction);
		if ($blockInstruction !== '')
		{
			return $blockInstruction;
		}

		return trim($fallbackInput);
	}

	private static function createEntityKey(int $landingId, int $blockId, int $position = 0): string
	{
		return "l{$landingId}_b{$blockId}_p{$position}_html";
	}

	private function buildRequestLogDataType(RequestEntityDto $entity): string
	{
		return 'AI request: ChangeAiSite block HTML, block '
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

	protected function handleEntitySendFailure(RequestEntityDto $entity, string $reason): bool
	{
		$htmlBlocks = $this->getHtmlBlocksIndexed();
		$blockId = (int)($entity->blockId ?? 0);
		$position = (int)($entity->position ?? 0);
		$entityKey = $blockId > 0 ? 'block_' . $blockId : 'position_' . $position;
		if (!isset($htmlBlocks[$entityKey]) && $blockId > 0)
		{
			$entityKey = (string)$blockId;
		}
		if (!isset($htmlBlocks[$entityKey]))
		{
			return false;
		}

		$htmlBlocks[$entityKey]['generatedHtml'] = '';
		$htmlBlocks[$entityKey]['generationError'] = trim($reason);
		unset($htmlBlocks[$entityKey]['contractDiagnostics']);

		if (!$this->attachAppliedEntityErrorRequest($entity, RequestEntities::HtmlBlock, $reason))
		{
			return false;
		}

		ChangeAiSiteState::setHtmlBlocks(
			$this->generation,
			$this->mergeHtmlBlocksWithIndexed($htmlBlocks),
		);

		return true;
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

	private function assertExecutionTimeAvailable(): void
	{
		$timer = $this->generation->getTimer();
		if (!$timer->check())
		{
			throw new \RuntimeException("The maximum execution time has been reached, step {$this->stepId} was aborted");
		}
	}

	private function getHtmlBlocksIndexed(): array
	{
		$indexed = [];
		foreach (ChangeAiSiteState::getHtmlBlocks($this->generation) as $index => $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$blockId = (int)($item['blockId'] ?? 0);
			if ($blockId <= 0 && !$this->isAddBlockItem($item))
			{
				continue;
			}

			$item['_entityPosition'] = (int)$index;
			$indexed[$this->createHtmlBlockKey($item, (int)$index)] = $item;
		}

		return $indexed;
	}

	private function mergeHtmlBlocksWithIndexed(array $indexed): array
	{
		$result = [];
		foreach (ChangeAiSiteState::getHtmlBlocks($this->generation) as $index => $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$key = $this->createHtmlBlockKey($item, (int)$index);
			$result[] = isset($indexed[$key]) ? $this->clearInternalIndexedFields($indexed[$key]) : $item;
		}

		return $result;
	}

	protected function loadRequestRelations(): array
	{
		$relations = [];
		$res = RequestToEntitiesTable::query()
			->setSelect([
				'REQUEST_ID',
				'BLOCK_ID',
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

	private function bindExistingRequestIds(object $filter): void
	{
		if (empty($this->entities))
		{
			return;
		}

		$res = RequestToEntitiesTable::query()
			->setSelect([
				'REQUEST_ID',
				'LANDING_ID',
				'BLOCK_ID',
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
			$key = self::createEntityKey(
				(int)$row['LANDING_ID'],
				(int)$row['BLOCK_ID'],
				(int)($row['POSITION'] ?? 0),
			);
			if (isset($this->entities[$key]))
			{
				$this->entities[$key]->requestId = (int)$row['REQUEST_ID'];
			}
		}
	}

	private function resolveHtmlBlockKeyByRelation(array $relation, array $htmlBlocks): string
	{
		$blockId = (int)($relation['BLOCK_ID'] ?? 0);
		$position = (int)($relation['POSITION'] ?? 0);
		$key = $blockId > 0 ? 'block_' . $blockId : 'position_' . $position;
		if (isset($htmlBlocks[$key]))
		{
			return $key;
		}

		$legacyKey = (string)$blockId;

		return isset($htmlBlocks[$legacyKey]) ? $legacyKey : '';
	}

	private function createHtmlBlockKey(array $item, int $position): string
	{
		$blockId = (int)($item['blockId'] ?? 0);
		if ($blockId > 0)
		{
			return 'block_' . $blockId;
		}

		return 'position_' . $position;
	}

	private function clearInternalIndexedFields(array $item): array
	{
		unset($item['_entityPosition']);

		return $item;
	}

	private function isAddBlockItem(array $item): bool
	{
		return trim((string)($item['actionType'] ?? '')) === self::ACTION_ADD;
	}

	private function buildPlacementContext(array $item): string
	{
		return (new ChangeAiSitePlacementContextBuilder())->build(
			$item,
			ChangeAiSiteState::getCandidates($this->generation),
		);
	}

	private function resolveEditModeForItem(array $item): string
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

	private function inspectQualityDiagnostics(string $html, bool $isAddBlock): array
	{
		return (new ChangeAiSiteHtmlQualityDiagnostics())->inspect(
			$html,
			ChangeAiSiteState::getStyleContext($this->generation),
			$isAddBlock,
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

	private function isCrmBlockHtml(string $html): bool
	{
		return
			str_contains($html, self::CRM_FORM_MARKER)
			|| str_contains($html, self::B24_FORM_CLASS)
			|| str_contains($html, self::B24_FORM_CLASS_ALT)
		;
	}

	private function hasCrmFormColorsAttribute(string $html): bool
	{
		return str_contains($html, self::CRM_FORM_COLORS_ATTR);
	}

	private function normalizeCurrentHtmlForPrompt(string $html): string
	{
		if ($html === '')
		{
			return '';
		}

		try
		{
			$doc = new DOM\Document();
			$doc->loadHTML('<div id="' . self::ROOT_PROMPT_NODE_ID . '">' . $html . '</div>');
			$root = $doc->querySelector('#' . self::ROOT_PROMPT_NODE_ID);
			if (!$root)
			{
				return $html;
			}

			$this->replaceEmbedNodesWithMarker($root);
			$this->removeCrmFormScripts($root);
			$this->ensureMarkerContainerHasColors($root);

			$preparedHtml = ChangeAiSiteDomHelper::extractRootHtml($root);

			return $preparedHtml !== '' ? $preparedHtml : $html;
		}
		catch (\Throwable)
		{
			return $html;
		}
	}

	private function replaceEmbedNodesWithMarker(object $root): void
	{
		foreach ($root->querySelectorAll('div') as $node)
		{
			if (!$this->isCrmEmbedNode($node))
			{
				continue;
			}

			$this->prepareEmbedNodeForMarker($node, $this->extractCrmColors((string)$node->getAttribute('data-b24form-design')));
		}
	}

	private function removeCrmFormScripts(object $root): void
	{
		foreach ($root->querySelectorAll('script') as $scriptNode)
		{
			if ((string)$scriptNode->getAttribute('data-b24-form') === '')
			{
				continue;
			}

			$parent = $scriptNode->getParentNode();
			if ($parent)
			{
				$parent->removeChild($scriptNode);
			}
		}
	}

	private function isCrmEmbedNode(object $node): bool
	{
		$classValue = trim(mb_strtolower((string)$node->getAttribute('class')));
		if ($classValue === '')
		{
			return false;
		}

		$classes = preg_split('/\s+/', $classValue) ?: [];

		return in_array(self::B24_FORM_CLASS, $classes, true)
			|| in_array(self::B24_FORM_CLASS_ALT, $classes, true)
		;
	}

	private function ensureMarkerContainerHasColors(object $root): void
	{
		foreach ($root->querySelectorAll('*') as $node)
		{
			$innerHtml = (string)$node->getInnerHTML();
			if (!str_contains($innerHtml, self::CRM_FORM_MARKER))
			{
				continue;
			}

			if ((string)$node->getAttribute(self::CRM_FORM_COLORS_ATTR) === '')
			{
				$node->setAttribute(
					self::CRM_FORM_COLORS_ATTR,
					$this->encodeCrmColors(self::DEFAULT_CRM_COLORS),
				);
			}

			return;
		}
	}

	private function prepareEmbedNodeForMarker(object $node, array $colors): void
	{
		foreach (
			[
				'data-b24form',
				'data-b24form-embed',
				'data-b24form-design',
				'data-b24form-use-style',
				'data-b24form-connector',
				'data-b24form-original-domain',
				'data-b24form-show-header',
			] as $attribute
		)
		{
			$node->removeAttribute($attribute);
		}

		$classValue = trim((string)$node->getAttribute('class'));
		if ($classValue !== '')
		{
			$filtered = array_values(array_filter(
				preg_split('/\s+/', $classValue) ?: [],
				static fn(string $className): bool => !in_array(
					$className,
					[self::B24_FORM_CLASS, self::B24_FORM_CLASS_ALT, 'g-brd-white-opacity-0_6', 'u-form-alert-v3'],
					true,
				),
			));

			if ($filtered === [])
			{
				$node->removeAttribute('class');
			}
			else
			{
				$node->setAttribute('class', implode(' ', $filtered));
			}
		}

		$node->setAttribute(self::CRM_FORM_COLORS_ATTR, $this->encodeCrmColors($colors));
		$node->setInnerHTML(self::CRM_FORM_MARKER);
	}

	private function extractCrmColors(string $rawValue): array
	{
		$decoded = json_decode(html_entity_decode($rawValue, ENT_QUOTES | ENT_HTML5), true);
		$source = is_array($decoded) && is_array($decoded['color'] ?? null)
			? $decoded['color']
			: []
		;

		$result = self::DEFAULT_CRM_COLORS;
		foreach (self::DEFAULT_CRM_COLORS as $key => $defaultValue)
		{
			$value = $source[$key] ?? null;
			if (!is_string($value))
			{
				continue;
			}

			$value = trim($value);
			if ($value !== '')
			{
				$result[$key] = $value;
			}
		}

		return $result;
	}

	private function encodeCrmColors(array $colors): string
	{
		$prepared = self::DEFAULT_CRM_COLORS;
		foreach (self::DEFAULT_CRM_COLORS as $key => $defaultValue)
		{
			$value = $colors[$key] ?? null;
			if (!is_string($value))
			{
				continue;
			}

			$value = trim($value);
			if ($value !== '')
			{
				$prepared[$key] = $value;
			}
		}

		$encoded = json_encode($prepared, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		return is_string($encoded) ? $encoded : '{}';
	}
}
