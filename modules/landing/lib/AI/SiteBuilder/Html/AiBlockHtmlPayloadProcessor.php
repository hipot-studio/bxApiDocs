<?php

declare(strict_types=1);

namespace Bitrix\Landing\AI\SiteBuilder\Html;

use Bitrix\Landing\AI\SiteBuilder\Font\AiFontPolicy;
use Bitrix\Landing\Copilot\Data\AiMeta\AiMetaNodes;
use Bitrix\Landing\Copilot\Services\HtmlBlock\FallbackHtmlTagHelper;
use Bitrix\Landing\Copilot\Services\HtmlBlock\NodeTagger;
use Bitrix\Landing\Copilot\Services\Manifest\NodeCollector;
use Bitrix\Main\Web\DOM;
use Bitrix\Main\Web\Uri;

final class AiBlockHtmlPayloadProcessor
{
	private const ROOT_NODE_ID = 'ai-block-html-payload-root';
	private const DEFAULT_IMAGE_ASPECT = 'square';
	private const IMAGE_NODE_TYPE = 'img';
	private const PROMPT_ATTR = 'data-ai-prompt';
	private const ASPECT_ATTR = 'data-ai-aspect';

	/**
	 * @return array{
	 * 	html: string,
	 * 	aiMeta: array{
	 * 		images: array<int, array{
	 * 			nodeCode: string,
	 * 			prompt: string,
	 * 			alt: string,
	 * 			aspect: string,
	 * 			shouldGenerate: bool
	 * 		}>
	 * 	}
	 * }
	 */
	public function prepare(string $html): array
	{
		if (trim($html) === '')
		{
			return [
				'html' => trim($html),
				'aiMeta' => self::getEmptyAiMeta(),
			];
		}

		try
		{
			return $this->prepareWithDom($html);
		}
		catch (\Throwable)
		{
			return $this->prepareWithRegexFallback($html);
		}
	}

	public static function sanitizeHtml(string $html): string
	{
		$html = self::removeDangerousTags($html);
		$html = self::removeEventHandlerAttributes($html);
		$html = self::sanitizeJavascriptUrls($html);
		$html = AiFontPolicy::normalizeHtmlFontClasses($html);
		$html = AiFontAwesomeIconNormalizer::normalizeHtml($html);

		$html = preg_replace_callback('/<img\b[^>]*>/iu', static function (array $matches): string
		{
			$imageTag = (string)($matches[0] ?? '');
			foreach ([self::PROMPT_ATTR, self::ASPECT_ATTR, 'width', 'height'] as $attribute)
			{
				$imageTag = self::removeAttributeFromHtmlTag($imageTag, $attribute);
			}

			return $imageTag;
		}, $html) ?? $html;

		return trim($html);
	}

	private static function removeDangerousTags(string $html): string
	{
		$patterns = [
			'/<script\b[^>]*>.*?<\/script>/is',
			'/<script\b[^>]*\/?>/is',
			'/<style\b[^>]*>.*?<\/style>/is',
			'/<style\b[^>]*\/?>/is',
		];
		$result = preg_replace($patterns, '', $html);

		return is_string($result) ? $result : $html;
	}

	private static function removeEventHandlerAttributes(string $html): string
	{
		$result = preg_replace(
			'/\s+on[a-z0-9:_-]+\s*=\s*(\"[^\"]*\"|\'[^\']*\'|[^\s>]+)/iu',
			'',
			$html,
		);

		return is_string($result) ? $result : $html;
	}

	private static function sanitizeJavascriptUrls(string $html): string
	{
		$result = preg_replace_callback(
			'/\b(href|src)\s*=\s*(\"[^\"]*\"|\'[^\']*\'|[^\s>]+)/iu',
			static function (array $matches): string
			{
				$attribute = (string)($matches[1] ?? '');
				$rawValue = trim((string)($matches[2] ?? ''));
				$value = trim($rawValue, "\"' \t\n\r\0\x0B");

				if (!preg_match('/^javascript\s*:/iu', $value))
				{
					return (string)($matches[0] ?? '');
				}

				$fallback = mb_strtolower($attribute) === 'href' ? '#' : '';

				return $attribute . '="' . $fallback . '"';
			},
			$html,
		);

		return is_string($result) ? $result : $html;
	}

	private static function removeAttributeFromHtmlTag(string $htmlTag, string $attribute): string
	{
		return preg_replace(
			'/\s' . preg_quote($attribute, '/') . '\s*=\s*(\"[^\"]*\"|\'[^\']*\'|[^\s>]+)/iu',
			'',
			$htmlTag,
		) ?? $htmlTag;
	}

	public static function getEmptyAiMeta(): array
	{
		return [
			'images' => [],
			'nodes' => [],
		];
	}

	public static function normalizeAiMeta(mixed $aiMeta): array
	{
		$normalized = self::getEmptyAiMeta();
		if (!is_array($aiMeta))
		{
			return $normalized;
		}

		$images = $aiMeta['images'] ?? null;
		foreach (is_array($images) ? $images : [] as $image)
		{
			if (!is_array($image))
			{
				continue;
			}

			$nodeCode = trim((string)($image['nodeCode'] ?? ''));
			$prompt = trim((string)($image['prompt'] ?? ''));
			if ($nodeCode === '' || $prompt === '')
			{
				continue;
			}

			$normalized['images'][] = [
				'nodeCode' => $nodeCode,
				'prompt' => $prompt,
				'alt' => trim((string)($image['alt'] ?? '')),
				'aspect' => self::normalizeImageAspect((string)($image['aspect'] ?? $image['format'] ?? '')),
				'shouldGenerate' => !empty($image['shouldGenerate']),
			];
		}

		$normalized['nodes'] = AiMetaNodes::fromArray($aiMeta['nodes'] ?? null)->toArray();

		return $normalized;
	}

	/**
	 * @return array{
	 * 	html: string,
	 * 	aiMeta: array{
	 * 		images: array<int, array{
	 * 			nodeCode: string,
	 * 			prompt: string,
	 * 			alt: string,
	 * 			aspect: string,
	 * 			shouldGenerate: bool
	 * 		}>
	 * 	}
	 * }
	 */
	private function prepareWithDom(string $html): array
	{
		$html = AiFontAwesomeIconNormalizer::normalizeHtml($html);

		$doc = new DOM\Document();
		$doc->loadHTML('<div id="' . self::ROOT_NODE_ID . '">' . $html . '</div>');

		$root = $doc->querySelector('#' . self::ROOT_NODE_ID);
		if (!$root)
		{
			return $this->prepareWithRegexFallback($html);
		}

		$nodesMeta = (new NodeTagger())->tagRoot($root);

		$imageMeta = [];
		$imageIndex = 0;
		foreach ($root->querySelectorAll('img') as $imageNode)
		{
			if (NodeTagger::isElementHidden($imageNode))
			{
				$this->removeInternalImageAttributes($imageNode);

				continue;
			}

			$imageIndex++;

			$src = trim((string)$imageNode->getAttribute('src'));
			$alt = trim((string)$imageNode->getAttribute('alt'));
			$prompt = $this->extractImagePrompt(
				trim((string)$imageNode->getAttribute(self::PROMPT_ATTR)),
				$src,
				$alt,
			);
			$aspect = $this->extractImageAspect(
				trim((string)$imageNode->getAttribute(self::ASPECT_ATTR)),
				(int)$imageNode->getAttribute('width'),
				(int)$imageNode->getAttribute('height'),
			);
			$this->removeInternalImageAttributes($imageNode);

			$meta = $this->buildImageMeta(
				$imageIndex,
				$prompt,
				$alt,
				$aspect,
				$src,
			);
			if ($meta !== null)
			{
				$imageMeta[] = $meta;
			}
		}

		$preparedHtml = $this->extractRootHtml($root);
		if ($preparedHtml === '')
		{
			$preparedHtml = $html;
		}

		return [
			'html' => self::sanitizeHtml($preparedHtml),
				'aiMeta' => [
					'images' => $imageMeta,
					'nodes' => $nodesMeta->toArray(),
				],
			];
		}

	/**
	 * @return array{
	 * 	html: string,
	 * 	aiMeta: array{
	 * 		images: array<int, array{
	 * 			nodeCode: string,
	 * 			prompt: string,
	 * 			alt: string,
	 * 			aspect: string,
	 * 			shouldGenerate: bool
	 * 		}>
	 * 	}
	 * }
	 */
	private function prepareWithRegexFallback(string $html): array
	{
		$imageMeta = [];
		$nodeMeta = [];
		$imageIndex = 0;

		$preparedHtml = preg_replace_callback('/<img\b[^>]*>/iu', function (array $matches) use (&$imageMeta, &$nodeMeta, &$imageIndex): string
		{
			$imageIndex++;
			$imageTag = (string)($matches[0] ?? '');

			$src = $this->extractAttributeValue($imageTag, 'src');
			$alt = $this->extractAttributeValue($imageTag, 'alt');
			$prompt = $this->extractImagePrompt(
				$this->extractAttributeValue($imageTag, self::PROMPT_ATTR),
				$src,
				$alt,
			);
			$aspect = $this->extractImageAspect(
				$this->extractAttributeValue($imageTag, self::ASPECT_ATTR),
				(int)$this->extractAttributeValue($imageTag, 'width'),
				(int)$this->extractAttributeValue($imageTag, 'height'),
			);

			$meta = $this->buildImageMeta(
				$imageIndex,
				$prompt,
				$alt,
				$aspect,
				$src,
			);
			if ($meta !== null)
			{
				$imageMeta[] = $meta;
			}

			$code = NodeCollector::buildAutoNodeCode(self::IMAGE_NODE_TYPE, $imageIndex);
			$selector = '.ai-node-' . $code;
			$nodeMeta[$selector] = [
				'name' => $code,
				'type' => self::IMAGE_NODE_TYPE,
			];
			$imageTag = self::removeAttributeFromHtmlTag($imageTag, 'data-ai-node');
			$imageTag = FallbackHtmlTagHelper::appendClass($imageTag, 'ai-node-' . $code);

			return self::sanitizeHtml($imageTag);
		}, $html);

		return [
			'html' => self::sanitizeHtml((string)($preparedHtml ?? $html)),
			'aiMeta' => [
				'images' => $imageMeta,
				'nodes' => AiMetaNodes::fromManifestNodes($nodeMeta)->toArray(),
			],
		];
	}

	/**
	 * @return array{
	 * 	nodeCode: string,
	 * 	prompt: string,
	 * 	alt: string,
	 * 	aspect: string,
	 * 	shouldGenerate: bool
	 * }|null
	 */
	private function buildImageMeta(
		int $imageIndex,
		string $prompt,
		string $alt,
		string $aspect,
		string $src,
	): ?array
	{
		$prompt = trim($prompt);
		if ($prompt === '')
		{
			return null;
		}

		$alt = trim($alt);

		return [
			'nodeCode' => NodeCollector::buildAutoNodeSelector(self::IMAGE_NODE_TYPE, $imageIndex),
			'prompt' => $prompt,
			'alt' => $alt !== '' ? $alt : $prompt,
			'aspect' => self::normalizeImageAspect($aspect),
			'shouldGenerate' => $this->shouldGenerateImage($src),
		];
	}

	private static function normalizeImageAspect(string $aspect): string
	{
		$aspect = mb_strtolower(trim($aspect));

		return in_array($aspect, ['landscape', 'portrait', self::DEFAULT_IMAGE_ASPECT], true)
			? $aspect
			: self::DEFAULT_IMAGE_ASPECT;
	}

	private function extractImageAspect(string $aspect, int $width = 0, int $height = 0): string
	{
		$aspect = trim($aspect);
		if ($aspect !== '')
		{
			return self::normalizeImageAspect($aspect);
		}

		return $this->resolveImageAspectFromSize($width, $height);
	}

	private function extractImagePrompt(
		string $prompt,
		string $src,
		string $alt,
	): string
	{
		if ($prompt !== '')
		{
			return $prompt;
		}

		$placeholderText = $this->extractPlaceholderText($src);
		if ($placeholderText !== '')
		{
			return $placeholderText;
		}

		return trim($alt);
	}

	private function extractAttributeValue(string $htmlTag, string $attribute): string
	{
		$pattern = '/\b' . preg_quote($attribute, '/') . '\s*=\s*(?:\"([^\"]*)\"|\'([^\']*)\'|([^\s>]+))/iu';
		if (!preg_match($pattern, $htmlTag, $matches, PREG_UNMATCHED_AS_NULL))
		{
			return '';
		}

		foreach ([1, 2, 3] as $index)
		{
			if (array_key_exists($index, $matches) && $matches[$index] !== null)
			{
				return trim((string)$matches[$index]);
			}
		}

		return '';
	}

	private function extractRootHtml(object $root): string
	{
		$html = '';
		foreach ($root->getChildNodesArray() as $child)
		{
			if (method_exists($child, 'getOuterHTML'))
			{
				$html .= $child->getOuterHTML();
			}
		}

		return trim($html);
	}

	private function removeInternalImageAttributes(object $imageNode): void
	{
		foreach ([self::PROMPT_ATTR, self::ASPECT_ATTR, 'width', 'height'] as $attribute)
		{
			$imageNode->removeAttribute($attribute);
		}
	}

	private function resolveImageAspectFromSize(int $width, int $height): string
	{
		if ($width > 0 && $height > 0)
		{
			$ratio = $width / $height;
			if ($ratio >= 1.2)
			{
				return 'landscape';
			}

			if ($ratio <= 0.83)
			{
				return 'portrait';
			}
		}

		return self::DEFAULT_IMAGE_ASPECT;
	}

	private function shouldGenerateImage(string $src): bool
	{
		return $src === '' || !$this->isStoredImageSrc($src);
	}

	private function isStoredImageSrc(string $src): bool
	{
		if ((int)($this->getUrlQueryParams($src)['id'] ?? 0) > 0)
		{
			return true;
		}

		$path = mb_strtolower((string)(new Uri($src))->getPath());

		return $path !== '' && str_starts_with($path, '/upload/');
	}

	private function extractPlaceholderText(string $src): string
	{
		$params = $this->getUrlQueryParams($src);

		return trim((string)($params['text'] ?? ''));
	}

	private function getUrlQueryParams(string $url): array
	{
		if ($url === '')
		{
			return [];
		}

		$params = [];
		mb_parse_str((new Uri($url))->getQuery(), $params);

		return is_array($params) ? $params : [];
	}
}
