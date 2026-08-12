<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Services\HtmlBlock;

use Bitrix\Main\Web\DOM;

/**
 * Additive coverage repairer: applies leaf text candidates and wraps uncovered
 * visible text into styleless span hosts. Never renumbers or removes existing nodes.
 * Detection predicates are shared with NodeValidator.
 */
final class CoverageRepairer
{
	private const ROOT_NODE_ID = 'ai-block-html-coverage-repairer-root';
	private const TEXT_NODE_TYPE = 'text';
	private const LINK_NODE_TYPE = 'link';
	private const NODE_MARKUP_CLASS_PATTERN = '/^ai-node-[a-z]+-\d+$/u';
	// inline-only allowlist: block-level text children (p, li) would break flow inside a span host
	private const INLINE_RUN_TAGS = [
		'a' => true,
		'abbr' => true,
		'b' => true,
		'br' => true,
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

	public function repair(string $html): string
	{
		if (trim($html) === '')
		{
			return $html;
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

			if (!$this->repairRoot($root))
			{
				return $html;
			}

			return trim((string)$root->getInnerHTML());
		}
		catch (\Throwable)
		{
			return $html;
		}
	}

	public function repairRoot(DOM\Element $root): bool
	{
		$changed = (new NodeTagger())->tagLeafTextNodes($root);

		foreach ($this->collectTextNodes($root) as $textNode)
		{
			if (self::isUncoveredTextNode($textNode))
			{
				$changed = $this->wrapInlineRun($textNode) || $changed;
			}
		}

		return $changed;
	}

	public static function isUncoveredTextNode(DOM\Node $node): bool
	{
		if (
			$node->getNodeType() !== DOM\Node::TEXT_NODE
			|| trim((string)$node->getTextContent()) === ''
		)
		{
			return false;
		}

		$parent = $node->getParentNode();
		if ($parent instanceof DOM\Element && NodeTagger::isElementHidden($parent))
		{
			return false;
		}

		return !self::hasParentNodeType($node, self::TEXT_NODE_TYPE)
			&& !self::hasLinkAncestorContext($node);
	}

	public static function hasParentNodeType(DOM\Node $node, string $type): bool
	{
		while ($node && method_exists($node, 'getParentNode'))
		{
			if ($node instanceof DOM\Element && NodeTagger::hasNodeType($node, $type))
			{
				return true;
			}

			$node = $node->getParentNode();
		}

		return false;
	}

	/**
	 * Link context excludes text from tagging and diagnostics: link nodes and standalone
	 * links keep their own editing controls, button text is not editable by design.
	 */
	public static function hasLinkAncestorContext(DOM\Node $node): bool
	{
		while ($node && method_exists($node, 'getParentNode'))
		{
			if ($node instanceof DOM\Element)
			{
				$tagName = mb_strtolower((string)$node->getNodeName());
				if ($tagName === 'button')
				{
					return true;
				}

				if (
					$tagName === 'a'
					&& (
						NodeTagger::hasNodeType($node, self::LINK_NODE_TYPE)
						|| NodeTagger::isStandaloneLink($node)
					)
				)
				{
					return true;
				}
			}

			$node = $node->getParentNode();
		}

		return false;
	}

	public static function hasAnyNodeMarkup(DOM\Element $element): bool
	{
		if (trim((string)$element->getAttribute(NodeTagger::NODE_ATTRIBUTE)) !== '')
		{
			return true;
		}

		$classes = preg_split('/\s+/u', trim((string)$element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($classes))
		{
			return false;
		}

		foreach ($classes as $className)
		{
			if (preg_match(self::NODE_MARKUP_CLASS_PATTERN, $className))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @return DOM\Node[]
	 */
	private function collectTextNodes(DOM\Node $node): array
	{
		$result = [];
		foreach ($node->getChildNodesArray() as $child)
		{
			if ($child->getNodeType() === DOM\Node::TEXT_NODE)
			{
				$result[] = $child;

				continue;
			}

			if ($child instanceof DOM\Element)
			{
				$result = array_merge($result, $this->collectTextNodes($child));
			}
		}

		return $result;
	}

	/**
	 * Wraps the maximal run of inline siblings around the text node into a span host.
	 * The run is built from the topmost unmarked inline ancestor, so uncovered text
	 * inside an inline link joins one common host instead of getting a nested one.
	 * The run breaks on elements carrying any node markup, so an existing link node
	 * can never end up inside a new text host.
	 */
	private function wrapInlineRun(DOM\Node $textNode): bool
	{
		$anchor = $this->resolveRunAnchor($textNode);
		$parent = $anchor->getParentNode();
		$doc = $anchor->getOwnerDocument();
		if (!$parent instanceof DOM\Element || !$doc instanceof DOM\Document)
		{
			return false;
		}

		$run = [$anchor];
		for ($node = $anchor->getPreviousSibling(); $node && $this->canJoinInlineRun($node); $node = $node->getPreviousSibling())
		{
			array_unshift($run, $node);
		}
		for ($node = $anchor->getNextSibling(); $node && $this->canJoinInlineRun($node); $node = $node->getNextSibling())
		{
			$run[] = $node;
		}

		// a lone unmarked span becomes the host itself: no extra DOM level,
		// repeated tagging of stripped hosts stays idempotent
		// (the parser emits empty text nodes between elements - not significant)
		$significant = array_values(array_filter(
			$run,
			static fn(DOM\Node $node): bool => $node instanceof DOM\Element
				|| trim((string)$node->getTextContent()) !== '',
		));
		if (
			count($significant) === 1
			&& $significant[0] instanceof DOM\Element
			&& mb_strtolower((string)$significant[0]->getNodeName()) === 'span'
		)
		{
			$significant[0]->setAttribute(NodeTagger::NODE_ATTRIBUTE, self::TEXT_NODE_TYPE);

			return true;
		}

		$host = $doc->createElement('span');
		$parent->insertBefore($host, $run[0]);
		foreach ($run as $node)
		{
			$parent->removeChild($node);
			$host->appendChild($node);
		}
		$host->setAttribute(NodeTagger::NODE_ATTRIBUTE, self::TEXT_NODE_TYPE);

		return true;
	}

	private function resolveRunAnchor(DOM\Node $textNode): DOM\Node
	{
		$anchor = $textNode;
		$parent = $anchor->getParentNode();
		while (
			$parent instanceof DOM\Element
			&& !self::hasAnyNodeMarkup($parent)
			&& isset(self::INLINE_RUN_TAGS[mb_strtolower((string)$parent->getNodeName())])
		)
		{
			$anchor = $parent;
			$parent = $anchor->getParentNode();
		}

		return $anchor;
	}

	private function canJoinInlineRun(DOM\Node $node): bool
	{
		if ($node->getNodeType() === DOM\Node::TEXT_NODE)
		{
			return true;
		}

		if (!$node instanceof DOM\Element || self::hasAnyNodeMarkup($node))
		{
			return false;
		}

		return isset(self::INLINE_RUN_TAGS[mb_strtolower((string)$node->getNodeName())]);
	}
}
