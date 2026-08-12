<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\Step\Request\AbstractRequestTailwindBlockHtml;
use Bitrix\Landing\AI\SiteBuilder\Html\AiBlockHtmlPayloadProcessor;
use Bitrix\Landing\AI\SiteBuilder\Html\AiSiteAnchorContract;
use Bitrix\Landing\AI\SiteBuilder\Prompt\PromptCodeCatalog;
use Bitrix\Landing\Copilot\Generation\Error;
use Bitrix\Landing\Copilot\Generation\Request;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Type\Errors;
use Bitrix\Landing\Copilot\Generation\Type\RequestEntityDto;
use Bitrix\Main\ORM\Query\Query;

class RequestTailwindBlockHtml extends AbstractRequestTailwindBlockHtml
{
	private const ENTITY_NODE_CODE = 'tailwind_html_block';
	private const MAX_RETRY_ATTEMPTS = 1;
	private const TIMEOUT_ERROR_MESSAGE = 'timeout waiting AI block HTML response';
	private const HTML_STATUS_SUCCESS = 'success';
	private const HTML_STATUS_RETRYING = 'retrying';
	private const HTML_STATUS_SKIPPED = 'skipped';

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
		$structure = CreateAiSiteState::getStructure($this->generation);
		if ($structure === [])
		{
			return [];
		}

		$htmlBlocks = $this->getHtmlBlocksIndexed();
		$filter = Query::filter()->logic('or');
		foreach ($structure as $position => $blockSpec)
		{
			$position = (int)$position;
			if ($this->isBlockAlreadyProcessed($htmlBlocks[$position] ?? []))
			{
				continue;
			}

			$promptData = $this->buildPromptData($blockSpec, $position, $structure);
			if ($promptData === null)
			{
				continue;
			}

			$this->registerEntity(
				$filter,
				$landingId,
				$position,
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
	private function buildPromptData(mixed $blockSpec, int $position, array $structure): ?array
	{
		$specData = $this->resolveSpecData($blockSpec);
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
			'{{block_context}}' => (string)$specData['data']['context'],
			'{{block_navigation_title}}' => (string)$specData['data']['navigation_title'],
			'{{block_goal}}' => (string)$specData['data']['goal'],
			'{{block_outline}}' => (string)$specData['data']['outline'],
			'{{block_content}}' => (string)$specData['data']['content'],
			'{{block_style}}' => (string)$specData['data']['style'],
			'{{block_palette}}' => (string)$specData['data']['palette'],
		]);

		return [
			'code' => PromptCodeCatalog::CREATE_AI_SITE_BLOCK_HTML,
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

		return trim((string)($htmlBlock['html'] ?? '')) !== '' || !empty($htmlBlock['htmlSkipped']);
	}

	protected function applyGeneratedHtml(array &$htmlBlock, string $html): void
	{
		$htmlBlock = $this->normalizeHtmlBlockState($htmlBlock);
		$htmlBlock['html'] = $html;
		$htmlBlock['improved'] = false;
		$htmlBlock['htmlStatus'] = self::HTML_STATUS_SUCCESS;
		$htmlBlock['htmlError'] = '';
		$htmlBlock['htmlSkipReason'] = '';
		$htmlBlock['htmlSkipped'] = false;
		$htmlBlock['aiMeta'] = AiBlockHtmlPayloadProcessor::getEmptyAiMeta();
	}

	protected function applyEntitySendFailureToHtmlBlock(
		RequestEntityDto $entity,
		array &$htmlBlock,
		string $reason,
	): bool
	{
		$htmlBlock = $this->normalizeHtmlBlockState($htmlBlock);
		$this->markBlockSkipped($htmlBlock, $reason);

		return true;
	}

	protected function getInvalidResponseMessage(): string
	{
		return 'Tailwind block HTML response is invalid.';
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
		return $this->handleHtmlFailure(
			$request,
			$position,
			$htmlBlock,
			$request->getError()?->getMessage() ?? 'Tailwind block HTML request failed.',
		);
	}

	protected function handleInvalidResponse(Request $request, int $position, array &$htmlBlock): bool
	{
		return $this->handleHtmlFailure(
			$request,
			$position,
			$htmlBlock,
			$this->getInvalidResponseMessage(),
		);
	}

	protected function normalizeHtmlBlockState(array $htmlBlock): array
	{
		$retryCount = (int)($htmlBlock['htmlRetryCount'] ?? 0);
		$status = (string)($htmlBlock['htmlStatus'] ?? '');

		$htmlBlock['htmlRetryCount'] = min(max($retryCount, 0), self::MAX_RETRY_ATTEMPTS);
		$htmlBlock['htmlStatus'] = in_array($status, [
			self::HTML_STATUS_SUCCESS,
			self::HTML_STATUS_RETRYING,
			self::HTML_STATUS_SKIPPED,
		], true)
			? $status
			: ''
		;
		$htmlBlock['htmlError'] = trim((string)($htmlBlock['htmlError'] ?? ''));
		$htmlBlock['htmlSkipped'] = !empty($htmlBlock['htmlSkipped']);
		$htmlBlock['htmlSkipReason'] = trim((string)($htmlBlock['htmlSkipReason'] ?? ''));

		return $htmlBlock;
	}

	protected static function createEntityKey(int $landingId, int $position): string
	{
		return "l{$landingId}_html_{$position}";
	}

	private function handleHtmlFailure(Request $request, int $position, array &$htmlBlock, string $reason): bool
	{
		$htmlBlock = $this->normalizeHtmlBlockState($htmlBlock);
		$reason = trim($reason);

		if ($this->canRetryHtmlRequest($htmlBlock))
		{
			$this->markBlockRetrying($htmlBlock, $reason);
			if ($this->retryEntityRequest($position, $request))
			{
				return true;
			}
		}

		$this->markBlockSkipped($htmlBlock, $reason);
		$this->markRequestApplied($request);

		return true;
	}

	private function canRetryHtmlRequest(array $htmlBlock): bool
	{
		return (int)($htmlBlock['htmlRetryCount'] ?? 0) < self::MAX_RETRY_ATTEMPTS;
	}

	private function markBlockRetrying(array &$htmlBlock, string $reason): void
	{
		$htmlBlock['htmlRetryCount'] = min(
			self::MAX_RETRY_ATTEMPTS,
			(int)($htmlBlock['htmlRetryCount'] ?? 0) + 1,
		);
		$htmlBlock['htmlStatus'] = self::HTML_STATUS_RETRYING;
		$htmlBlock['htmlError'] = $reason;
		$htmlBlock['htmlSkipReason'] = '';
		$htmlBlock['htmlSkipped'] = false;
		$htmlBlock['html'] = '';
	}

	protected function markBlockSkipped(array &$htmlBlock, string $reason): void
	{
		$htmlBlock['html'] = '';
		$htmlBlock['improved'] = false;
		$htmlBlock['htmlStatus'] = self::HTML_STATUS_SKIPPED;
		$htmlBlock['htmlError'] = $reason;
		$htmlBlock['htmlSkipped'] = true;
		$htmlBlock['htmlSkipReason'] = $reason;
		$htmlBlock['aiMeta'] = AiBlockHtmlPayloadProcessor::getEmptyAiMeta();
	}
}
