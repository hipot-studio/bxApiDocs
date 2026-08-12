<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Services\HtmlBlock;

use Bitrix\Main\Web\DOM;

/**
 * Diagnostic validator for tagger output.
 */
final class NodeValidator
{
	public const DIAGNOSTIC_TEXT_WITHOUT_NODE = 'text_without_node';
	public const DIAGNOSTIC_STANDALONE_LINK_WITHOUT_NODE = 'standalone_link_without_node';
	public const DIAGNOSTIC_IMG_WITHOUT_NODE = 'img_without_node';
	public const DIAGNOSTIC_ICON_WITHOUT_NODE = 'icon_without_node';

	private const ROOT_NODE_ID = 'ai-block-html-node-validator-root';
	private const TEXT_NODE_TYPE = 'text';
	private const LINK_NODE_TYPE = 'link';
	private const IMAGE_NODE_TYPE = 'img';
	private const ICON_NODE_TYPE = 'icon';

	/**
	 * @return list<string>
	 */
	public function validate(string $html): array
	{
		if (trim($html) === '')
		{
			return [];
		}

		try
		{
			$doc = new DOM\Document();
			$doc->loadHTML('<div id="' . self::ROOT_NODE_ID . '">' . $html . '</div>');

			$root = $doc->querySelector('#' . self::ROOT_NODE_ID);
			if (!$root instanceof DOM\Element)
			{
				return [];
			}

			$diagnostics = [];
			$this->validateTextNodes($root, $diagnostics);
			$this->validateLinks($root, $diagnostics);
			$this->validateImages($root, $diagnostics);
			$this->validateIcons($root, $diagnostics);

			return $diagnostics;
		}
		catch (\Throwable)
		{
			return [];
		}
	}

	/**
	 * @param list<string> $diagnostics
	 */
	private function validateTextNodes(DOM\Element $root, array &$diagnostics): void
	{
		$this->validateVisibleText($root, $diagnostics);
	}

	/**
	 * @param list<string> $diagnostics
	 */
	private function validateLinks(DOM\Element $root, array &$diagnostics): void
	{
		foreach ($root->querySelectorAll('a') as $element)
		{
			if (
				NodeTagger::isStandaloneLink($element)
				&& !$this->hasCurrentOrParentNodeType($element, self::TEXT_NODE_TYPE)
				&& !NodeTagger::hasNodeType($element, self::LINK_NODE_TYPE)
			)
			{
				$diagnostics[] = self::DIAGNOSTIC_STANDALONE_LINK_WITHOUT_NODE;
			}
		}
	}

	/**
	 * @param list<string> $diagnostics
	 */
	private function validateImages(DOM\Element $root, array &$diagnostics): void
	{
		foreach ($root->querySelectorAll('img') as $element)
		{
			if (
				!NodeTagger::isElementHidden($element)
				&& !NodeTagger::hasNodeType($element, self::IMAGE_NODE_TYPE)
			)
			{
				$diagnostics[] = self::DIAGNOSTIC_IMG_WITHOUT_NODE;
			}
		}
	}

	/**
	 * @param list<string> $diagnostics
	 */
	private function validateIcons(DOM\Element $root, array &$diagnostics): void
	{
		foreach ($root->querySelectorAll('i') as $element)
		{
			if (
				!NodeTagger::isElementHidden($element)
				&& NodeTagger::isFontAwesomeIcon($element)
				&& !NodeTagger::hasNodeType($element, self::ICON_NODE_TYPE)
			)
			{
				$diagnostics[] = self::DIAGNOSTIC_ICON_WITHOUT_NODE;
			}
		}
	}

	private function hasCurrentOrParentNodeType(DOM\Element $element, string $type): bool
	{
		$node = $element;
		while ($node instanceof DOM\Element)
		{
			if (NodeTagger::hasNodeType($node, $type))
			{
				return true;
			}

			$node = $node->getParentNode();
		}

		return false;
	}

	/**
	 * @param list<string> $diagnostics
	 */
	private function validateVisibleText(DOM\Node $node, array &$diagnostics): void
	{
		foreach ($node->getChildNodesArray() as $child)
		{
			if ($child instanceof DOM\Element && NodeTagger::isElementHidden($child))
			{
				continue;
			}

			if ($child->getNodeType() === DOM\Node::TEXT_NODE)
			{
				if (
					trim((string)$child->getTextContent()) !== ''
					&& !$this->hasParentNodeType($child, self::TEXT_NODE_TYPE)
					&& !$this->hasParentLinkContext($child)
				)
				{
					$diagnostics[] = self::DIAGNOSTIC_TEXT_WITHOUT_NODE;
				}

				continue;
			}

			if ($child instanceof DOM\Element)
			{
				$this->validateVisibleText($child, $diagnostics);
			}
		}
	}

	private function hasParentNodeType(DOM\Node $node, string $type): bool
	{
		return CoverageRepairer::hasParentNodeType($node, $type);
	}

	private function hasParentLinkContext(DOM\Node $node): bool
	{
		return CoverageRepairer::hasLinkAncestorContext($node);
	}
}
