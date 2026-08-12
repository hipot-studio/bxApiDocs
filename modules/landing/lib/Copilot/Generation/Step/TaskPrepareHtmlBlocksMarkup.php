<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\AI\SiteBuilder\Font\AiFontPolicy;
use Bitrix\Landing\AI\SiteBuilder\Html\AiBlockHtmlPayloadProcessor;
use Bitrix\Landing\AI\SiteBuilder\Html\AiSiteAnchorContract;
use Bitrix\Landing\Copilot\Generation\Log;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Base\TaskStep;
use Bitrix\Landing\Copilot\Services\HtmlBlock\NodeValidator;

class TaskPrepareHtmlBlocksMarkup extends TaskStep
{
	public function execute(): bool
	{
		parent::execute();

		$htmlBlocks = CreateAiSiteState::getHtmlBlocks($this->generation);
		if (empty($htmlBlocks))
		{
			return true;
		}

		$processor = new AiBlockHtmlPayloadProcessor();
		$nodeValidator = new NodeValidator();
		$emptyAiMeta = AiBlockHtmlPayloadProcessor::getEmptyAiMeta();
		$siteMap = AiSiteAnchorContract::buildSiteMap(CreateAiSiteState::getStructure($this->generation));
		$changed = false;
		foreach ($htmlBlocks as $index => $item)
		{
			$originalHtml = (string)($item['html'] ?? '');
			$html = AiFontPolicy::normalizeHtmlFontClasses($originalHtml);
			$currentAiMeta = AiBlockHtmlPayloadProcessor::normalizeAiMeta($item['aiMeta'] ?? null);
			if ($html === '')
			{
				if ($currentAiMeta !== $emptyAiMeta)
				{
					$htmlBlocks[$index]['aiMeta'] = $emptyAiMeta;
					$changed = true;
				}

				continue;
			}

			if ($this->isAlreadyPreparedMarkup($html, $currentAiMeta, $nodeValidator))
			{
				continue;
			}

			$preparedBlock = $processor->prepare($html);
			$preparedHtml = (string)($preparedBlock['html'] ?? $html);
			$preparedHtml = AiSiteAnchorContract::normalizePlannedAnchors(
				$preparedHtml,
				$siteMap,
				(int)($item['position'] ?? $index),
			);
			$preparedAiMeta = AiBlockHtmlPayloadProcessor::normalizeAiMeta($preparedBlock['aiMeta'] ?? null);
			$nodeDiagnostics = $nodeValidator->validate($preparedHtml);
			if ($nodeDiagnostics !== [])
			{
				$htmlBlocks[$index]['nodeDiagnostics'] = $nodeDiagnostics;
				$this->logNodeDiagnostics((int)$index, $nodeDiagnostics);
			}
			else
			{
				unset($htmlBlocks[$index]['nodeDiagnostics']);
			}

			if (
				$preparedHtml === $originalHtml
				&& $preparedAiMeta === $currentAiMeta
				&& ($item['nodeDiagnostics'] ?? []) === ($htmlBlocks[$index]['nodeDiagnostics'] ?? [])
			)
			{
				continue;
			}

			$htmlBlocks[$index]['html'] = $preparedHtml;
			$htmlBlocks[$index]['aiMeta'] = $preparedAiMeta;
			$changed = true;
		}

		if ($changed)
		{
			CreateAiSiteState::setHtmlBlocks($this->generation, $htmlBlocks);
			$this->changed = true;
		}

		return true;
	}

	/**
	 * @param list<string> $nodeDiagnostics
	 */
	private function logNodeDiagnostics(int $index, array $nodeDiagnostics): void
	{
		if (!Log::isEnabled())
		{
			return;
		}

		(new Log($this->generation->getId()))->add(implode(PHP_EOL, [
			'-----',
			'Date: ' . date('Y-m-d'),
			'Time: ' . date('H:i:s'),
			'Generation ID: ' . $this->generation->getId(),
			'Entry type: HTML block node diagnostics',
			'Step: ' . (int)$this->stepId,
			'Block index: ' . $index,
			'Diagnostics:',
			json_encode(array_values($nodeDiagnostics), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
			'',
		]));
	}

	private function isAlreadyPreparedMarkup(
		string $html,
		array $currentAiMeta,
		NodeValidator $nodeValidator,
	): bool
	{
		return
			$this->isAlreadyPreparedImageMarkup($html, $currentAiMeta)
			&& $nodeValidator->validate($html) === [];
	}

	private function isAlreadyPreparedImageMarkup(string $html, array $currentAiMeta): bool
	{
		$images = $currentAiMeta['images'] ?? null;
		if (!is_array($images) || $images === [])
		{
			return false;
		}

		return
			!str_contains($html, 'data-ai-prompt=')
			&& !str_contains($html, 'data-ai-aspect=')
			&& str_contains($html, 'ai-node-img-');
	}
}
