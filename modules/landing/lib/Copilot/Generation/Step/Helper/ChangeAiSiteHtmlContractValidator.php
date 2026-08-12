<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step\Helper;

use Bitrix\Landing\AI\SiteBuilder\Font\AiFontPolicy;
use Bitrix\Main\Web\DOM;

final class ChangeAiSiteHtmlContractValidator
{
	private const ROOT_NODE_ID = 'change-ai-site-contract-root';
	private const CRM_FORM_MARKER = '#CRM_FORM#';
	private const CRM_FORM_COLORS_ATTR = 'data-crm-form-colors';
	private const SUPPORTED_IMAGE_ASPECTS = ['landscape', 'portrait', 'square'];
	private const CRM_COLOR_KEYS = [
		'primary',
		'primaryText',
		'text',
		'background',
		'fieldBorder',
		'fieldBackground',
		'fieldFocusBackground',
	];
	private const FORBIDDEN_TAGS = [
		'script',
		'style',
		'svg',
		'path',
		'circle',
		'rect',
		'line',
		'polyline',
		'polygon',
		'defs',
		'clippath',
		'mask',
		'g',
		'use',
		'symbol',
		'form',
		'input',
		'textarea',
		'select',
		'option',
		'label',
		'button',
		'iframe',
		'object',
		'embed',
		'canvas',
		'video',
		'audio',
	];
	private const FORBIDDEN_RAW_TOKENS = [
		'<?',
		'```',
		'<!--',
		'-->',
		'data:image',
		'image/svg',
		'svg+xml',
		'xmlns',
		'viewbox',
		'background-image',
		'url(',
		'bg-[url(',
		'bitrix24forms',
		'bitrix24form',
	];
	private const SERVICE_FONT_PREFIXES = [
		'g-font-size-',
		'g-font-weight-',
		'g-font-style-',
	];

	/**
	 * @return array{isValid: bool, blockingDiagnostics: list<string>, warningDiagnostics: list<string>}
	 */
	public function validate(string $html): array
	{
		$blockingDiagnostics = [];
		$html = trim($html);
		if ($html === '')
		{
			return $this->buildResult(['empty_html']);
		}

		$blockingDiagnostics = array_merge($blockingDiagnostics, $this->inspectRawTokens($html));

		try
		{
			$doc = new DOM\Document();
			$doc->loadHTML('<div id="' . self::ROOT_NODE_ID . '">' . $html . '</div>');
			$root = $doc->querySelector('#' . self::ROOT_NODE_ID);
			if (!$root)
			{
				$blockingDiagnostics[] = 'dom_root_missing';

				return $this->buildResult($blockingDiagnostics);
			}

			$rootElement = $this->resolveSingleRootElement($root, $blockingDiagnostics);
			if ($rootElement)
			{
				$blockingDiagnostics = array_merge(
					$blockingDiagnostics,
					$this->inspectRootElement($rootElement),
				);
			}

			foreach ($this->collectElements($root) as $element)
			{
				$blockingDiagnostics = array_merge(
					$blockingDiagnostics,
					$this->inspectElement($element),
				);
			}

			$blockingDiagnostics = array_merge(
				$blockingDiagnostics,
				$this->inspectCrmContract($root),
			);
			$blockingDiagnostics = array_merge(
				$blockingDiagnostics,
				$this->inspectMenuContract($root),
			);
		}
		catch (\Throwable)
		{
			$blockingDiagnostics[] = 'html_parse_failed';
		}

		return $this->buildResult($blockingDiagnostics);
	}

	/**
	 * @param list<string> $blockingDiagnostics
	 * @return array{isValid: bool, blockingDiagnostics: list<string>, warningDiagnostics: list<string>}
	 */
	private function buildResult(array $blockingDiagnostics): array
	{
		$blockingDiagnostics = array_values(array_unique($blockingDiagnostics));

		return [
			'isValid' => $blockingDiagnostics === [],
			'blockingDiagnostics' => $blockingDiagnostics,
			'warningDiagnostics' => [],
		];
	}

	/**
	 * @return list<string>
	 */
	private function inspectRawTokens(string $html): array
	{
		$diagnostics = [];
		$lowerHtml = mb_strtolower($html);
		foreach (self::FORBIDDEN_RAW_TOKENS as $token)
		{
			if (str_contains($lowerHtml, $token))
			{
				$diagnostics[] = 'forbidden_token_' . $this->normalizeDiagnosticToken($token);
			}
		}

		return $diagnostics;
	}

	/**
	 * @return list<string>
	 */
	private function inspectMenuContract(DOM\Element $root): array
	{
		$diagnostics = [];
		foreach ($this->collectElements($root) as $menu)
		{
			if (!$menu->hasAttribute('data-ai-menu'))
			{
				continue;
			}

			$hasDisclosure = false;
			$hasDesktopList = false;
			$hasMobileList = false;
			foreach ($this->collectElements($menu) as $element)
			{
				if ($element->hasAttribute('data-ai-menu-disclosure'))
				{
					$hasDisclosure = true;
				}

				if ($element->hasAttribute('data-ai-menu-desktop-list'))
				{
					$hasDesktopList = true;
					if ($this->hasAncestorWithAttribute($element, 'data-ai-menu-disclosure', $menu))
					{
						$diagnostics[] = 'menu_desktop_list_inside_disclosure';
					}
				}

				if ($element->hasAttribute('data-ai-menu-list'))
				{
					$hasMobileList = true;
					$classes = (string)$element->getAttribute('class');
					if (!$this->classValueContains($classes, 'group-open:flex'))
					{
						$diagnostics[] = 'menu_mobile_list_missing_group_open';
					}
					if ($this->classValueContains($classes, 'lg:!flex') || $this->classValueContains($classes, 'lg:flex'))
					{
						$diagnostics[] = 'menu_mobile_list_has_desktop_visibility';
					}
					if (!$this->hasAncestorWithAttribute($element, 'data-ai-menu-disclosure', $menu))
					{
						$diagnostics[] = 'menu_mobile_list_outside_disclosure';
					}
				}
			}

			if (($hasDisclosure || $hasMobileList) && !$hasDesktopList)
			{
				$diagnostics[] = 'menu_desktop_list_missing';
			}
		}

		return $diagnostics;
	}

	private function hasAncestorWithAttribute(DOM\Element $element, string $attributeName, DOM\Element $stopNode): bool
	{
		$parent = $element->getParentNode();
		while ($parent instanceof DOM\Element)
		{
			if ($parent === $stopNode)
			{
				return false;
			}
			if ($parent->hasAttribute($attributeName))
			{
				return true;
			}
			$parent = $parent->getParentNode();
		}

		return false;
	}

	/**
	 * @param list<string> $diagnostics
	 */
	private function resolveSingleRootElement(DOM\Element $root, array &$diagnostics): ?DOM\Element
	{
		$elements = [];
		foreach ($root->getChildNodesArray() as $child)
		{
			if ($child->getNodeType() === DOM\Node::COMMENT_NODE)
			{
				$diagnostics[] = 'comment_node';
				continue;
			}

			if ($child->getNodeType() === DOM\Node::TEXT_NODE)
			{
				if (trim((string)$child->getTextContent()) !== '')
				{
					$diagnostics[] = 'text_outside_root';
				}
				continue;
			}

			if ($child instanceof DOM\Element)
			{
				$elements[] = $child;
			}
		}

		if (count($elements) !== 1)
		{
			$diagnostics[] = 'root_element_count';

			return $elements[0] ?? null;
		}

		return $elements[0];
	}

	/**
	 * @return list<string>
	 */
	private function inspectRootElement(DOM\Element $element): array
	{
		$diagnostics = [];
		if (mb_strtolower($element->getNodeName()) !== 'section')
		{
			$diagnostics[] = 'root_not_section';
		}

		if (!$this->classValueHasAllowedRootFont((string)$element->getAttribute('class')))
		{
			$diagnostics[] = 'root_missing_allowed_g_font';
		}

		return $diagnostics;
	}

	/**
	 * @return list<DOM\Element>
	 */
	private function collectElements(DOM\Element $root): array
	{
		$elements = [];
		foreach ($root->getChildNodesArray() as $child)
		{
			if ($child instanceof DOM\Element)
			{
				$elements[] = $child;
				$elements = array_merge($elements, $this->collectElements($child));
			}
		}

		return $elements;
	}

	/**
	 * @return list<string>
	 */
	private function inspectElement(DOM\Element $element): array
	{
		$diagnostics = [];
		$tagName = mb_strtolower($element->getNodeName());
		if (in_array($tagName, self::FORBIDDEN_TAGS, true))
		{
			$diagnostics[] = 'forbidden_tag_' . $tagName;
		}

		foreach ($element->getAttributesArray() as $attribute)
		{
			if (!$attribute instanceof DOM\Attr)
			{
				continue;
			}

			$name = mb_strtolower($attribute->getName());
			$value = (string)$attribute->getValue();
			if ($name === 'id')
			{
				$diagnostics[] = 'forbidden_attribute_id';
			}
			if ($name === 'style')
			{
				$diagnostics[] = 'forbidden_attribute_style';
			}
			if (str_starts_with($name, 'on'))
			{
				$diagnostics[] = 'forbidden_attribute_event_handler';
			}
			if (str_starts_with($name, 'data-b24form'))
			{
				$diagnostics[] = 'forbidden_attribute_data_b24form';
			}
			if ($name === 'class' && $this->classValueHasForbiddenFont($value))
			{
				$diagnostics[] = 'forbidden_font_family_class';
			}
			if ($name === 'class' && $this->classValueHasUnknownFont($value))
			{
				$diagnostics[] = 'unknown_g_font_class';
			}
			if (str_contains($value, self::CRM_FORM_MARKER))
			{
				$diagnostics[] = 'crm_marker_not_plain_text_node';
			}
		}

		if ($tagName === 'img')
		{
			$diagnostics = array_merge($diagnostics, $this->inspectImage($element));
		}

		return $diagnostics;
	}

	/**
	 * @return list<string>
	 */
	private function inspectImage(DOM\Element $element): array
	{
		$diagnostics = [];
		$classes = (string)$element->getAttribute('class');
		if (!$this->hasWidthConstraint($classes) || !$this->hasHeightConstraint($classes))
		{
			$diagnostics[] = 'image_missing_size_constraints';
		}

		$prompt = trim((string)$element->getAttribute('data-ai-prompt'));
		if ($prompt !== '')
		{
			$aspect = trim((string)$element->getAttribute('data-ai-aspect'));
			if (!in_array($aspect, self::SUPPORTED_IMAGE_ASPECTS, true))
			{
				$diagnostics[] = 'image_prompt_missing_valid_aspect';
			}
			if (trim((string)$element->getAttribute('src')) !== '')
			{
				$diagnostics[] = 'image_prompt_src_not_empty';
			}
		}

		return $diagnostics;
	}

	/**
	 * @return list<string>
	 */
	private function inspectCrmContract(DOM\Element $root): array
	{
		$diagnostics = [];
		$markerNodes = [];
		$crmColorNodes = [];
		$this->collectCrmNodes($root, $markerNodes, $crmColorNodes);

		if (count($markerNodes) === 0)
		{
			if ($crmColorNodes !== [])
			{
				$diagnostics[] = 'crm_colors_without_marker';
			}

			return $diagnostics;
		}

		if (count($markerNodes) !== 1)
		{
			$diagnostics[] = 'crm_marker_count';
		}

		$markerNode = $markerNodes[0] ?? null;
		if ($markerNode && trim((string)$markerNode->getTextContent()) !== self::CRM_FORM_MARKER)
		{
			$diagnostics[] = 'crm_marker_not_plain_text_node';
		}

		$container = $markerNode?->getParentNode();
		if (!$container instanceof DOM\Element)
		{
			$diagnostics[] = 'crm_marker_container_missing';

			return $diagnostics;
		}

		if (!$container->hasAttribute(self::CRM_FORM_COLORS_ATTR))
		{
			$diagnostics[] = 'crm_colors_missing';

			return $diagnostics;
		}

		$diagnostics = array_merge(
			$diagnostics,
			$this->inspectCrmColors((string)$container->getAttribute(self::CRM_FORM_COLORS_ATTR)),
		);

		return $diagnostics;
	}

	/**
	 * @param list<DOM\Node> $markerNodes
	 * @param list<DOM\Element> $crmColorNodes
	 */
	private function collectCrmNodes(DOM\Node $node, array &$markerNodes, array &$crmColorNodes): void
	{
		if ($node instanceof DOM\Element && $node->hasAttribute(self::CRM_FORM_COLORS_ATTR))
		{
			$crmColorNodes[] = $node;
		}

		if ($node->getNodeType() === DOM\Node::TEXT_NODE && str_contains((string)$node->getTextContent(), self::CRM_FORM_MARKER))
		{
			$markerNodes[] = $node;
		}

		foreach ($node->getChildNodesArray() as $child)
		{
			$this->collectCrmNodes($child, $markerNodes, $crmColorNodes);
		}
	}

	/**
	 * @return list<string>
	 */
	private function inspectCrmColors(string $colorsJson): array
	{
		$colors = json_decode(html_entity_decode(trim($colorsJson), ENT_QUOTES | ENT_HTML5), true);
		if (!is_array($colors))
		{
			return ['crm_colors_invalid_json'];
		}

		$keys = array_keys($colors);
		sort($keys);
		$expected = self::CRM_COLOR_KEYS;
		sort($expected);
		if ($keys !== $expected)
		{
			return ['crm_colors_invalid_keys'];
		}

		foreach ($colors as $value)
		{
			if (!is_string($value) || preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value) !== 1)
			{
				return ['crm_colors_invalid_hex'];
			}
		}

		return [];
	}

	private function classValueHasAllowedRootFont(string $classValue): bool
	{
		foreach ($this->splitClasses($classValue) as $class)
		{
			$class = mb_strtolower($this->resolveBaseClass($class));
			if (
				str_starts_with($class, 'g-font-')
				&& !$this->isServiceFontClass($class)
				&& AiFontPolicy::isAllowedFontClass($class)
			)
			{
				return true;
			}
		}

		return false;
	}

	private function classValueHasForbiddenFont(string $classValue): bool
	{
		foreach ($this->splitClasses($classValue) as $class)
		{
			$class = mb_strtolower($this->resolveBaseClass($class));
			if (in_array($class, ['font-sans', 'font-serif', 'font-mono'], true))
			{
				return true;
			}
			if (str_contains($class, '[font-family:'))
			{
				return true;
			}
		}

		return false;
	}

	private function classValueHasUnknownFont(string $classValue): bool
	{
		foreach ($this->splitClasses($classValue) as $class)
		{
			$class = mb_strtolower($this->resolveBaseClass($class));
			if (str_starts_with($class, 'g-font-') && !$this->isServiceFontClass($class) && !AiFontPolicy::isAllowedFontClass($class))
			{
				return true;
			}
		}

		return false;
	}

	private function hasWidthConstraint(string $classes): bool
	{
		$classes = implode(' ', array_map([$this, 'resolveBaseClass'], $this->splitClasses($classes)));

		return preg_match('/(^|\s)(w-|max-w-)/u', $classes) === 1;
	}

	private function hasHeightConstraint(string $classes): bool
	{
		$classes = implode(' ', array_map([$this, 'resolveBaseClass'], $this->splitClasses($classes)));
		if (preg_match('/(^|\s)max-h-/u', $classes) === 1)
		{
			return true;
		}
		if (preg_match('/(^|\s)h-auto(\s|$)/u', $classes) === 1)
		{
			return false;
		}

		return preg_match('/(^|\s)h-/u', $classes) === 1;
	}

	private function classValueContains(string $classValue, string $expectedClass): bool
	{
		return in_array($expectedClass, $this->splitClasses($classValue), true);
	}

	/**
	 * @return list<string>
	 */
	private function splitClasses(string $classValue): array
	{
		return array_values(array_filter(preg_split('/\s+/u', trim($classValue)) ?: []));
	}

	private function resolveBaseClass(string $class): string
	{
		$pos = strrpos($class, ':');
		if ($pos === false)
		{
			return $class;
		}

		$bracketPos = strpos($class, '[');
		if ($bracketPos !== false && $pos > $bracketPos)
		{
			return $class;
		}

		return substr($class, $pos + 1);
	}

	private function isServiceFontClass(string $fontClass): bool
	{
		foreach (self::SERVICE_FONT_PREFIXES as $prefix)
		{
			if (str_starts_with($fontClass, $prefix))
			{
				return true;
			}
		}

		return false;
	}

	private function normalizeDiagnosticToken(string $token): string
	{
		return trim(preg_replace('/[^a-z0-9]+/i', '_', $token) ?? '', '_');
	}
}
