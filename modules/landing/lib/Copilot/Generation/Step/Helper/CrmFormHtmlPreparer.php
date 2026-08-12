<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step\Helper;

use Bitrix\Landing\Subtype\Form;
use Bitrix\Main\Web\DOM;

final class CrmFormHtmlPreparer
{
	private const CRM_FORM_MARKER = '#CRM_FORM#';
	private const CRM_FORM_COLORS_ATTR = 'data-crm-form-colors';

	public function prepare(string $html, string $rootNodeId, string $fallbackEmbedHtml, array $context = []): string
	{
		try
		{
			$doc = new DOM\Document();
			$doc->loadHTML('<div id="' . $rootNodeId . '">' . $html . '</div>');

			$root = $doc->querySelector('#' . $rootNodeId);
			if (!$root)
			{
				return $this->replaceCrmFormMarker($html, $fallbackEmbedHtml);
			}

			$changed = false;
			$normalizer = new CrmFormColorNormalizer();
			$colorNodes = $doc->querySelectorAll('[' . self::CRM_FORM_COLORS_ATTR . ']');
			foreach ($colorNodes as $node)
			{
				$innerHtml = (string)$node->getInnerHTML();
				if (!str_contains($innerHtml, self::CRM_FORM_MARKER))
				{
					$node->removeAttribute(self::CRM_FORM_COLORS_ATTR);
					continue;
				}

				$colors = $normalizer->normalize(
					$this->extractColors((string)$node->getAttribute(self::CRM_FORM_COLORS_ATTR)),
					$this->extendContext($context, $html, $node),
				);
				$node->removeAttribute(self::CRM_FORM_COLORS_ATTR);
				$node->setInnerHTML(
					str_replace(
						self::CRM_FORM_MARKER,
						Form::getDefaultEmbedHtml($colors),
						$innerHtml,
					)
				);
				$changed = true;
			}

			$preparedHtml = ChangeAiSiteDomHelper::extractRootHtml($root);
			if (!$changed || str_contains($preparedHtml, self::CRM_FORM_MARKER))
			{
				$preparedHtml = $this->replaceCrmFormMarker($preparedHtml, $fallbackEmbedHtml);
			}

			return $preparedHtml !== '' ? $preparedHtml : $html;
		}
		catch (\Throwable)
		{
			return $this->replaceCrmFormMarker($html, $fallbackEmbedHtml);
		}
	}

	public function prepareMarkerContract(string $html, string $rootNodeId, array $context = []): string
	{
		if ($html === '' || !str_contains($html, self::CRM_FORM_MARKER))
		{
			return $html;
		}

		try
		{
			$doc = new DOM\Document();
			$doc->loadHTML('<div id="' . $rootNodeId . '">' . $html . '</div>');

			$root = $doc->querySelector('#' . $rootNodeId);
			if (!$root)
			{
				return $html;
			}

			$markerContainer = $this->findMarkerContainer($root);
			if (!$markerContainer)
			{
				return $html;
			}

			$rawColors = $this->extractColorsFromNearestColorNode($root, $markerContainer);
			$normalizer = new CrmFormColorNormalizer();
			$colors = $normalizer->normalize($rawColors, $this->extendContext($context, $html, $markerContainer));

			foreach ($root->querySelectorAll('[' . self::CRM_FORM_COLORS_ATTR . ']') as $node)
			{
				if ($node !== $markerContainer)
				{
					$node->removeAttribute(self::CRM_FORM_COLORS_ATTR);
				}
			}

			$markerContainer->setAttribute(self::CRM_FORM_COLORS_ATTR, $normalizer->encodeForHtmlAttribute($colors));
			$preparedHtml = ChangeAiSiteDomHelper::extractRootHtml($root);

			return $preparedHtml !== '' ? $preparedHtml : $html;
		}
		catch (\Throwable)
		{
			return $html;
		}
	}

	public function buildFallbackEmbedHtml(array $context = []): string
	{
		try
		{
			return Form::getDefaultEmbedHtml((new CrmFormColorNormalizer())->normalize([], $context));
		}
		catch (\Throwable)
		{
			return '<div data-b24form-embed data-b24form-use-style="Y" data-b24form-design="{}"></div>';
		}
	}

	public function hasMarkerColorContract(string $html, string $rootNodeId): bool
	{
		if ($html === '' || !str_contains($html, self::CRM_FORM_MARKER))
		{
			return true;
		}

		try
		{
			$doc = new DOM\Document();
			$doc->loadHTML('<div id="' . $rootNodeId . '">' . $html . '</div>');
			$root = $doc->querySelector('#' . $rootNodeId);
			if (!$root)
			{
				return false;
			}

			$markerContainer = $this->findMarkerContainer($root);
			if (!$markerContainer)
			{
				return false;
			}

			$decoded = json_decode(
				html_entity_decode((string)$markerContainer->getAttribute(self::CRM_FORM_COLORS_ATTR), ENT_QUOTES | ENT_HTML5),
				true,
			);
			if (!is_array($decoded))
			{
				return false;
			}

			$keys = array_keys($decoded);
			$expectedKeys = CrmFormColorNormalizer::COLOR_KEYS;
			sort($keys);
			sort($expectedKeys);
			if ($keys !== $expectedKeys)
			{
				return false;
			}

			foreach ($decoded as $value)
			{
				if (!is_string($value) || !preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', trim($value)))
				{
					return false;
				}
			}

			return true;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	private function replaceCrmFormMarker(string $html, string $embedHtml): string
	{
		return str_replace(self::CRM_FORM_MARKER, $embedHtml, $html);
	}

	private function extractColors(string $rawValue): array
	{
		$decoded = json_decode($rawValue, true);

		return is_array($decoded) ? $decoded : [];
	}

	private function extendContext(array $context, string $html, object $node): array
	{
		$context['html'] = $context['html'] ?? $html;
		$class = method_exists($node, 'getAttribute') ? (string)$node->getAttribute('class') : '';
		if ($class !== '')
		{
			$context['containerClass'] = $class;
		}

		return $context;
	}

	private function findMarkerContainer(object $root): ?object
	{
		foreach ($root->getChildNodesArray() as $child)
		{
			if (!$this->isElementNode($child))
			{
				continue;
			}

			$candidate = $this->findDeepestMarkerContainer($child);
			if ($candidate)
			{
				return $candidate;
			}
		}

		return null;
	}

	private function findDeepestMarkerContainer(object $node): ?object
	{
		if (
			!method_exists($node, 'getInnerHTML')
			|| !str_contains((string)$node->getInnerHTML(), self::CRM_FORM_MARKER)
		)
		{
			return null;
		}

		foreach ($node->getChildNodesArray() as $child)
		{
			if (!$this->isElementNode($child))
			{
				continue;
			}

			$candidate = $this->findDeepestMarkerContainer($child);
			if ($candidate)
			{
				return $candidate;
			}
		}

		return $node;
	}

	private function extractColorsFromNearestColorNode(object $root, object $markerContainer): array
	{
		$bestNode = null;
		$bestDepth = -1;
		foreach ($root->querySelectorAll('[' . self::CRM_FORM_COLORS_ATTR . ']') as $node)
		{
			if (!str_contains((string)$node->getInnerHTML(), self::CRM_FORM_MARKER))
			{
				continue;
			}

			$depth = $this->resolveNodeDepth($node);
			if ($node === $markerContainer)
			{
				$bestNode = $node;
				break;
			}
			if ($depth > $bestDepth)
			{
				$bestNode = $node;
				$bestDepth = $depth;
			}
		}

		if (!$bestNode)
		{
			return [];
		}

		return $this->extractColors((string)$bestNode->getAttribute(self::CRM_FORM_COLORS_ATTR));
	}

	private function resolveNodeDepth(object $node): int
	{
		$depth = 0;
		while (method_exists($node, 'getParentNode') && $node->getParentNode())
		{
			$depth++;
			$node = $node->getParentNode();
		}

		return $depth;
	}

	private function isElementNode(mixed $node): bool
	{
		return is_object($node)
			&& method_exists($node, 'getNodeType')
			&& $node->getNodeType() === DOM\Node::ELEMENT_NODE
		;
	}
}
