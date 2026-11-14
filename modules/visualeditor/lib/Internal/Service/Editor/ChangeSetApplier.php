<?php

declare(strict_types=1);

namespace Bitrix\VisualEditor\Internal\Service\Editor;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\VisualEditor\Public\Adapter\EditorCapabilities;

final class ChangeSetApplier
{
	private const VOID_TAGS = [
		'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr',
	];
	private const RAW_TEXT_TAGS = [
		'script', 'style', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript', 'textarea', 'title',
	];
	private const LOADING_TAGS = [
		'script', 'link', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'base', 'meta', 'param',
		'animate', 'set', 'animatemotion', 'animatetransform', 'animatecolor', 'discard',
	];
	private const P_CLOSING_TAGS = [
		'address', 'article', 'aside', 'blockquote', 'center', 'dd', 'dt', 'li', 'listing', 'plaintext',
		'summary', 'xmp', 'details', 'dialog', 'dir', 'div', 'dl', 'fieldset',
		'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup',
		'hr', 'main', 'menu', 'nav', 'ol', 'p', 'pre', 'search', 'section', 'table', 'ul',
	];
	private const HEADING_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
	private const LIST_SCOPE_BOUNDARIES = [
		'applet', 'caption', 'html', 'table', 'td', 'th', 'marquee', 'object', 'template', 'ol', 'ul',
	];
	private const ITEM_SCOPE_BOUNDARIES = [
		'applet', 'article', 'aside', 'basefont', 'bgsound', 'blockquote', 'body', 'button',
		'caption', 'center', 'colgroup',
		'dd', 'details', 'dialog', 'dir', 'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer',
		'form', 'frame', 'frameset', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'header', 'hgroup', 'html',
		'keygen', 'li', 'listing', 'main', 'marquee', 'menu', 'nav', 'object', 'ol', 'plaintext', 'pre',
		'search', 'section', 'select', 'summary', 'table', 'tbody', 'td', 'template', 'tfoot',
		'th', 'thead', 'tr', 'ul',
	];
	private const IMPLICIT_CLOSE_TARGETS = [
		'a' => ['a'],
		'button' => ['button'],
		'li' => ['li'],
		'dt' => ['dt', 'dd'],
		'dd' => ['dt', 'dd'],
		'tr' => ['tr', 'td', 'th'],
		'td' => ['td', 'th'],
		'th' => ['td', 'th'],
		'thead' => ['thead', 'tbody', 'tfoot', 'tr', 'td', 'th'],
		'tbody' => ['thead', 'tbody', 'tfoot', 'tr', 'td', 'th'],
		'tfoot' => ['thead', 'tbody', 'tfoot', 'tr', 'td', 'th'],
		'option' => ['option'],
		'optgroup' => ['option', 'optgroup'],
		'rt' => ['rt', 'rp'],
		'rp' => ['rt', 'rp'],
	];

	public function __construct(private readonly ?ChangeSetValidator $validator = null)
	{
	}

	public function apply(
		string $baselineHtml,
		array $changeSet,
		EditorCapabilities $capabilities = new EditorCapabilities(),
	): Result
	{
		$validator = $this->validator ?? new ChangeSetValidator();
		$envelope = $validator->validateEnvelope($changeSet, $capabilities);
		if (!$envelope->isSuccess())
		{
			return $envelope;
		}

		$file = HtmlCharset::decode($baselineHtml);
		$validation = $validator->validate(
			$changeSet,
			function (array $operation) use ($file): bool {
				$elements = $this->parseElements($file['text'], [$operation['id']]);
				$element = $elements[$operation['id']] ?? null;
				if ($element === null || $element['closeStart'] < $element['openEnd'])
				{
					return false;
				}
				$before = substr(
					$file['text'],
					$element['openEnd'],
					$element['closeStart'] - $element['openEnd'],
				);

				return $this->markupSignature($before) === $this->markupSignature($operation['html']);
			},
			$capabilities,
		);
		if (!$validation->isSuccess())
		{
			return $validation;
		}
		if ($capabilities->verifyBaseVersion
			&& !hash_equals(substr(sha1($baselineHtml), 0, 12), $changeSet['baseVersion']))
		{
			return $this->error('stale-version');
		}

		$operations = $validation->getData()['operations'];
		if ($operations !== [] && in_array($file['charset'], ['utf-16le', 'utf-16be'], true))
		{
			return $this->error('write-rejected');
		}
		if ($file['multibyte'] && !HtmlCharset::canEncodePayload($operations, $file['charset']))
		{
			return $this->error('write-rejected');
		}
		$html = $file['text'];
		$elements = $this->parseElements($html, array_map(
			static fn(array $operation): int => (int)$operation['id'],
			$operations,
		), true);
		$submittedCount = count($operations);
		$operations = array_merge($operations, $this->lazyCompanions($elements, $operations));
		$edits = [];
		$skipped = [];
		$innerIds = [];
		foreach ($operations as $operation)
		{
			$element = $elements[(int)$operation['id']] ?? null;
			if ($operation['type'] === 'setInnerHTML' && $element !== null
				&& !in_array($element['tag'], self::VOID_TAGS, true) && !$element['selfClosing'])
			{
				$innerIds[(int)$operation['id']] = true;
			}
		}
		foreach ($operations as $index => $operation)
		{
			$element = $elements[(int)$operation['id']] ?? null;
			if ($element === null)
			{
				if ($index < $submittedCount)
				{
					$skipped[] = ['op' => $operation, 'reason' => 'unknown-id'];
				}
				continue;
			}
			if ($element['inTemplate'])
			{
				if ($index < $submittedCount)
				{
					$skipped[] = ['op' => $operation, 'reason' => 'in-template'];
				}
				continue;
			}
			$parentId = $element['parentId'];
			while ($parentId !== null)
			{
				if (isset($innerIds[$parentId]))
				{
					if ($index < $submittedCount)
					{
						$skipped[] = ['op' => $operation, 'reason' => 'ancestor-setInnerHTML'];
					}
					continue 2;
				}
				$parentId = $elements[$parentId]['parentId'] ?? null;
			}
			if (
				($operation['type'] === 'setInnerHTML' && in_array($element['tag'], self::RAW_TEXT_TAGS, true))
				|| ($operation['type'] === 'setAttr' && in_array($element['tag'], self::LOADING_TAGS, true))
			)
			{
				return $this->error('write-rejected');
			}
			$target = $operation['type'] === 'setInnerHTML'
				? 'content:' . (int)$operation['id']
				: 'attr:' . (int)$operation['id'] . ':' . mb_strtolower($operation['name'])
			;
			if ($operation['type'] === 'setInnerHTML')
			{
				$innerEdit = $this->innerHtmlEdit($element, $operation['html']);
				$operationEdits = $innerEdit === null ? [] : [$innerEdit];
			}
			else
			{
				$operationEdits = $this->attributeEdits($element, $operation['name'], $operation['value']);
			}
			if ($operationEdits === [])
			{
				if ($index < $submittedCount)
				{
					$skipped[] = ['op' => $operation, 'reason' => $operation['type'] === 'setAttr'
						? 'attribute-not-found' : 'void-element'];
				}
				continue;
			}
			foreach ($operationEdits as $edit)
			{
				$edit['target'] = $target;
				$edit['index'] = $index;
				$edit['op'] = $operation;
				$edits[] = $edit;
			}
		}

		usort($edits, static fn(array $left, array $right): int =>
			($right['start'] <=> $left['start']) ?: (($right['end'] <=> $left['end'])
				?: ($left['index'] <=> $right['index'])));
		$boundary = strlen($html);
		$accepted = [];
		$targets = [];
		$applied = [];
		foreach ($edits as $edit)
		{
			if ($edit['end'] > $boundary || (isset($targets[$edit['target']])
				&& $targets[$edit['target']] !== $edit['index']))
			{
				if ($edit['index'] < $submittedCount)
				{
					$skipped[] = ['op' => $edit['op'], 'reason' => 'overlapping-edit'];
				}
				continue;
			}
			$accepted[] = $edit;
			$targets[$edit['target']] = $edit['index'];
			$applied[$edit['index']] = true;
			$boundary = $edit['start'];
		}

		$fragments = [];
		$offset = 0;
		foreach (array_reverse($accepted) as $edit)
		{
			$fragments[] = substr($html, $offset, $edit['start'] - $offset);
			$fragments[] = $edit['text'];
			$offset = $edit['end'];
		}
		$fragments[] = substr($html, $offset);
		$html = implode('', $fragments);
		$encoded = $html === $file['text']
			? $baselineHtml
			: HtmlCharset::encode($html, $file['charset'], $file['multibyte']);
		if ($encoded === null)
		{
			return $this->error('write-rejected');
		}

		return (new Result())->setData([
			'html' => $encoded,
			'version' => substr(sha1($encoded), 0, 12),
			'applied' => count($applied),
			'skipped' => $skipped,
		]);
	}

	private function parseElements(string $html, array $targetIds, bool $includeAll = false): array
	{
		if ($targetIds === [])
		{
			return [];
		}
		$targets = array_fill_keys($targetIds, true);
		$elements = [];
		$stack = [];
		$openTagPositions = [];
		$targetStackIds = [];
		$stackIds = [];
		$id = 0;
		$nextOffset = 0;
		$htmlBytes = strlen($html);
		while (preg_match(
			'/<!--[\s\S]*?-->|<![^>]*>|<\/?[a-z][^\x09\x0a\x0c\x0d \/>]*/i',
			$html,
			$match,
			PREG_OFFSET_CAPTURE,
			$nextOffset,
		))
		{
			[$raw, $offset] = $match[0];
			$nextOffset = $offset + strlen($raw);
			if (str_starts_with($raw, '<!'))
			{
				continue;
			}
			$tagEnd = $this->findTagEnd($html, $nextOffset);
			if ($tagEnd === null)
			{
				return [];
			}
			$raw = substr($html, $offset, $tagEnd - $offset);
			$nextOffset = $tagEnd;
			if (preg_match('/^<\/([^\x09\x0a\x0c\x0d \/>]+)/', $raw, $closing))
			{
				$tag = mb_strtolower($closing[1]);
				if (!empty($openTagPositions[$tag]))
				{
					$index = $openTagPositions[$tag][array_key_last($openTagPositions[$tag])];
					if ($tag === 'li' && $this->hasScopeBoundary($stack, $index, self::LIST_SCOPE_BOUNDARIES))
					{
						continue;
					}
					while (count($stack) > $index)
					{
						$position = count($stack) - 1;
						if (isset($targetStackIds[$position]))
						{
							$elements[$targetStackIds[$position]]['closeStart'] = $offset;
							$elements[$targetStackIds[$position]]['explicitClose'] = $position === $index;
							unset($targetStackIds[$position]);
						}
						$closedTag = array_pop($stack);
						array_pop($stackIds);
						array_pop($openTagPositions[$closedTag]);
						if ($openTagPositions[$closedTag] === [])
						{
							unset($openTagPositions[$closedTag]);
						}
					}
				}
				continue;
			}
			if (!preg_match('/^<([^\x09\x0a\x0c\x0d \/>]+)/', $raw, $opening))
			{
				continue;
			}

			$id++;
			$tag = mb_strtolower($opening[1]);
			$implicitlyClosedTags = self::IMPLICIT_CLOSE_TARGETS[$tag] ?? [];
			if (in_array($tag, self::P_CLOSING_TAGS, true))
			{
				$implicitlyClosedTags[] = 'p';
			}
			if (in_array($tag, self::HEADING_TAGS, true))
			{
				$implicitlyClosedTags = array_merge($implicitlyClosedTags, self::HEADING_TAGS);
			}
			$impliedPosition = null;
			foreach ($implicitlyClosedTags as $closedTag)
			{
				if (empty($openTagPositions[$closedTag]))
				{
					continue;
				}
				$position = $openTagPositions[$closedTag][array_key_last($openTagPositions[$closedTag])];
				if ($closedTag === 'li' && $this->hasScopeBoundary($stack, $position, self::ITEM_SCOPE_BOUNDARIES))
				{
					continue;
				}
				$impliedPosition = $impliedPosition === null ? $position : min($impliedPosition, $position);
			}
			if ($impliedPosition !== null)
			{
				while (count($stack) > $impliedPosition)
				{
					$position = count($stack) - 1;
					if (isset($targetStackIds[$position]))
					{
						$elements[$targetStackIds[$position]]['closeStart'] = $offset;
						unset($targetStackIds[$position]);
					}
					$closedTag = array_pop($stack);
					array_pop($stackIds);
					array_pop($openTagPositions[$closedTag]);
					if ($openTagPositions[$closedTag] === [])
					{
						unset($openTagPositions[$closedTag]);
					}
				}
			}
			$openEnd = $offset + strlen($raw);
			$void = str_ends_with(rtrim($raw), '/>') || in_array($tag, self::VOID_TAGS, true);
			if ($includeAll || $tag === 'meta' || isset($targets[$id]))
			{
				$elements[$id] = [
					'id' => $id,
					'tag' => $tag,
					'inTemplate' => in_array('template', $stack, true),
					'selfClosing' => str_ends_with(rtrim($raw), '/>'),
					'parentId' => $stackIds === [] ? null : $stackIds[array_key_last($stackIds)],
					'openStart' => $offset,
					'openEnd' => $openEnd,
					'closeStart' => $void ? $openEnd : $htmlBytes,
					'explicitClose' => false,
					'ambiguousClose' => false,
					'raw' => $raw,
				];
			}
			if (in_array($tag, self::RAW_TEXT_TAGS, true))
			{
				$closeStart = $htmlBytes;
				$nextOffset = $htmlBytes;
				if ($tag === 'script')
				{
					$closeStart = $this->findScriptCloseStart($html, $openEnd);
					if ($closeStart === null)
					{
						return [];
					}
				}
				elseif (preg_match(
					'~</' . $tag . '(?=[\x09\x0a\x0c\x0d \/>]|$)~i',
					$html,
					$closing,
					PREG_OFFSET_CAPTURE,
					$openEnd,
				))
				{
					$closeStart = $closing[0][1];
				}
				if ($closeStart < $htmlBytes)
				{
					$closeEnd = strpos($html, '>', $closeStart);
					$nextOffset = $closeEnd === false ? $htmlBytes : $closeEnd + 1;
				}
				if ($includeAll || isset($targets[$id]))
				{
					$elements[$id]['closeStart'] = $closeStart;
				}

				continue;
			}
			if (!$void)
			{
				$position = count($stack);
				$openTagPositions[$tag][] = $position;
				if ($includeAll || isset($targets[$id]))
				{
					$targetStackIds[$position] = $id;
				}
				$stack[] = $tag;
				$stackIds[] = $id;
			}
		}

		return $elements;
	}

	private function hasScopeBoundary(array $stack, int $position, array $boundaries): bool
	{
		foreach (array_slice($stack, $position + 1) as $tag)
		{
			if (in_array($tag, $boundaries, true))
			{
				return true;
			}
		}

		return false;
	}

	private function findScriptCloseStart(string $html, int $offset): ?int
	{
		$escaped = false;
		$doubleEscaped = false;
		while (preg_match(
			'~<!--|-->|</?script(?=[\x09\x0a\x0c\x0d \/>]|$)~i',
			$html,
			$match,
			PREG_OFFSET_CAPTURE,
			$offset,
		))
		{
			[$token, $start] = $match[0];
			$offset = $start + strlen($token);
			if (str_starts_with($token, '</'))
			{
				// The SDK ends raw text here, but HTML keeps a double-escaped end tag inside the script.
				return $doubleEscaped ? null : $start;
			}
			if ($token === '-->')
			{
				$escaped = false;
				$doubleEscaped = false;
			}
			elseif ($token === '<!--')
			{
				$escaped = true;
				$offset = $start + 2;
			}
			elseif ($escaped)
			{
				$doubleEscaped = true;
			}
		}

		return strlen($html);
	}

	private function findTagEnd(string $html, int $offset): ?int
	{
		$length = strlen($html);
		while ($offset < $length)
		{
			$offset += strspn($html, "\x09\x0a\x0c\x0d ", $offset);
			$character = $html[$offset] ?? '';
			if ($character === '>')
			{
				return $offset + 1;
			}
			if ($character === '/' || $character === '=')
			{
				$offset++;

				continue;
			}
			$offset += strcspn($html, "\x09\x0a\x0c\x0d =>/", $offset);
			$offset += strspn($html, "\x09\x0a\x0c\x0d ", $offset);
			if (($html[$offset] ?? '') !== '=')
			{
				continue;
			}
			$offset++;
			$offset += strspn($html, "\x09\x0a\x0c\x0d ", $offset);
			$quote = $html[$offset] ?? '';
			if ($quote === '"' || $quote === "'")
			{
				$quoteEnd = strpos($html, $quote, $offset + 1);
				if ($quoteEnd === false)
				{
					return null;
				}
				$offset = $quoteEnd + 1;
			}
			else
			{
				$offset += strcspn($html, "\x09\x0a\x0c\x0d >", $offset);
			}
		}

		return null;
	}

	private function innerHtmlEdit(array $element, string $html): ?array
	{
		if (
			in_array($element['tag'], self::VOID_TAGS, true)
			|| $element['selfClosing']
			|| $element['closeStart'] < $element['openEnd']
		)
		{
			return null;
		}

		return ['start' => $element['openEnd'], 'end' => $element['closeStart'], 'text' => $html];
	}

	private function markupSignature(string $html): array
	{
		$parts = [];
		$offset = 0;
		$length = strlen($html);
		while ($offset < $length && preg_match('/<(?=[!?%\/a-z])/i', $html, $match, PREG_OFFSET_CAPTURE, $offset))
		{
			$start = $match[0][1];
			$parts[] = null;
			if (substr($html, $start, 4) === '<!--')
			{
				$end = strpos($html, '-->', $start + 4);
				$end = $end === false ? $length : $end + 3;
			}
			elseif (substr($html, $start, 2) === '<?' || substr($html, $start, 2) === '<%')
			{
				$endToken = $html[$start + 1] === '?' ? '?>' : '%>';
				$end = strpos($html, $endToken, $start + 2);
				$end = $end === false ? $length : $end + 2;
			}
			else
			{
				$end = $this->findTagEnd($html, $start + 1) ?? $length;
			}
			$tagText = substr($html, $start, $end - $start);
			$parts[] = $tagText;
			$offset = $end;
			if (!preg_match('/^<([a-z][\w:-]*)/i', $tagText, $tag))
			{
				continue;
			}
			$name = strtolower($tag[1]);
			if (
				in_array($name, self::VOID_TAGS, true)
				|| (str_ends_with(rtrim($tagText), '/>') && !in_array($name, self::RAW_TEXT_TAGS, true))
				|| ($name !== 'template' && !in_array($name, self::RAW_TEXT_TAGS, true)
					&& ChangeSetValidator::isAllowedMarkupTag($name))
			)
			{
				continue;
			}
			$hasClose = preg_match(
				'~</' . preg_quote($name, '~') . '(?=[\x09\x0a\x0c\x0d \/>])~i',
				$html,
				$closing,
				PREG_OFFSET_CAPTURE,
				$offset,
			);
			if ($hasClose !== 1)
			{
				$parts[] = substr($html, $offset);
				$offset = $length;
				break;
			}
			$close = $closing[0][1];
			$parts[] = substr($html, $offset, $close - $offset);
			$offset = $close;
		}
		$parts[] = null;

		return $parts;
	}

	private function attributes(string $raw): array
	{
		preg_match_all(
			'/\s+([^\s"\'<>\/=]+)(?:(\s*=\s*)(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/',
			$raw,
			$matches,
			PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
		);

		return $matches;
	}

	private function lazyCompanions(array $elements, array $operations): array
	{
		$specified = [];
		foreach ($operations as $operation)
		{
			if ($operation['type'] === 'setAttr')
			{
				$specified[$operation['id']][strtolower($operation['name'])] = true;
			}
		}
		$added = [];
		foreach ($operations as $operation)
		{
			$element = $elements[$operation['id']] ?? null;
			if (
				$operation['type'] !== 'setAttr'
				|| strtolower($operation['name']) !== 'src'
				|| !is_string($operation['value'])
				|| ($element['tag'] ?? null) !== 'img'
			)
			{
				continue;
			}
			$candidates = [$element];
			if (($elements[$element['parentId']]['tag'] ?? null) === 'picture')
			{
				foreach ($elements as $source)
				{
					if ($source['tag'] === 'source' && $source['parentId'] === $element['parentId'])
					{
						$candidates[] = $source;
					}
				}
			}
			foreach ($candidates as $candidate)
			{
				foreach ($this->attributes($candidate['raw']) as $attribute)
				{
					$name = strtolower($attribute[1][0]);
					$isSource = $candidate['tag'] === 'img'
						&& in_array($name, ['data-src', 'data-lazy-src', 'data-original', 'data-lazy', 'data-echo'], true);
					$isSourceSet = in_array($name, ['data-srcset', 'data-lazy-srcset', 'data-original-set'], true);
					if (isset($specified[$candidate['id']][$name]) || (!$isSource && !$isSourceSet))
					{
						continue;
					}
					$specified[$candidate['id']][$name] = true;
					$added[] = [
						'type' => 'setAttr',
						'id' => $candidate['id'],
						'name' => $name,
						'value' => $isSource ? $operation['value'] : null,
					];
				}
			}
		}

		return $added;
	}

	private function attributeEdits(array $element, string $name, ?string $value): array
	{
		if ($value !== null)
		{
			$edit = $this->attributeEdit($element, $name, $value);

			return $edit === null ? [] : [$edit];
		}

		$edits = [];
		foreach ($this->attributes($element['raw']) as $attribute)
		{
			if (strtolower($attribute[1][0]) !== strtolower($name))
			{
				continue;
			}
			$start = $element['openStart'] + $attribute[0][1];
			$edits[] = [
				'start' => $start,
				'end' => $start + strlen($attribute[0][0]),
				'text' => '',
			];
		}

		return $edits;
	}

	private function attributeEdit(array $element, string $name, string $value): ?array
	{
		$raw = $element['raw'];
		$attributes = $this->attributes($raw);
		foreach ($attributes as $attribute)
		{
			if (strtolower($attribute[1][0]) !== strtolower($name))
			{
				continue;
			}
			$start = $element['openStart'] + $attribute[0][1];
			$end = $start + strlen($attribute[0][0]);
			$text = '';
			if ($value !== null)
			{
				$group = 5;
				foreach ([3, 4] as $quotedGroup)
				{
					if ($attribute[$quotedGroup][0] !== null)
					{
						$group = $quotedGroup;
						break;
					}
				}
				if ($attribute[$group][0] !== null)
				{
					$start = $element['openStart'] + $attribute[$group][1];
					$end = $start + strlen($attribute[$group][0]);
					$text = $this->escapeAttribute($value, $group === 4 ? "'" : '"');
					if ($group === 5 && ($text === '' || preg_match('/[\s"\'`=<>]/', $text)))
					{
						$start = $element['openStart'] + $attribute[1][1];
						$text = $attribute[1][0] . '="' . $text . '"';
					}
				}
				else
				{
					$start = $end;
					$text = '="' . $this->escapeAttribute($value) . '"';
				}
			}

			return ['start' => $start, 'end' => $end, 'text' => $text];
		}
		if ($value === null)
		{
			return null;
		}
		$last = $attributes === [] ? null : $attributes[array_key_last($attributes)];
		$insertAt = $last === null
			? $element['openStart'] + strcspn($raw, "\x09\x0a\x0c\x0d />", 1) + 1
			: $element['openStart'] + $last[0][1] + strlen($last[0][0])
		;

		return [
			'start' => $insertAt,
			'end' => $insertAt,
			'text' => ' ' . $name . '="' . $this->escapeAttribute($value) . '"',
		];
	}

	private function escapeAttribute(string $value, string $quote = '"'): string
	{
		return str_replace(
			['&', "\r", $quote],
			['&amp;', '&#13;', $quote === "'" ? '&#39;' : '&quot;'],
			$value,
		);
	}

	private function error(string $code): Result
	{
		Loc::loadMessages(__FILE__);

		return (new Result())->addError(new Error(
			Loc::getMessage($code === 'stale-version'
				? 'VISUALEDITOR_STALE_VERSION'
				: 'VISUALEDITOR_CHANGESET_REJECTED'),
			$code,
		));
	}
}
