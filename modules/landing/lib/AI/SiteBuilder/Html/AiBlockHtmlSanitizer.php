<?php

declare(strict_types=1);

namespace Bitrix\Landing\AI\SiteBuilder\Html;

use Bitrix\Landing\Sanitizer;
use Bitrix\Main\Web\DOM;

/**
 * Allow-list sanitizer for AI block markup: a tag, an attribute or a url scheme that is not
 * listed here does not survive.
 *
 * The result is stored in b_landing_repo.CONTENT and rendered on a public page as is, so the
 * sanitizer has to agree with the browser on where a tag and an attribute end. It parses with
 * Bitrix\Main\Web\DOM - the same implementation that serializes the stored block in
 * Copilot\Services\Manifest\Builder - and re-serializes the tree, so what is stored is the
 * canonical form of what was inspected: every tag name comes from the allow-list and every
 * attribute value is re-encoded. Matching patterns against raw markup cannot give that
 * guarantee: `<svg/onload=alert(1)>` is a tag with an event handler for the browser, a single
 * tag name for this parser and no match at all for a pattern written against whitespace.
 */
final class AiBlockHtmlSanitizer
{
	/**
	 * Tags an AI block is built from: the markup contract of the generation prompt
	 * (dev/prompt/ru/ai-block-html.txt) plus inline formatting and tables that the editor
	 * can leave in a block later edited by hand.
	 */
	private const ALLOWED_TAGS = [
		'a',
		'address',
		'article',
		'aside',
		'b',
		'blockquote',
		'br',
		'caption',
		'cite',
		'code',
		'col',
		'colgroup',
		'dd',
		'details',
		'div',
		'dl',
		'dt',
		'em',
		'figcaption',
		'figure',
		'font',
		'footer',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'header',
		'hr',
		'i',
		'img',
		'li',
		'main',
		'mark',
		'nav',
		'ol',
		'p',
		'pre',
		'q',
		's',
		'section',
		'small',
		'span',
		'strong',
		'sub',
		'summary',
		'sup',
		'table',
		'tbody',
		'td',
		'tfoot',
		'th',
		'thead',
		'time',
		'tr',
		'u',
		'ul',
	];

	/**
	 * Tags removed together with their content: their content is script, markup for another
	 * parser or an external resource, never text of the block. Everything else outside
	 * ALLOWED_TAGS is unwrapped instead, so its text survives.
	 */
	private const TAGS_DROPPED_WITH_CONTENT = [
		'applet',
		'audio',
		'base',
		'canvas',
		'embed',
		'frame',
		'frameset',
		'head',
		'iframe',
		'link',
		'math',
		'meta',
		'noembed',
		'noframes',
		'noscript',
		'object',
		'plaintext',
		'script',
		'style',
		'svg',
		'template',
		'textarea',
		'title',
		'video',
		'xmp',
	];

	private const ALLOWED_ATTRIBUTES = [
		'align',
		'alt',
		'class',
		'colspan',
		'datetime',
		'decoding',
		'dir',
		'height',
		'href',
		'id',
		'lang',
		'loading',
		'open',
		'rel',
		'reversed',
		'role',
		'rowspan',
		'span',
		'src',
		'start',
		'style',
		'target',
		'title',
		'type',
		'value',
		'width',
	];

	/**
	 * Attributes the browser resolves as a url. Most of them belong to tags that are not
	 * allowed at all; the check runs by attribute name regardless of the tag, so a url sink
	 * added to ALLOWED_ATTRIBUTES later is covered by default - as long as an attribute
	 * holding a list of urls is listed in URL_LIST_ATTRIBUTES too.
	 */
	private const URL_ATTRIBUTES = [
		'action',
		'background',
		'cite',
		'data',
		'data-href',
		'data-preview',
		'data-source',
		'data-src',
		'data-url',
		'formaction',
		'href',
		'longdesc',
		'ping',
		'poster',
		'src',
		'srcset',
	];

	/**
	 * Url attributes whose value is a list of urls: `srcset` separates a url and its descriptor
	 * by whitespace and the pairs by a comma, `ping` is a whitespace separated list. A scheme is
	 * read from the beginning of a value, so a list has to be checked item by item - as a whole
	 * `/a.png 1x, javascript:alert(1) 2x` carries no scheme at all.
	 */
	private const URL_LIST_ATTRIBUTES = [
		'ping',
		'srcset',
	];

	/**
	 * Style values with these constructs are dropped whole. The check does not clean css - it
	 * rejects the attribute - because a partially cleaned style is exactly the kind of
	 * guarantee patterns cannot give.
	 */
	private const FORBIDDEN_STYLE_TOKENS = [
		'javascript:',
		'vbscript:',
		'expression(',
		'-moz-binding',
		'behavior:',
		'@import',
	];

	/**
	 * Serves every data-pseudo-url of a single sanitize() run: Sanitizer caches the security text
	 * auditor per instance, and building that auditor costs more than checking one attribute with
	 * it. The run releases the instance when it ends - a static living longer would carry the
	 * auditor, and the last value it filtered, across requests and across jobs of a long process.
	 */
	private static ?Sanitizer $pseudoUrlSanitizer = null;

	public static function sanitize(string $html): string
	{
		if (trim($html) === '')
		{
			return '';
		}

		try
		{
			$document = new DOM\Document();
			$document->loadHTML($html);
			self::sanitizeChildNodes($document);

			return trim((string)$document->saveHTML());
		}
		catch (\Throwable)
		{
			return '';
		}
		finally
		{
			self::$pseudoUrlSanitizer = null;
		}
	}

	private static function sanitizeChildNodes(DOM\Node $parent): void
	{
		foreach ($parent->getChildNodesArray() as $node)
		{
			self::sanitizeNode($parent, $node);
		}
	}

	private static function sanitizeNode(DOM\Node $parent, DOM\Node $node): void
	{
		if (!($node instanceof DOM\Element))
		{
			// a comment keeps its source intact through serialization, including php slices
			// restored by the parser, so only text survives next to elements
			if ($node->getNodeType() !== DOM\Node::TEXT_NODE)
			{
				$parent->removeChild($node);
			}

			return;
		}

		$tagName = mb_strtolower((string)$node->getNodeName());
		if (
			!self::isWellFormedTagName($tagName)
			|| in_array($tagName, self::TAGS_DROPPED_WITH_CONTENT, true)
		)
		{
			$parent->removeChild($node);

			return;
		}

		self::sanitizeChildNodes($node);

		if (in_array($tagName, self::ALLOWED_TAGS, true))
		{
			self::sanitizeAttributes($node);

			return;
		}

		self::unwrapElement($parent, $node);
	}

	/**
	 * A name the parser could not split into tag and attributes - `svg/onload="alert(1)"` is
	 * one such name - is markup the browser reads differently, so it is never kept.
	 */
	private static function isWellFormedTagName(string $tagName): bool
	{
		return (bool)preg_match('/^[a-z][a-z0-9]*$/D', $tagName);
	}

	private static function unwrapElement(DOM\Node $parent, DOM\Element $element): void
	{
		foreach ($element->getChildNodesArray() as $child)
		{
			$element->removeChild($child);
			$parent->insertBefore($child, $element);
		}

		$parent->removeChild($element);
	}

	private static function sanitizeAttributes(DOM\Element $element): void
	{
		foreach ($element->getAttributesArray() as $attribute)
		{
			if (!($attribute instanceof DOM\Attr))
			{
				continue;
			}

			$name = mb_strtolower((string)$attribute->getName());
			if (!self::isAllowedAttribute($name))
			{
				$element->removeAttributeNode($attribute);

				continue;
			}

			$value = (string)$attribute->getValue();

			if ($name === 'style' && !self::isSafeStyle($value))
			{
				$element->removeAttributeNode($attribute);

				continue;
			}

			// a json link description, not a url: it goes through the mechanism that writes the
			// same attribute from the editor (Node\Img, Node\Icon, Node\Text), so the link
			// policy stays in one place
			if ($name === 'data-pseudo-url')
			{
				$pseudoUrl = self::sanitizePseudoUrl($value);
				if ($pseudoUrl === '')
				{
					$element->removeAttributeNode($attribute);

					continue;
				}

				$attribute->setValue($pseudoUrl);

				continue;
			}

			if (in_array($name, self::URL_ATTRIBUTES, true) && !self::isSafeUrl($name, $value))
			{
				$attribute->setValue($name === 'href' ? '#' : '');
			}
		}
	}

	/**
	 * Link policy for the pseudo-url json: scheme allow-list for the href and only the keys the
	 * link control writes. Unlike the rest of the class this needs the kernel - the module
	 * mechanism runs the text auditor, and that one loads the security module - so a context
	 * without a kernel loses the attribute instead of the whole block.
	 */
	private static function sanitizePseudoUrl(string $value): string
	{
		try
		{
			self::$pseudoUrlSanitizer ??= new Sanitizer();

			return self::$pseudoUrlSanitizer->sanitizePseudoUrl($value);
		}
		catch (\Throwable)
		{
			return '';
		}
	}

	private static function isAllowedAttribute(string $name): bool
	{
		if (in_array($name, self::ALLOWED_ATTRIBUTES, true))
		{
			return true;
		}

		return (bool)preg_match('/^(?:data|aria)-[a-z][a-z0-9_-]*$/D', $name);
	}

	/**
	 * Url policy of the module: the scheme allow-list of Landing\Sanitizer, shared with the
	 * editor link control, so a link a user sets on an AI block node survives here too.
	 * The value itself is kept byte for byte when it passes - the parser has already decoded
	 * entities and the serializer encodes them back.
	 */
	private static function isSafeUrl(string $name, string $value): bool
	{
		if (!self::isSafeUrlValue($value))
		{
			return false;
		}

		if (!in_array($name, self::URL_LIST_ATTRIBUTES, true))
		{
			return true;
		}

		// a descriptor of a srcset item (`2x`, `640w`) carries no scheme, so splitting by both
		// separators at once and checking every piece costs nothing and needs no url grammar
		foreach (preg_split('/[,\s]+/', $value) ?: [] as $item)
		{
			if (!self::isSafeUrlValue($item))
			{
				return false;
			}
		}

		return true;
	}

	private static function isSafeUrlValue(string $value): bool
	{
		$value = trim($value);
		if ($value === '')
		{
			return true;
		}

		// the browser decodes character references in an attribute value before it reads the
		// scheme, so both forms have to pass
		$decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);

		return !Sanitizer::hasDisallowedScheme($value)
			&& !Sanitizer::hasDisallowedScheme($decoded)
		;
	}

	private static function isSafeStyle(string $value): bool
	{
		$variants = [$value, html_entity_decode($value, ENT_QUOTES | ENT_HTML5)];
		foreach ($variants as $variant)
		{
			// byte-wise: the tokens are ascii and the value is not guaranteed to be valid utf-8
			$normalized = strtolower((string)preg_replace('/[\s\x00-\x1F\x7F]+/', '', $variant));
			foreach (self::FORBIDDEN_STYLE_TOKENS as $token)
			{
				if (str_contains($normalized, $token))
				{
					return false;
				}
			}
		}

		return true;
	}
}
