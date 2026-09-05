<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step\Helper;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\DOM;

final class ChangeAiSiteDomHelper
{
	public const RESOLVE_ERROR_SELECTOR_MISS = 'selector_miss';
	public const RESOLVE_ERROR_BROKEN_HTML = 'broken_html';

	private const ROOT_NODE_ID = 'change-ai-site-dom-root';

	// the selector is untrusted input and the fallback engine parser is quadratic on length
	private const MAX_SELECTOR_LENGTH = 512;
	private const MAX_SELECTOR_SEGMENTS = 32;

	// tag or tag:nth-child(N); position is 1-based among content sibling elements, not among same-tag ones
	private const POSITIONAL_SEGMENT_REGEX = '/^([a-z][a-z0-9-]*)(?::nth-child\((\d+)\))?$/i';

	// the same content predicate as the JS target model (Q-1): editor service
	// elements do not shift position numbering, childCount or fingerprint text;
	// applying it to the saved HTML keeps both sides symmetric even when such
	// markup slips into the saved block content
	private const SERVICE_CLASS_PREFIX = 'landing-ui-';
	private const SERVICE_CLASSES = [
		'landing-highlight-border',
		'landing-designer-block-pseudo-last',
		'main-ui-loader',
		'landing__copilot-skeleton_animated-text',
		'landing-block-user-action',
	];

	// containers whose live subtree never matches the saved HTML: their text
	// never participates in the fingerprint (DTO-02) on either side
	private const QUARANTINE_CLASSES = ['bitrix24forms'];

	public static function extractRootHtml(object $root): string
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

	public static function extractElementOuterHtml(string $blockHtml, string $selector): string
	{
		$blockHtml = trim($blockHtml);
		$selector = trim($selector);
		if ($blockHtml === '' || $selector === '')
		{
			return '';
		}

		try
		{
			$root = self::parseBlockRoot($blockHtml);
			if (!$root)
			{
				return '';
			}

			$node = $root->querySelector($selector);
			if (!$node && method_exists($root, 'querySelectorAll'))
			{
				$matches = $root->querySelectorAll($selector);
				if (is_iterable($matches))
				{
					foreach ($matches as $match)
					{
						$node = $match;
						break;
					}
				}
			}

			if ($node && method_exists($node, 'getOuterHTML'))
			{
				return trim((string)$node->getOuterHTML());
			}
		}
		catch (\Throwable)
		{
		}

		return '';
	}

	/**
	 * Strictly resolves the selector to exactly one element of the block.
	 *
	 * Success data: ['outerHtml' => string, 'fingerprint' => string, 'matchCount' => 1].
	 * Error codes: RESOLVE_ERROR_SELECTOR_MISS (not exactly one match, customData ['matchCount']),
	 * RESOLVE_ERROR_BROKEN_HTML (block HTML could not be parsed).
	 */
	public static function resolveSelectedElement(string $blockHtml, string $selector): Result
	{
		$result = new Result();
		$blockHtml = trim($blockHtml);
		$selector = trim($selector);

		if ($blockHtml === '')
		{
			return $result->addError(new Error(
				'ChangeAiSite block HTML is empty.',
				self::RESOLVE_ERROR_BROKEN_HTML,
			));
		}

		if (
			$selector === ''
			|| mb_strlen($selector) > self::MAX_SELECTOR_LENGTH
			|| substr_count($selector, '>') >= self::MAX_SELECTOR_SEGMENTS
		)
		{
			return $result->addError(new Error(
				'ChangeAiSite selected element selector is empty or exceeds limits.',
				self::RESOLVE_ERROR_SELECTOR_MISS,
				['matchCount' => 0],
			));
		}

		try
		{
			$root = self::parseBlockRoot($blockHtml);
		}
		catch (\Throwable)
		{
			$root = null;
		}
		// a parsed block always keeps at least one element inside the wrapper; an empty
		// wrapper means the markup broke out of it (e.g. an early closing tag)
		if (!$root || self::getElementChildren($root) === [])
		{
			return $result->addError(new Error(
				'ChangeAiSite block HTML could not be parsed.',
				self::RESOLVE_ERROR_BROKEN_HTML,
			));
		}

		$segments = self::parsePositionalSegments($selector);
		if ($segments !== null)
		{
			[$node, $matchCount] = self::resolvePositionalPath($root, $segments);
		}
		else
		{
			try
			{
				// the engine binds "a > b" to [descendant, child, descendant]; browsers treat it as "a>b"
				$matches = $root->querySelectorAll((string)preg_replace('/\s*>\s*/', '>', $selector));
			}
			catch (\Throwable)
			{
				$matches = [];
			}
			$matches = is_array($matches) ? $matches : [];
			$matchCount = count($matches);
			$node = $matchCount === 1 ? $matches[0] : null;
		}

		if (!($node instanceof DOM\Element))
		{
			return $result->addError(new Error(
				'ChangeAiSite selected element selector did not match exactly one element.',
				self::RESOLVE_ERROR_SELECTOR_MISS,
				['matchCount' => $matchCount],
			));
		}

		return $result->setData([
			'outerHtml' => trim((string)$node->getOuterHTML()),
			'fingerprint' => self::buildElementFingerprint($node),
			'matchCount' => 1,
		]);
	}

	private static function parseBlockRoot(string $blockHtml): ?DOM\Element
	{
		$doc = new DOM\Document();
		$doc->loadHTML('<div id="' . self::ROOT_NODE_ID . '">' . $blockHtml . '</div>');
		$root = $doc->querySelector('#' . self::ROOT_NODE_ID);

		return $root instanceof DOM\Element ? $root : null;
	}

	/**
	 * @return list<array{tag: string, position: ?int}>|null null when the selector
	 * is not the positional dialect and must go to the query engine
	 */
	private static function parsePositionalSegments(string $selector): ?array
	{
		$segments = [];
		foreach (explode('>', $selector) as $segment)
		{
			if (!preg_match(self::POSITIONAL_SEGMENT_REGEX, trim($segment), $match))
			{
				return null;
			}

			$segments[] = [
				'tag' => mb_strtolower($match[1]),
				'position' => isset($match[2]) && $match[2] !== '' ? (int)$match[2] : null,
			];
		}

		return $segments;
	}

	/**
	 * Walks direct element children from the wrapper root; a segment without a position
	 * must match exactly one child by tag, otherwise the resolve fails.
	 *
	 * @param list<array{tag: string, position: ?int}> $segments
	 * @return array{0: ?DOM\Element, 1: int} the resolved node and the match count
	 * of the last processed segment (0 = not found, >=2 = ambiguous)
	 */
	private static function resolvePositionalPath(DOM\Element $root, array $segments): array
	{
		$node = $root;
		foreach ($segments as $segment)
		{
			$children = self::getContentElementChildren($node);

			if ($segment['position'] !== null)
			{
				$node = $children[$segment['position'] - 1] ?? null;
				if (!$node || mb_strtolower($node->getTagName()) !== $segment['tag'])
				{
					return [null, 0];
				}

				continue;
			}

			$matched = [];
			foreach ($children as $child)
			{
				if (mb_strtolower($child->getTagName()) === $segment['tag'])
				{
					$matched[] = $child;
				}
			}
			if (count($matched) !== 1)
			{
				return [null, count($matched)];
			}
			$node = $matched[0];
		}

		return [$node, 1];
	}

	/**
	 * @return DOM\Element[]
	 */
	private static function getElementChildren(DOM\Element $node): array
	{
		$children = [];
		foreach ($node->getChildNodesArray() as $child)
		{
			if ($child instanceof DOM\Element)
			{
				$children[] = $child;
			}
		}

		return $children;
	}

	/**
	 * Element children with editor service elements filtered out — the list the
	 * selector grammar (DTO-01) and the fingerprint childCount (DTO-02) are
	 * defined over, same as contentElementChildren() of the JS target model.
	 *
	 * @return DOM\Element[]
	 */
	private static function getContentElementChildren(DOM\Element $node): array
	{
		return array_values(array_filter(
			self::getElementChildren($node),
			static fn(DOM\Element $child): bool => !self::isServiceElement($child),
		));
	}

	private static function isServiceElement(DOM\Element $node): bool
	{
		if (mb_strtolower((string)$node->getTagName()) === 'script')
		{
			return true;
		}

		$classList = self::getClassList($node);
		if ($classList === [])
		{
			return $node->hasAttribute('hidden');
		}

		foreach ($classList as $className)
		{
			if (
				str_starts_with($className, self::SERVICE_CLASS_PREFIX)
				|| in_array($className, self::SERVICE_CLASSES, true)
			)
			{
				return true;
			}
		}

		return false;
	}

	private static function isQuarantineContainer(DOM\Element $node): bool
	{
		return array_intersect(self::getClassList($node), self::QUARANTINE_CLASSES) !== [];
	}

	/**
	 * @return list<string>
	 */
	private static function getClassList(DOM\Element $node): array
	{
		$className = trim((string)$node->getClassName());
		if ($className === '')
		{
			return [];
		}

		return preg_split('/\s+/', $className) ?: [];
	}

	/**
	 * Text of the subtree without editor service elements and quarantine
	 * subtrees — the DTO-02 text definition shared with the JS side.
	 */
	private static function collectContentText(DOM\Element $node): string
	{
		$text = '';
		foreach ($node->getChildNodesArray() as $child)
		{
			if ($child instanceof DOM\Element)
			{
				if (!self::isServiceElement($child) && !self::isQuarantineContainer($child))
				{
					$text .= self::collectContentText($child);
				}

				continue;
			}

			if ($child->getNodeType() === DOM\Node::TEXT_NODE)
			{
				$text .= (string)$child->getTextContent();
			}
		}

		return $text;
	}

	/**
	 * Builds "<tag>|<childCount>|<textHash>"; the text is collected without editor
	 * service elements and quarantine subtrees, entity-decoded (both sides of the
	 * contract decode once more on top of their DOM text), unicode-whitespace-collapsed,
	 * trimmed and cut to the first 64 characters before hashing.
	 */
	private static function buildElementFingerprint(DOM\Element $node): string
	{
		$tag = mb_strtolower($node->getTagName());
		$childCount = count(self::getContentElementChildren($node));

		$text = html_entity_decode(self::collectContentText($node), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = (string)preg_replace('/\s+/u', ' ', $text);
		$text = mb_substr(trim($text), 0, 64);

		return $tag . '|' . $childCount . '|' . self::hashFnv1a32($text);
	}

	private static function hashFnv1a32(string $text): string
	{
		$hash = 0x811C9DC5;
		$length = strlen($text);
		for ($i = 0; $i < $length; $i++)
		{
			$hash ^= ord($text[$i]);
			$hash = ($hash * 0x01000193) & 0xFFFFFFFF;
		}

		return sprintf('%08x', $hash);
	}
}
