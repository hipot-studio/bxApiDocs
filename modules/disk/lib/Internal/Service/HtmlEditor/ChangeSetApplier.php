<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\HtmlEditor;

use Bitrix\Disk\Configuration;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class ChangeSetApplier
{
	public const MAX_INLINE_BYTES = 10_485_760;
	public const MAX_OPERATIONS = 500;
	private const VOID_TAGS = [
		'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr',
	];
	private const RAW_TEXT_TAGS = [
		'script', 'style', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript', 'textarea', 'title',
	];
	private const LOADING_TAGS = [
		'script', 'link', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'base', 'meta', 'param',
	];

	public function __construct(private readonly ?ChangeSetValidator $validator = null)
	{
	}

	public function apply(string $baselineHtml, array $changeSet): Result
	{
		$validation = ($this->validator ?? new ChangeSetValidator())->validate($changeSet);
		if (!$validation->isSuccess())
		{
			return $validation;
		}
		$maxPageBytes = Configuration::getMaxSizeForHtmlViewer();
		if (strlen($baselineHtml) > $maxPageBytes)
		{
			return $this->error('content_too_large');
		}
		if (!hash_equals(substr(sha1($baselineHtml), 0, 12), $changeSet['baseVersion']))
		{
			return $this->error('content_version_conflict');
		}

		$operations = $validation->getData()['operations'];
		$elements = $this->parseElements($baselineHtml, array_column($operations, 'id'));
		$edits = [];
		$targets = [];
		foreach ($operations as $operation)
		{
			$element = $elements[$operation['id']] ?? null;
			if ($element === null)
			{
				return $this->error('invalid_operations');
			}
			if (
				($operation['type'] === 'setInnerHTML' && in_array($element['tag'], self::RAW_TEXT_TAGS, true))
				|| ($operation['type'] === 'setAttr' && in_array($element['tag'], self::LOADING_TAGS, true))
			)
			{
				return $this->error('invalid_operations');
			}
			$target = $operation['type'] === 'setInnerHTML'
				? 'content:' . $operation['id']
				: 'attr:' . $operation['id'] . ':' . mb_strtolower($operation['name'])
			;
			if (isset($targets[$target]))
			{
				return $this->error('invalid_operations');
			}
			$targets[$target] = true;

			$edit = $operation['type'] === 'setInnerHTML'
				? $this->innerHtmlEdit($element, $operation['html'])
				: $this->attributeEdit($element, $operation['name'], $operation['value'])
			;
			if ($edit === null)
			{
				return $this->error('invalid_operations');
			}
			$edits[] = $edit;
		}

		usort($edits, static fn(array $left, array $right): int => $right['start'] <=> $left['start']);
		$boundary = strlen($baselineHtml);
		$resultBytes = $boundary;
		foreach ($edits as $edit)
		{
			if ($edit['end'] > $boundary)
			{
				return $this->error('invalid_operations');
			}
			$boundary = $edit['start'];
			$resultBytes += strlen($edit['text']) - ($edit['end'] - $edit['start']);
		}
		if ($resultBytes > $maxPageBytes)
		{
			return $this->error('content_too_large');
		}

		$fragments = [];
		$offset = 0;
		foreach (array_reverse($edits) as $edit)
		{
			$fragments[] = substr($baselineHtml, $offset, $edit['start'] - $offset);
			$fragments[] = $edit['text'];
			$offset = $edit['end'];
		}
		$fragments[] = substr($baselineHtml, $offset);
		$html = implode('', $fragments);

		return (new Result())->setData([
			'html' => $html,
			'version' => substr(sha1($html), 0, 12),
		]);
	}

	private function parseElements(string $html, array $targetIds): array
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
					if (isset($targetStackIds[$index]))
					{
						$elements[$targetStackIds[$index]]['closeStart'] = $offset;
					}
					while (count($stack) > $index)
					{
						unset($targetStackIds[count($stack) - 1]);
						$closedTag = array_pop($stack);
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
			$openEnd = $offset + strlen($raw);
			$void = str_ends_with(rtrim($raw), '/>') || in_array($tag, self::VOID_TAGS, true);
			if (isset($targets[$id]))
			{
				$elements[$id] = [
					'id' => $id,
					'tag' => $tag,
					'openStart' => $offset,
					'openEnd' => $openEnd,
					'closeStart' => $void ? $openEnd : $htmlBytes,
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
				if (isset($targets[$id]))
				{
					$elements[$id]['closeStart'] = $closeStart;
				}

				continue;
			}
			if (!$void)
			{
				$position = count($stack);
				$openTagPositions[$tag][] = $position;
				if (isset($targets[$id]))
				{
					$targetStackIds[$position] = $id;
				}
				$stack[] = $tag;
			}
		}

		return $elements;
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
		if (in_array($element['tag'], self::VOID_TAGS, true) || $element['closeStart'] < $element['openEnd'])
		{
			return null;
		}

		return ['start' => $element['openEnd'], 'end' => $element['closeStart'], 'text' => $html];
	}

	private function attributeEdit(array $element, string $name, ?string $value): ?array
	{
		$raw = $element['raw'];
		preg_match_all(
			'/\s+([^\s"\'<>\/=]+)(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?/',
			$raw,
			$matches,
			PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
		);
		foreach ($matches as $attribute)
		{
			if (mb_strtolower($attribute[1][0]) !== mb_strtolower($name))
			{
				continue;
			}
			$start = $element['openStart'] + $attribute[0][1];
			$end = $start + strlen($attribute[0][0]);
			$text = $value === null ? '' : ' ' . $attribute[1][0] . '="' . $this->escapeAttribute($value) . '"';

			return ['start' => $start, 'end' => $end, 'text' => $text];
		}
		if ($value === null)
		{
			return null;
		}
		$insertAt = $element['openEnd'] - (str_ends_with(rtrim($raw), '/>') ? 2 : 1);

		return [
			'start' => $insertAt,
			'end' => $insertAt,
			'text' => ' ' . $name . '="' . $this->escapeAttribute($value) . '"',
		];
	}

	private function escapeAttribute(string $value): string
	{
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
	}

	private function error(string $code): Result
	{
		return (new Result())->addError(new Error($code, $code));
	}
}
