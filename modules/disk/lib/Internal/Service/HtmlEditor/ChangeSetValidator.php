<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\HtmlEditor;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class ChangeSetValidator
{
	private const OPERATION_TYPES = ['setInnerHTML', 'setAttr'];
	private const URL_ATTRIBUTES = [
		'href', 'src', 'srcset', 'poster', 'action', 'formaction', 'background', 'cite',
		'data', 'longdesc', 'usemap', 'ping', 'xlink:href', 'codebase', 'profile', 'manifest',
	];
	private const FORBIDDEN_ATTRIBUTES = ['data-ve', 'srcdoc', 'contenteditable'];
	private const FORBIDDEN_TAGS = [
		'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'base', 'meta', 'param',
	];
	private const ALLOWED_TAGS = [
		'a', 'abbr', 'address', 'b', 'bdi', 'bdo', 'big', 'blockquote', 'br', 'caption', 'center',
		'cite', 'code', 'data', 'dd', 'del', 'dfn', 'div', 'dl', 'dt', 'em', 'figcaption', 'figure',
		'font', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'img', 'ins', 'kbd', 'label', 'li',
		'mark', 'nobr', 'ol', 'p', 'picture', 'pre', 'q', 'rp', 'rt', 'ruby', 's', 'samp', 'small',
		'source', 'span', 'strike', 'strong', 'sub', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th',
		'thead', 'time', 'tr', 'tt', 'u', 'ul', 'var', 'wbr',
	];

	public function validate(array $changeSet): Result
	{
		$schemaVersion = $changeSet['schemaVersion'] ?? null;
		if (
			!in_array($schemaVersion, [1, '1'], true)
			|| !is_string($changeSet['baseVersion'] ?? null)
			|| preg_match('/^[0-9a-f]{12}$/', $changeSet['baseVersion']) !== 1
			|| !is_array($changeSet['ops'] ?? null)
			|| count($changeSet['ops']) > ChangeSetApplier::MAX_OPERATIONS
			|| array_key_exists('content', $changeSet)
			|| array_key_exists('contentBase64', $changeSet)
		)
		{
			return $this->invalid();
		}

		$operations = [];
		foreach ($changeSet['ops'] as $operation)
		{
			$result = $this->validateOperation($operation);
			if (!$result->isSuccess())
			{
				return $result;
			}
			$operations[] = $result->getData()['operation'];
		}

		return (new Result())->setData(['operations' => $operations]);
	}

	private function validateOperation(mixed $operation): Result
	{
		if (!is_array($operation) || !in_array($operation['type'] ?? null, self::OPERATION_TYPES, true))
		{
			return $this->invalid();
		}
		$id = $operation['id'] ?? null;
		if ((!is_int($id) && !(is_string($id) && preg_match('/^[0-9]+$/D', $id) === 1)) || (int)$id < 1)
		{
			return $this->invalid();
		}

		if ($operation['type'] === 'setInnerHTML')
		{
			if (array_diff(array_keys($operation), ['type', 'id', 'html']) || !is_string($operation['html'] ?? null))
			{
				return $this->invalid();
			}

			$result = $this->validateMarkup($operation['html']);
			if ($result->isSuccess())
			{
				$operation['id'] = (int)$id;
				$result->setData(['operation' => $operation]);
			}

			return $result;
		}

		if (
			array_diff(array_keys($operation), ['type', 'id', 'name', 'value'])
			|| !is_string($operation['name'] ?? null)
			|| !array_key_exists('value', $operation)
			|| (!is_string($operation['value']) && $operation['value'] !== null)
		)
		{
			return $this->invalid();
		}

		$result = $this->validateAttribute($operation['name'], $operation['value']);
		if ($result->isSuccess())
		{
			$operation['id'] = (int)$id;
			$result->setData(['operation' => $operation]);
		}

		return $result;
	}

	private function validateMarkup(string $html): Result
	{
		if (preg_match('/<\?|<%|<!--#/i', $html))
		{
			return $this->invalid();
		}
		$offset = 0;
		while (($offset = strpos($html, '<', $offset)) !== false)
		{
			if (substr($html, $offset, 4) === '<!--')
			{
				$commentEnd = strpos($html, '--', $offset + 4);
				if ($commentEnd === false || substr($html, $commentEnd, 3) !== '-->')
				{
					return $this->invalid();
				}
				$offset = $commentEnd + 3;

				continue;
			}
			if (substr($html, $offset, 2) === '</')
			{
				if (!preg_match('/\G<\/[a-z][\w:-]*[\x09\x0a\x0c\x0d ]*>/i', $html, $closing, 0, $offset))
				{
					return $this->invalid();
				}
				$offset += strlen($closing[0]);

				continue;
			}
			if (!preg_match('/\G<[a-z]/i', $html, offset: $offset))
			{
				if (substr($html, $offset, 2) === '<!')
				{
					return $this->invalid();
				}
				$offset++;

				continue;
			}
			$result = $this->validateOpeningTag($html, $offset);
			if (!$result->isSuccess())
			{
				return $result;
			}
		}

		return new Result();
	}

	private function validateOpeningTag(string $html, int &$offset): Result
	{
		if (!preg_match('/\G<([a-z][\w:-]*)(?=[\x09\x0a\x0c\x0d \/>])/i', $html, $tag, 0, $offset))
		{
			return $this->invalid();
		}
		$tagName = mb_strtolower($tag[1]);
		if (
			in_array($tagName, self::FORBIDDEN_TAGS, true)
			|| (!in_array($tagName, self::ALLOWED_TAGS, true) && !str_contains($tagName, '-'))
		)
		{
			return $this->invalid();
		}
		$offset += strlen($tag[0]);
		while ($offset < strlen($html))
		{
			preg_match('/\G[\x09\x0a\x0c\x0d ]*/', $html, $whitespace, 0, $offset);
			$offset += strlen($whitespace[0]);
			if (preg_match('/\G\/?>/', $html, $end, 0, $offset))
			{
				$offset += strlen($end[0]);

				return new Result();
			}
			if (
				$whitespace[0] === ''
				|| !preg_match('/\G([a-z_:][\w:.-]*)(?=[\x09\x0a\x0c\x0d =\/>])/i', $html, $attribute, 0, $offset)
			)
			{
				return $this->invalid();
			}
			$offset += strlen($attribute[0]);
			$value = null;
			if (preg_match('/\G[\x09\x0a\x0c\x0d ]*=[\x09\x0a\x0c\x0d ]*/', $html, $equals, 0, $offset))
			{
				$offset += strlen($equals[0]);
				if (!preg_match(
					'/\G(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'`=<>]+))/',
					$html,
					$attributeValue,
					PREG_UNMATCHED_AS_NULL,
					$offset,
				))
				{
					return $this->invalid();
				}
				$offset += strlen($attributeValue[0]);
				$value = html_entity_decode(
					$attributeValue[1] ?? $attributeValue[2] ?? $attributeValue[3],
					ENT_QUOTES | ENT_HTML5,
					'UTF-8',
				);
			}
			$result = $this->validateAttribute($attribute[1], $value);
			if (!$result->isSuccess())
			{
				return $result;
			}
		}

		return $this->invalid();
	}

	private function validateAttribute(string $name, ?string $value): Result
	{
		$name = mb_strtolower($name);
		if (
			preg_match('/^[a-z_:][\w:.-]*$/i', $name) !== 1
			|| str_starts_with($name, 'on')
			|| in_array($name, self::FORBIDDEN_ATTRIBUTES, true)
		)
		{
			return $this->invalid();
		}
		if ($value === null)
		{
			return new Result();
		}
		if (preg_match('/<\?|<%|<!--#/i', $value))
		{
			return $this->invalid();
		}
		if ($name === 'style' && preg_match('/javascript:|expression\s*\(|behavior\s*:|-moz-binding/i', $value))
		{
			return $this->invalid();
		}

		if ($name === 'srcset')
		{
			foreach (explode(',', $value) as $candidate)
			{
				$url = preg_split('/\s+/', trim($candidate))[0] ?? '';
				$result = $this->validateUrl($name, $url);
				if (!$result->isSuccess())
				{
					return $result;
				}
			}

			return new Result();
		}

		return in_array($name, self::URL_ATTRIBUTES, true) ? $this->validateUrl($name, $value) : new Result();
	}

	private function validateUrl(string $attribute, string $url): Result
	{
		$normalized = preg_replace('/[\x00-\x20\x7f]/', '', $url);
		if ($normalized === null || !str_contains($normalized, ':'))
		{
			return new Result();
		}
		$scheme = mb_strtolower((string)strstr($normalized, ':', true));
		if (preg_match('~[/?#]~', $scheme) || in_array($scheme, ['http', 'https', 'mailto', 'tel', 'sms'], true))
		{
			return new Result();
		}
		if ($scheme !== 'data' || !in_array($attribute, ['src', 'srcset'], true))
		{
			return $this->invalid();
		}

		return $this->validateInlineDataUrl($normalized);
	}

	private function validateInlineDataUrl(string $url): Result
	{
		if (!preg_match(
			'~^data:image/(png|jpe?g|gif|webp|avif|bmp);base64,([A-Za-z0-9+/]*={0,2})$~i',
			$url,
			$matches,
		))
		{
			return $this->invalid();
		}
		$decoded = base64_decode($matches[2], true);
		$mime = mb_strtolower($matches[1]);
		if ($decoded === false || base64_encode($decoded) !== $matches[2])
		{
			return $this->invalid();
		}
		if (strlen($decoded) > ChangeSetApplier::MAX_INLINE_BYTES)
		{
			return $this->tooLarge();
		}
		if (!$this->matchesImageSignature($mime, $decoded))
		{
			return $this->invalid();
		}

		return new Result();
	}

	private function matchesImageSignature(string $mime, string $bytes): bool
	{
		return match ($mime) {
			'png' => str_starts_with($bytes, "\x89PNG\r\n\x1a\n"),
			'jpg', 'jpeg' => str_starts_with($bytes, "\xff\xd8\xff"),
			'gif' => str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a'),
			'webp' => substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP',
			'avif' => substr($bytes, 4, 8) === 'ftypavif' || substr($bytes, 4, 8) === 'ftypavis',
			'bmp' => str_starts_with($bytes, 'BM'),
			default => false,
		};
	}

	private function invalid(): Result
	{
		return (new Result())->addError(new Error('invalid_operations', 'invalid_operations'));
	}

	private function tooLarge(): Result
	{
		return (new Result())->addError(new Error('content_too_large', 'content_too_large'));
	}
}
