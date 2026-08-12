<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\Step\Helper\ChangeAiSiteDomHelper;
use Bitrix\Landing\Copilot\Generation\Step\Base\TaskStep;

use Bitrix\Landing\AI\SiteBuilder\Font\AiFontPolicy;
use Bitrix\Landing\AI\SiteBuilder\Html\AiBlockHtmlPayloadProcessor;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteState;
use Bitrix\Landing\Copilot\Services\Manifest\NodeCollector;
use Bitrix\Main\Web\DOM;

class TaskPrepareChangeAiSiteBlocksMarkup extends TaskStep
{
	private const ROOT_NODE_ID = 'change-ai-site-markup-root';
	private const DEFAULT_IMAGE_ASPECT = 'square';
	private const IMAGE_NODE_TYPE = 'img';
	private const PROMPT_ATTR = 'data-ai-prompt';
	private const ASPECT_ATTR = 'data-ai-aspect';
	private const SUPPORTED_ASPECTS = ['landscape', 'portrait', 'square'];
	private const ACTION_ADD = 'add_block';
	private const ACTION_UPDATE = 'update_block';

	public function execute(): bool
	{
		parent::execute();

		$htmlBlocks = ChangeAiSiteState::getHtmlBlocks($this->generation);
		if ($htmlBlocks === [])
		{
			return true;
		}

		$changed = false;
		$emptyAiMeta = AiBlockHtmlPayloadProcessor::getEmptyAiMeta();
		foreach ($htmlBlocks as &$item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$originalGeneratedHtml = (string)($item['generatedHtml'] ?? '');
			$html = AiFontPolicy::normalizeHtmlFontClasses($originalGeneratedHtml);
			$currentAiMeta = AiBlockHtmlPayloadProcessor::normalizeAiMeta($item['aiMeta'] ?? null);
			if ($html === '')
			{
				$rawAiMeta = $item['aiMeta'] ?? null;
				if ($rawAiMeta !== $emptyAiMeta)
				{
					$item['aiMeta'] = $emptyAiMeta;
					$changed = true;
				}
				continue;
			}

			if ($this->isAlreadyPreparedImageMarkup($html, $currentAiMeta))
			{
				continue;
			}

			$prepared = $this->prepareGeneratedHtml(
				$html,
				trim((string)($item['actionType'] ?? '')),
				(string)($item['originalHtml'] ?? ''),
			);
			$preparedHtml = (string)($prepared['html'] ?? $html);
			$preparedAiMeta = AiBlockHtmlPayloadProcessor::normalizeAiMeta($prepared['aiMeta'] ?? null);
			if ($preparedHtml === $originalGeneratedHtml && $preparedAiMeta === $currentAiMeta)
			{
				continue;
			}

			$item['generatedHtml'] = $preparedHtml;
			$item['aiMeta'] = $preparedAiMeta;
			$changed = true;
		}
		unset($item);

		if ($changed)
		{
			ChangeAiSiteState::setHtmlBlocks($this->generation, $htmlBlocks);
			$this->changed = true;
		}

		return true;
	}

	private function isAlreadyPreparedImageMarkup(string $html, array $currentAiMeta): bool
	{
		$images = $currentAiMeta['images'] ?? null;
		if (!is_array($images) || $images === [])
		{
			return false;
		}

		return
			!str_contains($html, self::PROMPT_ATTR . '=')
			&& !str_contains($html, self::ASPECT_ATTR . '=')
			&& str_contains($html, 'data-ai-node="' . self::IMAGE_NODE_TYPE . '"')
		;
	}

	private function prepareGeneratedHtml(string $html, string $actionType = '', string $originalHtml = ''): array
	{
		if (trim($html) === '')
		{
			return [
				'html' => '',
				'aiMeta' => AiBlockHtmlPayloadProcessor::getEmptyAiMeta(),
			];
		}

		try
		{
			$doc = new DOM\Document();
			$doc->loadHTML('<div id="' . self::ROOT_NODE_ID . '">' . $html . '</div>');

			$root = $doc->querySelector('#' . self::ROOT_NODE_ID);
			if (!$root)
			{
				throw new \RuntimeException('DOM root not found.');
			}

			$preservedImageSources = $this->collectImageSourcesFromHtml($originalHtml);
			$images = [];
			$imageIndex = 0;
			foreach ($root->querySelectorAll('img') as $imageNode)
			{
				$imageIndex++;
				$imageNode->setAttribute('data-ai-node', self::IMAGE_NODE_TYPE);

				$className = 'ai-node-' . NodeCollector::buildAutoNodeCode(self::IMAGE_NODE_TYPE, $imageIndex);
				$existingClasses = trim((string)$imageNode->getAttribute('class'));
				$classParts = $existingClasses === '' ? [] : (preg_split('/\s+/', $existingClasses) ?: []);
				if (!in_array($className, $classParts, true))
				{
					$classParts[] = $className;
					$imageNode->setAttribute('class', trim(implode(' ', $classParts)));
				}

				$src = trim((string)$imageNode->getAttribute('src'));
				$alt = trim((string)$imageNode->getAttribute('alt'));
				$aspect = $this->normalizeAspect((string)$imageNode->getAttribute(self::ASPECT_ATTR));
				$nodeCode = NodeCollector::buildAutoNodeSelector(self::IMAGE_NODE_TYPE, $imageIndex);
				$prompt = trim((string)$imageNode->getAttribute(self::PROMPT_ATTR));
				if ($prompt !== '')
				{
					$this->clearImageSource($imageNode);
					$images[] = $this->buildImageMeta($nodeCode, $prompt, $alt, $aspect);
				}
				elseif ($this->shouldGenerateUnpromptedImage($src, $actionType, $preservedImageSources))
				{
					$this->clearImageSource($imageNode);
					$fallbackPrompt = $this->resolveFallbackImagePrompt($imageNode);
					if ($fallbackPrompt !== '')
					{
						$images[] = $this->buildImageMeta($nodeCode, $fallbackPrompt, $alt, $aspect);
					}
				}

				$this->clearInternalImageAttributes($imageNode);
			}

			$preparedHtml = ChangeAiSiteDomHelper::extractRootHtml($root);
			if ($preparedHtml === '')
			{
				$preparedHtml = $html;
			}

			return [
				'html' => AiBlockHtmlPayloadProcessor::sanitizeHtml($preparedHtml),
				'aiMeta' => AiBlockHtmlPayloadProcessor::normalizeAiMeta([
					'images' => $images,
				]),
			];
		}
		catch (\Throwable)
		{
			return [
				'html' => AiBlockHtmlPayloadProcessor::sanitizeHtml($html),
				'aiMeta' => AiBlockHtmlPayloadProcessor::getEmptyAiMeta(),
			];
		}
	}

	private function normalizeAspect(string $aspect): string
	{
		$aspect = mb_strtolower(trim($aspect));

		return in_array($aspect, self::SUPPORTED_ASPECTS, true)
			? $aspect
			: self::DEFAULT_IMAGE_ASPECT
		;
	}

	private function buildImageMeta(string $nodeCode, string $prompt, string $alt, string $aspect): array
	{
		$alt = trim($alt);

		return [
			'nodeCode' => $nodeCode,
			'prompt' => $prompt,
			'alt' => $alt !== '' ? $alt : trim($prompt),
			'aspect' => $aspect,
			'shouldGenerate' => true,
		];
	}

	/**
	 * @param array<string, bool> $preservedImageSources
	 */
	private function shouldGenerateUnpromptedImage(string $src, string $actionType, array $preservedImageSources): bool
	{
		if (!in_array($actionType, [self::ACTION_ADD, self::ACTION_UPDATE], true))
		{
			return false;
		}

		if ($src === '')
		{
			return true;
		}

		if ($actionType === self::ACTION_ADD)
		{
			return true;
		}

		return !$this->isPreservedImageSource($src, $preservedImageSources);
	}

	/**
	 * @param array<string, bool> $preservedImageSources
	 */
	private function isPreservedImageSource(string $src, array $preservedImageSources): bool
	{
		$normalized = $this->normalizeImageSource($src);

		return $normalized !== '' && isset($preservedImageSources[$normalized]);
	}

	/**
	 * @return array<string, bool>
	 */
	private function collectImageSourcesFromHtml(string $html): array
	{
		$html = trim($html);
		if ($html === '' || stripos($html, '<img') === false)
		{
			return [];
		}

		$sources = [];
		try
		{
			$doc = new DOM\Document();
			$doc->loadHTML('<div id="' . self::ROOT_NODE_ID . '-original">' . $html . '</div>');
			$root = $doc->querySelector('#' . self::ROOT_NODE_ID . '-original');
			if ($root)
			{
				foreach ($root->querySelectorAll('img') as $imageNode)
				{
					$src = $this->normalizeImageSource((string)$imageNode->getAttribute('src'));
					if ($src !== '')
					{
						$sources[$src] = true;
					}
				}
			}
		}
		catch (\Throwable)
		{
			$sources = [];
		}

		if ($sources !== [])
		{
			return $sources;
		}

		return $this->collectImageSourcesFromHtmlTags($html);
	}

	/**
	 * @return array<string, bool>
	 */
	private function collectImageSourcesFromHtmlTags(string $html): array
	{
		if (!preg_match_all('/<img\b[^>]*>/iu', $html, $matches) || empty($matches[0]))
		{
			return [];
		}

		$sources = [];
		foreach ($matches[0] as $imageTag)
		{
			if (!is_string($imageTag))
			{
				continue;
			}

			$src = $this->normalizeImageSource($this->extractAttributeValueFromHtmlTag($imageTag, 'src'));
			if ($src !== '')
			{
				$sources[$src] = true;
			}
		}

		return $sources;
	}

	private function extractAttributeValueFromHtmlTag(string $htmlTag, string $attribute): string
	{
		$pattern = '/\b' . preg_quote($attribute, '/') . '\s*=\s*(?:\"([^\"]*)\"|\'([^\']*)\'|([^\s>]+))/iu';
		if (!preg_match($pattern, $htmlTag, $matches, PREG_UNMATCHED_AS_NULL))
		{
			return '';
		}

		foreach ([1, 2, 3] as $index)
		{
			if (array_key_exists($index, $matches) && is_string($matches[$index]))
			{
				return trim($matches[$index]);
			}
		}

		return '';
	}

	private function normalizeImageSource(string $src): string
	{
		return html_entity_decode(trim($src), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	private function resolveFallbackImagePrompt(object $imageNode): string
	{
		foreach (['alt', 'title', 'aria-label'] as $attribute)
		{
			$prompt = $this->normalizeFallbackPrompt((string)$imageNode->getAttribute($attribute));
			if ($prompt !== '')
			{
				return $prompt;
			}
		}

		return '';
	}

	private function normalizeFallbackPrompt(string $prompt): string
	{
		$prompt = trim(strip_tags($prompt));
		$prompt = preg_replace('/\s+/u', ' ', $prompt) ?? $prompt;

		return mb_strlen($prompt) >= 3 ? mb_substr($prompt, 0, 220) : '';
	}

	private function clearImageSource(object $imageNode): void
	{
		$imageNode->setAttribute('src', '');
	}

	private function clearInternalImageAttributes(object $imageNode): void
	{
		foreach ([self::PROMPT_ATTR, self::ASPECT_ATTR, 'width', 'height'] as $attribute)
		{
			$imageNode->removeAttribute($attribute);
		}
	}
}
