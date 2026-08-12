<?php
namespace Bitrix\Landing\Copilot\Services\Manifest;

use Bitrix\Main\Web\DOM\Document;

class NodeCollector
{
	private const AUTO_NODE_CLASS_PREFIX = 'ai-node';

	private array $nodeTypes;

	public function __construct(array $nodeTypes)
	{
		$this->nodeTypes = $nodeTypes;
	}

	public function collectCardCodes(Document $doc): array
	{
		$result = [];
		$cards = $doc->querySelectorAll('[data-ai-card]');
		foreach ($cards as $element)
		{
			$code = $this->normalizeCardCode((string)$element->getAttribute('data-ai-card'));
			if ($code === '')
			{
				$element->removeAttribute('data-ai-card');
				continue;
			}

			$element->setAttribute('data-ai-card', $code);
			$this->appendClass($element, 'ai-card-' . $code);

			if (isset($result[$code]))
			{
				continue;
			}

			$result[$code] = true;
		}

		return array_keys($result);
	}

	public static function findNodeElements(Document $doc): array
	{
		$result = [];
		self::appendNodeElements($doc, $result);

		return $result;
	}

	private static function appendNodeElements($node, array &$result): void
	{
		if (!method_exists($node, 'getChildNodesArray'))
		{
			return;
		}

		foreach ($node->getChildNodesArray() as $child)
		{
			if (
				method_exists($child, 'getAttribute')
				&& (
					str_contains((string)$child->getAttribute('class'), self::AUTO_NODE_CLASS_PREFIX . '-')
					|| (
						method_exists($child, 'hasAttribute')
						&& $child->hasAttribute('data-ai-node')
					)
				)
			)
			{
				$result[] = $child;
			}

			self::appendNodeElements($child, $result);
		}
	}

	public function collectNodes($nodeElements): array
	{
		$cardLabels = [];
		$nodes = [];
		$typeCounters = [];

		foreach ($nodeElements as $element)
		{
			$nodeClass = $this->resolveNodeClass($element);
			$type = $this->normalizeNodeType((string)($nodeClass['type'] ?? $element->getAttribute('data-ai-node')));
			if ($type === null)
			{
				$element->removeAttribute('data-ai-node');
				continue;
			}

			$code = (string)($nodeClass['code'] ?? '');
			if ($code === '')
			{
				$code = $this->buildNodeCode($type, $typeCounters);
			}
			else
			{
				$typeCounters[$type] = max(
					(int)($typeCounters[$type] ?? 0),
					(int)($nodeClass['index'] ?? 0),
				);
			}

			$selector = $this->buildNodeSelector($code);
			$this->appendClass($element, self::AUTO_NODE_CLASS_PREFIX . '-' . $code);
			$element->removeAttribute('data-ai-node');

			$nodes[$selector] = [
				'name' => $code,
				'type' => $type,
			];

			$cardCode = $this->findParentCardCode($element);
			if ($cardCode !== null)
			{
				$this->addCardLabel($cardLabels, $cardCode, $selector);
			}
		}

		return [
			'nodes' => $nodes,
			'cardLabels' => $cardLabels,
		];
	}

	private function resolveNodeClass($element): ?array
	{
		$classes = preg_split('/\s+/', trim((string)$element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($classes))
		{
			return null;
		}

		foreach ($classes as $className)
		{
			if (preg_match('/^ai-node-([a-z]+)-(\d+)$/u', $className, $matches))
			{
				$type = $this->normalizeNodeType((string)($matches[1] ?? ''));
				if ($type === null)
				{
					continue;
				}

				$index = (int)($matches[2] ?? 0);

				return [
					'code' => self::buildAutoNodeCode($type, $index),
					'type' => $type,
					'index' => $index,
				];
			}
		}

		return null;
	}

	public function buildCards(array $cardCodes, array $cardLabels): array
	{
		$cards = [];
		foreach ($cardCodes as $cardCode)
		{
			$selector = $this->buildCardSelector($cardCode);
			$cards[$selector] = [
				'name' => $cardCode,
				'label' => $cardLabels[$cardCode] ?? [],
			];
		}

		return $cards;
	}

	private function normalizeNodeType(string $value): ?string
	{
		$type = mb_strtolower(trim($value));
		if ($type === '')
		{
			return null;
		}

		if (!in_array($type, $this->nodeTypes, true))
		{
			return null;
		}

		return $type;
	}

	private function findParentCardCode($element): ?string
	{
		$node = $element;
		while ($node && method_exists($node, 'getParentNode'))
		{
			$node = $node->getParentNode();
			if (!$node || !method_exists($node, 'getAttribute'))
			{
				break;
			}
			$card = $this->normalizeCardCode((string)$node->getAttribute('data-ai-card'));
			if ($card !== '')
			{
				return $card;
			}
		}

		return null;
	}

	private function buildCardSelector(string $cardCode): string
	{
		return '.ai-card-' . $cardCode;
	}

	private function buildNodeSelector(string $code): string
	{
		return '.' . self::AUTO_NODE_CLASS_PREFIX . '-' . $code;
	}

	private function buildNodeCode(string $type, array &$typeCounters): string
	{
		$typeCounters[$type] = (int)($typeCounters[$type] ?? 0) + 1;

		return self::buildAutoNodeCode($type, $typeCounters[$type]);
	}

	public static function buildAutoNodeCode(string $type, int $index): string
	{
		$type = mb_strtolower(trim($type));
		$index = max(1, $index);

		return $type . '-' . $index;
	}

	public static function buildAutoNodeSelector(string $type, int $index): string
	{
		return '.' . self::AUTO_NODE_CLASS_PREFIX . '-' . self::buildAutoNodeCode($type, $index);
	}

	private function normalizeCardCode(string $value): string
	{
		$value = mb_strtolower(trim($value));
		if ($value === '')
		{
			return '';
		}

		$value = preg_replace('/[^a-z0-9_-]+/i', '-', $value) ?: $value;
		$value = trim($value, '-_');

		return $value;
	}

	private function addCardLabel(array &$cardLabels, string $cardCode, string $selector): void
	{
		if (!isset($cardLabels[$cardCode]))
		{
			$cardLabels[$cardCode] = [];
		}
		if (!in_array($selector, $cardLabels[$cardCode], true))
		{
			$cardLabels[$cardCode][] = $selector;
		}
	}

	private function appendClass($element, string $class): void
	{
		$current = trim((string)$element->getAttribute('class'));
		if ($current === '')
		{
			$element->setAttribute('class', $class);
			return;
		}
		$parts = preg_split('/\s+/', $current);
		if (!in_array($class, $parts, true))
		{
			$parts[] = $class;
			$element->setAttribute('class', trim(implode(' ', $parts)));
		}
	}
}
