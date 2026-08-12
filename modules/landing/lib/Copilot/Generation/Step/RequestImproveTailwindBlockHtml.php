<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\Step\Request\AbstractRequestTailwindBlockHtml;
use Bitrix\Landing\AI\SiteBuilder\Html\AiBlockHtmlPayloadProcessor;
use Bitrix\Landing\AI\SiteBuilder\Html\AiSiteAnchorContract;
use Bitrix\Landing\AI\SiteBuilder\Prompt\PromptCodeCatalog;
use Bitrix\Landing\Copilot\Generation\Error;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Request;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Type\Errors;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Copilot\Generation\Type\RequestEntityDto;
use Bitrix\Main\ORM\Query\Query;

class RequestImproveTailwindBlockHtml extends AbstractRequestTailwindBlockHtml
{
	private const ENTITY_NODE_CODE = 'tailwind_html_block_improve';
	private const MAX_RETRY_ATTEMPTS = 1;
	private const TIMEOUT_ERROR_MESSAGE = 'timeout waiting AI block HTML improvement response';
	private const IMPROVE_STATUS_SUCCESS = 'success';
	private const IMPROVE_STATUS_RETRYING = 'retrying';
	private const IMPROVE_STATUS_FALLBACK = 'fallback_base_html';
	private const IMPROVE_FALLBACK_SOURCE = 'base_html';

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
		$htmlBlocks = $this->getHtmlBlocksIndexed(true);
		$filter = Query::filter()->logic('or');
		foreach ($htmlBlocks as $position => $current)
		{
			$currentHtml = trim((string)($current['html'] ?? ''));
			if ($currentHtml === '' || $this->isBlockAlreadyProcessed($current))
			{
				continue;
			}

			$promptData = $this->buildPromptData(
				$current['spec'] ?? null,
				$currentHtml,
				(int)$position,
				CreateAiSiteState::getStructure($this->generation),
			);
			if ($promptData === null)
			{
				continue;
			}

			$this->registerEntity(
				$filter,
				$landingId,
				(int)$position,
				$promptData['code'],
				$promptData['markers'],
			);
		}

		if (!empty($this->entities))
		{
			$this->bindExistingRequestIds($filter);
		}

		return $this->entities;
	}

	/**
	 * @return array{code: string, markers: array<string, string>}|null
	 */
	private function buildPromptData(mixed $spec, string $currentHtml, int $position, array $structure): ?array
	{
		$specData = $this->resolveSpecData($spec);
		if ($specData === null)
		{
			return null;
		}

		$siteMap = AiSiteAnchorContract::buildSiteMap($structure);
		$markers = CreateAiSiteState::buildPromptMarkers([
			'{{block_spec_json}}' => $specData['json'],
			'{{block_position}}' => (string)($position + 1),
			'{{block_anchor}}' => AiSiteAnchorContract::buildHref($position),
			'{{site_map_anchors_json}}' => AiSiteAnchorContract::encodeSiteMap($siteMap),
			'{{current_html}}' => $currentHtml,
		]);

		return [
			'code' => PromptCodeCatalog::CREATE_AI_SITE_BLOCK_HTML_IMPROVE,
			'markers' => $markers,
		];
	}

	protected function getEntityNodeCode(): string
	{
		return self::ENTITY_NODE_CODE;
	}

	protected function isBlockAlreadyProcessed(array $htmlBlock): bool
	{
		$htmlBlock = $this->normalizeHtmlBlockState($htmlBlock);

		return !empty($htmlBlock['improved'])
			|| ($htmlBlock['improveStatus'] ?? '') === self::IMPROVE_STATUS_FALLBACK
			|| !empty($htmlBlock['htmlSkipped'])
		;
	}

	protected function applyGeneratedHtml(array &$htmlBlock, string $html): void
	{
		$htmlBlock = $this->normalizeHtmlBlockState($htmlBlock);
		$htmlBlock['html'] = $html;
		$htmlBlock['improved'] = true;
		$htmlBlock['improveStatus'] = self::IMPROVE_STATUS_SUCCESS;
		$htmlBlock['improveError'] = '';
		$htmlBlock['improveFallbackSource'] = '';
		$htmlBlock['aiMeta'] = AiBlockHtmlPayloadProcessor::getEmptyAiMeta();
	}

	protected function applyEntitySendFailureToHtmlBlock(
		RequestEntityDto $entity,
		array &$htmlBlock,
		string $reason,
	): bool
	{
		$htmlBlock = $this->normalizeHtmlBlockState($htmlBlock);
		$this->applyFallbackToBaseHtml($htmlBlock, $reason);

		return true;
	}

	protected function getInvalidResponseMessage(): string
	{
		return 'Tailwind block HTML improvement response is invalid.';
	}

	protected function getResponseLogDataType(int $position): string
	{
		return 'AI response: improved Tailwind block HTML, block ' . ($position + 1);
	}

	protected function getRequestLogDataType(int $position): string
	{
		return 'AI request: improved Tailwind block HTML, block ' . ($position + 1);
	}

	protected function handleExpiredRequest(Request $request): bool
	{
		$error = Error::createError(Errors::requestFail);
		$error->message .= ': ' . self::TIMEOUT_ERROR_MESSAGE;
		if (!$request->saveError($error))
		{
			$request->setDeleted();

			return true;
		}

		return false;
	}

	protected function handleErroredRequest(Request $request, int $position, array &$htmlBlock): bool
	{
		return $this->handleImproveFailure(
			$request,
			$position,
			$htmlBlock,
			$request->getError()?->getMessage() ?? 'Tailwind block HTML improvement request failed.',
		);
	}

	protected function handleInvalidResponse(Request $request, int $position, array &$htmlBlock): bool
	{
		return $this->handleImproveFailure(
			$request,
			$position,
			$htmlBlock,
			$this->getInvalidResponseMessage(),
		);
	}

	protected function normalizeHtmlBlockState(array $htmlBlock): array
	{
		$retryCount = (int)($htmlBlock['improveRetryCount'] ?? 0);
		$status = (string)($htmlBlock['improveStatus'] ?? '');
		$fallbackSource = (string)($htmlBlock['improveFallbackSource'] ?? '');

		$htmlBlock['improveRetryCount'] = min(max($retryCount, 0), self::MAX_RETRY_ATTEMPTS);
		$htmlBlock['improveStatus'] = in_array($status, [
			self::IMPROVE_STATUS_SUCCESS,
			self::IMPROVE_STATUS_RETRYING,
			self::IMPROVE_STATUS_FALLBACK,
		], true)
			? $status
			: ''
		;
		$htmlBlock['improveError'] = trim((string)($htmlBlock['improveError'] ?? ''));
		$htmlBlock['improveFallbackSource'] = $fallbackSource === self::IMPROVE_FALLBACK_SOURCE
			? $fallbackSource
			: ''
		;

		return $htmlBlock;
	}

	protected static function createEntityKey(int $landingId, int $position): string
	{
		return "l{$landingId}_improve_html_{$position}";
	}

	private function handleImproveFailure(Request $request, int $position, array &$htmlBlock, string $reason): bool
	{
		$htmlBlock = $this->normalizeHtmlBlockState($htmlBlock);
		$reason = trim($reason);

		if ($this->canRetryImproveRequest($htmlBlock))
		{
			$this->markBlockRetrying($htmlBlock, $reason);

			try
			{
				if ($this->retryEntityRequest($position, $request))
				{
					return true;
				}
			}
			catch (GenerationException $exception)
			{
				$reason = trim($exception->getMessage());
			}
		}

		$this->applyFallbackToBaseHtml($htmlBlock, $reason);
		$this->markRequestApplied($request);

		return true;
	}

	private function canRetryImproveRequest(array $htmlBlock): bool
	{
		return (int)($htmlBlock['improveRetryCount'] ?? 0) < self::MAX_RETRY_ATTEMPTS;
	}

	private function markBlockRetrying(array &$htmlBlock, string $reason): void
	{
		$htmlBlock['improved'] = false;
		$htmlBlock['improveRetryCount'] = min(
			self::MAX_RETRY_ATTEMPTS,
			(int)($htmlBlock['improveRetryCount'] ?? 0) + 1,
		);
		$htmlBlock['improveStatus'] = self::IMPROVE_STATUS_RETRYING;
		$htmlBlock['improveError'] = $reason;
		$htmlBlock['improveFallbackSource'] = '';
	}

	protected function applyFallbackToBaseHtml(array &$htmlBlock, string $reason): void
	{
		$baseHtml = trim((string)($htmlBlock['html'] ?? ''));
		if ($baseHtml === '')
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'Base Tailwind block HTML is missing for improvement fallback.',
			);
		}

		$htmlBlock['html'] = $baseHtml;
		$htmlBlock['improved'] = false;
		$htmlBlock['improveStatus'] = self::IMPROVE_STATUS_FALLBACK;
		$htmlBlock['improveError'] = $reason;
		$htmlBlock['improveFallbackSource'] = self::IMPROVE_FALLBACK_SOURCE;
		$htmlBlock['aiMeta'] = AiBlockHtmlPayloadProcessor::getEmptyAiMeta();
	}
}
