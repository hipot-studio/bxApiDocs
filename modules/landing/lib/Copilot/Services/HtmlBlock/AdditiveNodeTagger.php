<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Services\HtmlBlock;

use Bitrix\Landing\Copilot\Services\Manifest\NodeCollector;
use Bitrix\Main\Web\DOM;

/**
 * Additive text/link/icon tagger for the full regeneration path.
 * Restores `ai-node-<type>-<index>` codes dropped by a model rewrite: valid codes are
 * reused as is, missing ones are appended starting from max(index) + 1 of their type.
 * Detection mirrors NodeTagger::tagRoot() order, but nothing is cleared or renumbered.
 * Images are tagged by the caller and stay untouched here.
 */
final class AdditiveNodeTagger
{
	private const TEXT_NODE_TYPE = 'text';
	private const LINK_NODE_TYPE = 'link';
	private const ICON_NODE_TYPE = 'icon';
	private const HEADING_TEXT_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
	private const TEXT_CONTAINER_TAGS = ['div'];

	public function repair(DOM\Element $root): void
	{
		$maxIndexes = $this->collectMaxIndexes($root);

		$this->detectIcons($root);
		$this->detectTextNodes($root);
		(new NodeTagger())->tagLeafTextNodes($root);
		$this->detectLinks($root);
		(new CoverageRepairer())->repairRoot($root);

		$this->applyNodeClasses($root, $maxIndexes);
	}

	/**
	 * @return array<string, int>
	 */
	private function collectMaxIndexes(DOM\Element $root): array
	{
		$maxIndexes = [
			self::TEXT_NODE_TYPE => 0,
			self::LINK_NODE_TYPE => 0,
			self::ICON_NODE_TYPE => 0,
		];

		foreach ($root->querySelectorAll('[class*="ai-node-"]') as $element)
		{
			foreach (self::getClassList($element) as $className)
			{
				$node = NodeCollector::parseNodeClass($className);
				if ($node === null || !isset($maxIndexes[$node['type']]))
				{
					continue;
				}

				$maxIndexes[$node['type']] = max($maxIndexes[$node['type']], $node['index']);
			}
		}

		return $maxIndexes;
	}

	private function detectIcons(DOM\Element $root): void
	{
		foreach ($root->querySelectorAll('i') as $element)
		{
			if (
				CoverageRepairer::hasAnyNodeMarkup($element)
				|| NodeTagger::isElementHidden($element)
				|| !NodeTagger::isFontAwesomeIcon($element)
			)
			{
				continue;
			}

			$element->setAttribute(NodeTagger::NODE_ATTRIBUTE, self::ICON_NODE_TYPE);
		}
	}

	private function detectTextNodes(DOM\Element $root): void
	{
		foreach ([...self::HEADING_TEXT_TAGS, ...self::TEXT_CONTAINER_TAGS] as $tagName)
		{
			foreach ($root->querySelectorAll($tagName) as $element)
			{
				if (
					CoverageRepairer::hasAnyNodeMarkup($element)
					|| !NodeTagger::isTextCandidate($element)
					|| $this->hasAncestorNodeType($element, self::TEXT_NODE_TYPE)
				)
				{
					continue;
				}

				$element->setAttribute(NodeTagger::NODE_ATTRIBUTE, self::TEXT_NODE_TYPE);
			}
		}
	}

	private function detectLinks(DOM\Element $root): void
	{
		foreach ($root->querySelectorAll('a') as $element)
		{
			if (
				CoverageRepairer::hasAnyNodeMarkup($element)
				|| !NodeTagger::isStandaloneLink($element)
				|| $this->hasAncestorNodeType($element, self::TEXT_NODE_TYPE)
			)
			{
				continue;
			}

			$element->setAttribute(NodeTagger::NODE_ATTRIBUTE, self::LINK_NODE_TYPE);
		}
	}

	/**
	 * @param array<string, int> $maxIndexes
	 */
	private function applyNodeClasses(DOM\Element $root, array $maxIndexes): void
	{
		foreach ($root->querySelectorAll('[' . NodeTagger::NODE_ATTRIBUTE . ']') as $element)
		{
			$type = mb_strtolower(trim((string)$element->getAttribute(NodeTagger::NODE_ATTRIBUTE)));
			if (!isset($maxIndexes[$type]) || $this->hasNodeCode($element))
			{
				continue;
			}

			$maxIndexes[$type]++;
			$this->appendClass($element, 'ai-node-' . NodeCollector::buildAutoNodeCode($type, $maxIndexes[$type]));
			$element->removeAttribute(NodeTagger::NODE_ATTRIBUTE);
		}
	}

	private function hasAncestorNodeType(DOM\Element $element, string $type): bool
	{
		$parent = $element->getParentNode();

		return $parent instanceof DOM\Node && CoverageRepairer::hasParentNodeType($parent, $type);
	}

	private function hasNodeCode(DOM\Element $element): bool
	{
		foreach (self::getClassList($element) as $className)
		{
			if (NodeCollector::parseNodeClass($className) !== null)
			{
				return true;
			}
		}

		return false;
	}

	private function appendClass(DOM\Element $element, string $class): void
	{
		$parts = self::getClassList($element);
		if (in_array($class, $parts, true))
		{
			return;
		}

		$parts[] = $class;
		$element->setAttribute('class', implode(' ', $parts));
	}

	/**
	 * @return string[]
	 */
	private static function getClassList(DOM\Element $element): array
	{
		$parts = preg_split('/\s+/u', trim((string)$element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY);

		return is_array($parts) ? $parts : [];
	}
}
