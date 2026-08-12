<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Services\HtmlBlock;

use Bitrix\Landing\Copilot\Data\AiMeta\AiMetaNodes;
use Bitrix\Landing\Copilot\Services\Manifest\NodeCollector;
use Bitrix\Main\Web\DOM;

/**
 * Deterministic HTML node tagger.
 */
final class NodeTagger
{
	public const NODE_ATTRIBUTE = 'data-ai-node';

	/**
	 * Current version of tagging rules, stored as top level manifest key `taggerVersion`.
	 * Bump on every rules change to make older blocks repairable.
	 */
	public const VERSION = 1;

	private const ROOT_NODE_ID = 'ai-block-html-tagger-root';
	private const TEXT_NODE_TYPE = 'text';
	private const LINK_NODE_TYPE = 'link';
	private const IMAGE_NODE_TYPE = 'img';
	private const ICON_NODE_TYPE = 'icon';
	private const TEXT_CONTAINER_TAGS = ['div'];
	private const HEADING_TEXT_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
	private const LEAF_TEXT_TAGS = ['p', 'summary', 'blockquote', 'figcaption'];
	// tags from LEAF_TEXT_TAGS which cannot host a text node themselves (parser auto-closes them)
	private const LEAF_HOST_WRAPPED_TAGS = ['p' => true];
	private const TEXT_CHILD_TAGS = [
		'a' => true,
		'abbr' => true,
		'b' => true,
		'br' => true,
		'cite' => true,
		'code' => true,
		'em' => true,
		'font' => true,
		'i' => true,
		'li' => true,
		'mark' => true,
		'p' => true,
		's' => true,
		'small' => true,
		'span' => true,
		'strong' => true,
		'sub' => true,
		'sup' => true,
		'u' => true,
	];
	private const INLINE_TEXT_SIBLING_TAGS = [
		'abbr' => true,
		'b' => true,
		'cite' => true,
		'code' => true,
		'em' => true,
		'font' => true,
		'i' => true,
		'mark' => true,
		's' => true,
		'small' => true,
		'span' => true,
		'strong' => true,
		'sub' => true,
		'sup' => true,
		'u' => true,
	];
	private const FA_BASE_CLASSES = [
		'fa' => true,
		'fa-solid' => true,
		'fa-regular' => true,
		'fa-brands' => true,
	];
	private const FA_SERVICE_CLASSES = [
		'fa-fw' => true,
		'fa-ul' => true,
		'fa-li' => true,
		'fa-border' => true,
		'fa-pull-left' => true,
		'fa-pull-right' => true,
		'fa-spin' => true,
		'fa-pulse' => true,
		'fa-beat' => true,
		'fa-fade' => true,
		'fa-beat-fade' => true,
		'fa-bounce' => true,
		'fa-flip' => true,
		'fa-shake' => true,
		'fa-spin-pulse' => true,
		'fa-swap-opacity' => true,
		'fa-inverse' => true,
		'fa-stack' => true,
		'fa-stack-1x' => true,
		'fa-stack-2x' => true,
		'fa-layers' => true,
		'fa-layers-text' => true,
		'fa-layers-counter' => true,
		'fa-primary' => true,
		'fa-secondary' => true,
	];

	public function tag(string $html): string
	{
		if (trim($html) === '')
		{
			return trim($html);
		}

		try
		{
			$doc = new DOM\Document();
			$doc->loadHTML('<div id="' . self::ROOT_NODE_ID . '">' . $html . '</div>');

			$root = $doc->querySelector('#' . self::ROOT_NODE_ID);
			if (!$root instanceof DOM\Element)
			{
				return $html;
			}

			$this->tagRoot($root);

			return trim((string)$root->getInnerHTML());
		}
		catch (\Throwable)
		{
			return $html;
		}
	}

	public function tagRoot(DOM\Element $root): AiMetaNodes
	{
		$this->clearNodeAttributes($root);
		$this->tagImages($root);
		$this->tagIcons($root);
		$this->tagTextNodes($root);
		$this->tagLeafTextNodes($root);
		$this->tagLinks($root);
		(new CoverageRepairer())->repairRoot($root);

		return $this->applyNodeClasses($root);
	}

	/**
	 * Additive pass: tags leaf text candidates (p, summary, blockquote, figcaption).
	 * A `p` cannot host contenteditable, so the node is hosted on a styleless div wrapper.
	 */
	public function tagLeafTextNodes(DOM\Element $root): bool
	{
		$changed = false;
		foreach (self::LEAF_TEXT_TAGS as $tagName)
		{
			foreach ($root->querySelectorAll($tagName) as $element)
			{
				if (
					self::hasNodeType($element, self::TEXT_NODE_TYPE)
					|| $this->hasParentNodeType($element, self::TEXT_NODE_TYPE)
					|| CoverageRepairer::hasLinkAncestorContext($element)
					|| !self::isTextCandidate($element)
				)
				{
					continue;
				}

				$host = $element;
				if (isset(self::LEAF_HOST_WRAPPED_TAGS[$tagName]))
				{
					$host = $this->wrapWithHostElement($element, 'div');
					if ($host === null)
					{
						continue;
					}
				}

				$host->setAttribute(self::NODE_ATTRIBUTE, self::TEXT_NODE_TYPE);
				$changed = true;
			}
		}

		return $changed;
	}

	public static function hasNodeType(DOM\Element $element, string $type): bool
	{
		$type = mb_strtolower(trim($type));
		if (mb_strtolower(trim((string)$element->getAttribute(self::NODE_ATTRIBUTE))) === $type)
		{
			return true;
		}

		$classList = preg_split('/\s+/u', trim((string)$element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($classList))
		{
			return false;
		}

		foreach ($classList as $className)
		{
			if (preg_match('/^ai-node-' . preg_quote($type, '/') . '-\d+$/u', $className))
			{
				return true;
			}
		}

		return false;
	}

	public static function isFontAwesomeIcon(DOM\Element $element): bool
	{
		if (mb_strtolower((string)$element->getNodeName()) !== 'i')
		{
			return false;
		}

		$classes = self::getClassMap($element);
		if (!array_intersect_key($classes, self::FA_BASE_CLASSES))
		{
			return false;
		}

		foreach ($classes as $className => $exists)
		{
			if (
				$exists
				&& str_starts_with($className, 'fa-')
				&& !isset(self::FA_BASE_CLASSES[$className])
				&& !isset(self::FA_SERVICE_CLASSES[$className])
			)
			{
				return true;
			}
		}

		return false;
	}

	public static function isElementHidden(DOM\Element $element): bool
	{
		$node = $element;
		while ($node instanceof DOM\Element)
		{
			if (
				mb_strtolower(trim((string)$node->getAttribute('aria-hidden'))) === 'true'
				|| $node->hasAttribute('hidden')
			)
			{
				return true;
			}

			$node = $node->getParentNode();
		}

		return false;
	}

	public static function hasVisibleText(DOM\Element $element): bool
	{
		if (self::isElementHidden($element))
		{
			return false;
		}

		return self::getVisibleText($element) !== '';
	}

	public static function isTextCandidate(DOM\Element $element): bool
	{
		$tagName = mb_strtolower((string)$element->getNodeName());
		if (in_array($tagName, self::HEADING_TEXT_TAGS, true))
		{
			return self::hasVisibleText($element);
		}

		if (
			!in_array($tagName, self::TEXT_CONTAINER_TAGS, true)
			&& !in_array($tagName, self::LEAF_TEXT_TAGS, true)
		)
		{
			return false;
		}

		if (!self::hasVisibleText($element))
		{
			return false;
		}

		if (self::hasOnlyStandaloneLinkText($element))
		{
			return false;
		}

		foreach ($element->getChildNodesArray() as $child)
		{
			if (!$child instanceof DOM\Element)
			{
				continue;
			}

			$childTagName = mb_strtolower((string)$child->getNodeName());
			if (!isset(self::TEXT_CHILD_TAGS[$childTagName]))
			{
				return false;
			}
		}

		return true;
	}

	public static function isStandaloneLink(DOM\Element $element): bool
	{
		if (
			mb_strtolower((string)$element->getNodeName()) !== 'a'
			|| trim((string)$element->getAttribute('href')) === ''
			|| self::isElementHidden($element)
		)
		{
			return false;
		}

		$parent = $element->getParentNode();
		if (!$parent || !method_exists($parent, 'getChildNodesArray'))
		{
			return true;
		}

		foreach ($parent->getChildNodesArray() as $sibling)
		{
			if ($sibling === $element)
			{
				continue;
			}

			if ($sibling instanceof DOM\Element && self::isElementHidden($sibling))
			{
				continue;
			}

			if ($sibling->getNodeType() === DOM\Node::TEXT_NODE && trim((string)$sibling->getTextContent()) !== '')
			{
				return false;
			}

			if (!$sibling instanceof DOM\Element)
			{
				continue;
			}

			$siblingTagName = mb_strtolower((string)$sibling->getNodeName());
			if ($siblingTagName === 'a' || !isset(self::INLINE_TEXT_SIBLING_TAGS[$siblingTagName]))
			{
				continue;
			}

			if (trim((string)$sibling->getTextContent()) !== '')
			{
				return false;
			}
		}

		return true;
	}

	private function clearNodeAttributes(DOM\Element $root): void
	{
		foreach ($root->querySelectorAll('[' . self::NODE_ATTRIBUTE . ']') as $element)
		{
			$element->removeAttribute(self::NODE_ATTRIBUTE);
		}

		foreach ($root->querySelectorAll('[class*="ai-node-"]') as $element)
		{
			$this->removeNodeClasses($element);
		}
	}

	private function tagImages(DOM\Element $root): void
	{
		foreach ($root->querySelectorAll('img') as $element)
		{
			if (!self::isElementHidden($element))
			{
				$element->setAttribute(self::NODE_ATTRIBUTE, self::IMAGE_NODE_TYPE);
			}
		}
	}

	private function tagIcons(DOM\Element $root): void
	{
		foreach ($root->querySelectorAll('i') as $element)
		{
			if (!self::isElementHidden($element) && self::isFontAwesomeIcon($element))
			{
				$element->setAttribute(self::NODE_ATTRIBUTE, self::ICON_NODE_TYPE);
			}
		}
	}

	private function tagTextNodes(DOM\Element $root): void
	{
		foreach ([...self::HEADING_TEXT_TAGS, ...self::TEXT_CONTAINER_TAGS] as $tagName)
		{
			foreach ($root->querySelectorAll($tagName) as $element)
			{
				if (
					self::isTextCandidate($element)
					&& !$this->hasParentNodeType($element, self::TEXT_NODE_TYPE)
				)
				{
					$element->setAttribute(self::NODE_ATTRIBUTE, self::TEXT_NODE_TYPE);
				}
			}
		}
	}

	private function tagLinks(DOM\Element $root): void
	{
		foreach ($root->querySelectorAll('a') as $element)
		{
			if (
				self::isStandaloneLink($element)
				&& !$this->hasParentNodeType($element, self::TEXT_NODE_TYPE)
			)
			{
				$element->setAttribute(self::NODE_ATTRIBUTE, self::LINK_NODE_TYPE);
			}
		}
	}

	private function wrapWithHostElement(DOM\Element $element, string $hostTagName): ?DOM\Element
	{
		$parent = $element->getParentNode();
		$doc = $element->getOwnerDocument();
		if (!$parent instanceof DOM\Node || !$doc instanceof DOM\Document)
		{
			return null;
		}

		$host = $doc->createElement($hostTagName);
		$parent->insertBefore($host, $element);
		$parent->removeChild($element);
		$host->appendChild($element);

		return $host;
	}

	private function hasParentNodeType(DOM\Element $element, string $type): bool
	{
		$node = $element;
		while ($node && method_exists($node, 'getParentNode'))
		{
			$node = $node->getParentNode();
			if (!$node instanceof DOM\Element)
			{
				break;
			}

			if (self::hasNodeType($node, $type))
			{
				return true;
			}
		}

		return false;
	}

	private function applyNodeClasses(DOM\Element $root): AiMetaNodes
	{
		$nodes = [];
		$typeCounters = [];
		foreach ($root->querySelectorAll('[' . self::NODE_ATTRIBUTE . ']') as $element)
		{
			$type = mb_strtolower(trim((string)$element->getAttribute(self::NODE_ATTRIBUTE)));
			if (!in_array($type, [self::TEXT_NODE_TYPE, self::LINK_NODE_TYPE, self::IMAGE_NODE_TYPE, self::ICON_NODE_TYPE], true))
			{
				$element->removeAttribute(self::NODE_ATTRIBUTE);

				continue;
			}

			$typeCounters[$type] = (int)($typeCounters[$type] ?? 0) + 1;
			$code = NodeCollector::buildAutoNodeCode($type, $typeCounters[$type]);
			$selector = '.ai-node-' . $code;
			$this->appendClass($element, 'ai-node-' . $code);
			$element->removeAttribute(self::NODE_ATTRIBUTE);

			$nodes[$selector] = [
				'name' => $code,
				'type' => $type,
			];
		}

		return AiMetaNodes::fromManifestNodes($nodes);
	}

	private static function hasOnlyStandaloneLinkText(DOM\Element $element): bool
	{
		$text = self::getVisibleText($element);
		if ($text === '')
		{
			return false;
		}

		foreach ($element->getChildNodesArray() as $child)
		{
			if ($child->getNodeType() === DOM\Node::TEXT_NODE && trim((string)$child->getTextContent()) !== '')
			{
				return false;
			}

			if (!$child instanceof DOM\Element || self::isElementHidden($child))
			{
				continue;
			}

			if (
				mb_strtolower((string)$child->getNodeName()) !== 'a'
				|| trim((string)$child->getAttribute('href')) === ''
			)
			{
				return false;
			}
		}

		return true;
	}

	private static function getVisibleText(DOM\Node $node): string
	{
		$text = '';
		foreach ($node->getChildNodesArray() as $child)
		{
			if ($child instanceof DOM\Element)
			{
				if (self::isElementHidden($child))
				{
					continue;
				}

				$text .= self::getVisibleText($child);

				continue;
			}

			if ($child->getNodeType() === DOM\Node::TEXT_NODE)
			{
				$text .= (string)$child->getTextContent();
			}
		}

		return trim($text);
	}

	private static function getClassMap(DOM\Element $element): array
	{
		$tokens = preg_split('/\s+/u', trim((string)$element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($tokens))
		{
			return [];
		}

		$result = [];
		foreach ($tokens as $token)
		{
			$result[mb_strtolower($token)] = true;
		}

		return $result;
	}

	private function appendClass(DOM\Element $element, string $class): void
	{
		$current = trim((string)$element->getAttribute('class'));
		if ($current === '')
		{
			$element->setAttribute('class', $class);

			return;
		}

		$parts = preg_split('/\s+/u', $current, -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($parts))
		{
			$element->setAttribute('class', $class);

			return;
		}

		if (!in_array($class, $parts, true))
		{
			$parts[] = $class;
			$element->setAttribute('class', implode(' ', $parts));
		}
	}

	private function removeNodeClasses(DOM\Element $element): void
	{
		$parts = preg_split('/\s+/u', trim((string)$element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($parts))
		{
			return;
		}

		$parts = array_values(array_filter(
			$parts,
			static fn(string $className): bool => !preg_match('/^ai-node-[a-z]+-\d+$/u', $className),
		));
		if ($parts === [])
		{
			$element->removeAttribute('class');

			return;
		}

		$element->setAttribute('class', implode(' ', $parts));
	}
}
