<?php

declare(strict_types=1);

namespace Bitrix\VisualEditor\Internal\Service\Editor;

final class HtmlCharset
{
	private const HEAD_BYTES = 65536;
	private const PRESCAN_BYTES = 1024;
	private const TABLES = [
		'windows-1251' => 'ЂЃ‚ѓ„…†‡€‰Љ‹ЊЌЋЏ' .
			'ђ‘’“”•–—' . "\u{0098}" . '™љ›њќћџ' .
			"\u{00A0}" . 'ЎўЈ¤Ґ¦§Ё©Є«¬' . "\u{00AD}" . '®Ї°±Ііґµ¶·ё№є»јЅѕї' .
			'АБВГДЕЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ' .
			'абвгдежзийклмнопрстуфхцчшщъыьэюя',
		'windows-1252' => '€' . "\u{0081}" . '‚ƒ„…†‡ˆ‰Š‹Œ' . "\u{008D}" . 'Ž' . "\u{008F}\u{0090}" .
			'‘’“”•–—˜™š›œ' . "\u{009D}" . 'žŸ' . "\u{00A0}" . '¡¢£¤¥¦§¨©ª«¬' . "\u{00AD}" .
			'®¯°±²³´µ¶·¸¹º»¼½¾¿ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖ×ØÙÚÛÜÝÞß' .
			'àáâãäåæçèéêëìíîïðñòóôõö÷øùúûüýþÿ',
		'koi8-r' => '─│┌┐└┘├┤┬┴┼▀▄█▌▐' .
			'░▒▓⌠■∙√≈≤≥' . "\u{00A0}" . '⌡°²·÷' .
			'═║╒ё╓╔╕╖╗╘╙╚╛╜╝╞╟╠╡Ё╢╣╤╥╦╧╨╩╪╫╬©' .
			'юабцдефгхийклмнопярстужвьызшэщчъ' .
		'ЮАБЦДЕФГХИЙКЛМНОПЯРСТУЖВЬЫЗШЭЩЧЪ',
		'iso-8859-5' => 'ЁЂЃЄЅІЇЈЉЊЋЌ' . "\u{00AD}" . 'ЎЏ' .
			'АБВГДЕЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ' .
			'абвгдежзийклмнопрстуфхцчшщъыьэюя' .
			'№ёђѓєѕіїјљњћќ§ўџ',
		'iso-8859-15' => '¡¢£€¥Š§š©ª«¬' . "\u{00AD}" . '®¯°±²³Žµ¶·ž¹º»ŒœŸ¿' .
			'ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖ×ØÙÚÛÜÝÞß' .
			'àáâãäåæçèéêëìíîïðñòóôõö÷øùúûüýþÿ',
	];
	private const ALIASES = [
		'utf8' => 'utf-8', 'unicode-1-1-utf-8' => 'utf-8',
		'cp1251' => 'windows-1251', 'x-cp1251' => 'windows-1251',
		'cp1252' => 'windows-1252', 'x-cp1252' => 'windows-1252',
		'ansi_x3.4-1968' => 'windows-1252', 'us-ascii' => 'windows-1252', 'ascii' => 'windows-1252',
		'iso_8859-1' => 'iso-8859-1', 'iso-ir-100' => 'iso-8859-1', 'latin1' => 'iso-8859-1',
		'l1' => 'iso-8859-1', 'cp819' => 'iso-8859-1',
		'iso_8859-5' => 'iso-8859-5', 'cyrillic' => 'iso-8859-5',
		'iso_8859-15' => 'iso-8859-15', 'l9' => 'iso-8859-15',
		'shift-jis' => 'shift_jis', 'sjis' => 'shift_jis', 'ms_kanji' => 'shift_jis',
		'windows-31j' => 'shift_jis', 'gbk' => 'gb2312', 'gb18030' => 'gb2312',
	];
	public static function decode(string $bytes): array
	{
		$charset = self::detect($bytes);
		if ($charset === 'utf-16le' || $charset === 'utf-16be')
		{
			return [
				'text' => self::decodeUtf16($bytes, $charset === 'utf-16le'),
				'charset' => $charset,
				'multibyte' => false,
			];
		}
		if ($charset === 'utf-8')
		{
			return ['text' => $bytes, 'charset' => $charset, 'multibyte' => false];
		}
		$known = $charset === 'iso-8859-1' || isset(self::TABLES[$charset]);

		return [
			'text' => self::decodeSingleByte($bytes, $known ? $charset : null),
			'charset' => $charset,
			'multibyte' => !$known,
		];
	}

	public static function encode(string $text, string $charset, bool $identityFallback = false): ?string
	{
		if (!mb_check_encoding($text, 'UTF-8'))
		{
			return null;
		}
		$charset = self::canonical($charset);
		if ($charset === 'utf-8')
		{
			return $text;
		}
		if ($charset === 'utf-16le' || $charset === 'utf-16be')
		{
			return null;
		}
		if ($identityFallback)
		{
			return self::encodeSingleByte($text, null);
		}
		if ($charset === 'iso-8859-1' || isset(self::TABLES[$charset]))
		{
			return self::encodeSingleByte($text, $charset);
		}

		return preg_match('/[^\x00-\x7F]/', $text) === 1 ? null : $text;
	}

	public static function isAscii(string $text): bool
	{
		return preg_match('/[^\x00-\x7F]/', $text) === 0;
	}

	public static function canEncodePayload(array $operations, string $charset): bool
	{
		foreach ($operations as $operation)
		{
			if (!is_array($operation))
			{
				continue;
			}
			foreach (['html', 'name', 'value'] as $field)
			{
				if (isset($operation[$field]) && is_string($operation[$field]))
				{
					if (self::encode($operation[$field], $charset) === null)
					{
						return false;
					}
				}
			}
		}

		return true;
	}

	private static function canonical(string $charset): string
	{
		$label = strtolower(trim($charset));

		return self::ALIASES[$label] ?? $label;
	}

	private static function detect(string $bytes): string
	{
		if (str_starts_with($bytes, "\xFF\xFE"))
		{
			return 'utf-16le';
		}
		if (str_starts_with($bytes, "\xFE\xFF"))
		{
			return 'utf-16be';
		}
		$validUtf8 = mb_check_encoding($bytes, 'UTF-8');
		if (str_starts_with($bytes, "\xEF\xBB\xBF") && $validUtf8)
		{
			return 'utf-8';
		}
		$declared = self::metaCharset($bytes);
		if ($declared !== null && $declared !== 'utf-8')
		{
			return $declared;
		}
		if ($validUtf8)
		{
			return 'utf-8';
		}

		return self::guessSingleByteCharset($bytes);
	}

	private static function guessSingleByteCharset(string $bytes): string
	{
		$high = 0;
		$cyrillic = 0;
		$upperHalf = 0;
		$length = min(strlen($bytes), self::HEAD_BYTES);
		for ($i = 0; $i < $length; $i++)
		{
			$byte = ord($bytes[$i]);
			if ($byte < 0x80)
			{
				continue;
			}
			$high++;
			if ($byte >= 0xC0 || in_array($byte, [0xA8, 0xB8, 0xA3, 0xB3], true))
			{
				$cyrillic++;
			}
			if ($byte >= 0xE0)
			{
				$upperHalf++;
			}
		}
		if ($high === 0 || $cyrillic / $high < 0.9)
		{
			return 'windows-1252';
		}

		return $upperHalf >= $cyrillic / 2 ? 'windows-1251' : 'koi8-r';
	}

	private static function decodeSingleByte(string $bytes, ?string $charset): string
	{
		$table = self::decodeTable($charset);
		$text = '';
		$length = strlen($bytes);
		for ($i = 0; $i < $length; $i++)
		{
			$text .= $table[ord($bytes[$i])];
		}

		return $text;
	}

	private static function encodeSingleByte(string $text, ?string $charset): ?string
	{
		$table = array_flip(self::decodeTable($charset));
		$chars = mb_str_split($text, 1, 'UTF-8');
		$bytes = '';
		foreach ($chars as $char)
		{
			if (!isset($table[$char]))
			{
				return null;
			}
			$bytes .= chr($table[$char]);
		}

		return $bytes;
	}

	private static function decodeTable(?string $charset): array
	{
		static $tables = [];
		$key = $charset ?? 'identity';
		if (isset($tables[$key]))
		{
			return $tables[$key];
		}
		$table = [];
		for ($byte = 0; $byte < 128; $byte++)
		{
			$table[$byte] = chr($byte);
		}
		if ($charset === null)
		{
			for ($byte = 128; $byte < 256; $byte++)
			{
				$table[$byte] = mb_chr($byte, 'UTF-8');
			}
		}
		else
		{
			$high = self::highTable($charset);
			foreach (mb_str_split($high, 1, 'UTF-8') as $index => $char)
			{
				$table[$index + 128] = $char;
			}
		}
		return $tables[$key] = $table;
	}

	private static function highTable(string $charset): string
	{
		if ($charset === 'iso-8859-1')
		{
			return self::TABLES['windows-1252'];
		}
		$controls = '';
		if ($charset === 'iso-8859-5' || $charset === 'iso-8859-15')
		{
			for ($byte = 128; $byte < 160; $byte++)
			{
				$controls .= mb_chr($byte, 'UTF-8');
			}
		}
		if ($charset === 'iso-8859-5' || $charset === 'iso-8859-15')
		{
			$controls .= "\u{00A0}";
		}

		return $controls . self::TABLES[$charset];
	}

	private static function decodeUtf16(string $bytes, bool $little): string
	{
		$text = '';
		$length = strlen($bytes);
		$lead = null;
		for ($i = 0; $i + 1 < $length; $i += 2)
		{
			$unit = $little
				? ord($bytes[$i]) | (ord($bytes[$i + 1]) << 8)
				: (ord($bytes[$i]) << 8) | ord($bytes[$i + 1])
			;
			if ($lead !== null)
			{
				if ($unit >= 0xDC00 && $unit <= 0xDFFF)
				{
					$text .= mb_chr(0x10000 + (($lead - 0xD800) << 10) + $unit - 0xDC00, 'UTF-8');
					$lead = null;
					continue;
				}
				$text .= "\u{FFFD}";
				$lead = null;
			}
			if ($unit >= 0xD800 && $unit <= 0xDBFF)
			{
				$lead = $unit;
			}
			else
			{
				$text .= mb_chr($unit >= 0xDC00 && $unit <= 0xDFFF ? 0xFFFD : $unit, 'UTF-8');
			}
		}
		if ($lead !== null || $length % 2 !== 0)
		{
			$text .= "\u{FFFD}";
		}

		return $text;
	}

	private static function metaCharset(string $bytes): ?string
	{
		$head = substr($bytes, 0, self::HEAD_BYTES);
		$length = strlen($head);
		for ($i = 0; $i < min($length, self::PRESCAN_BYTES); $i++)
		{
			if ($head[$i] !== '<')
			{
				continue;
			}
			if (substr($head, $i, 4) === '<!--')
			{
				$end = strpos($head, '-->', $i + 4);
				$i = $end === false ? $length : $end + 2;
				continue;
			}
			if (preg_match('/\G<([a-z][a-z0-9:-]*)(?=[\x09\x0A\x0C\x0D\x20\/>])/i', $head, $match, 0, $i) !== 1)
			{
				continue;
			}
			$tagName = strtolower($match[1]);
			$end = self::tagEnd($head, $i + strlen($match[0]));
			if ($end === null)
			{
				break;
			}
			if ($tagName === 'meta')
			{
				$tag = substr($head, $i + 5, $end - $i - 5);
				$declared = self::charsetInMeta($tag);
				if ($declared !== null)
				{
					return $declared;
				}
			}
			elseif (in_array($tagName, [
			'script', 'style', 'title', 'textarea', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript',
			], true))
			{
				$close = stripos($head, '</' . $tagName, $end + 1);
				if ($close === false)
				{
					break;
				}
				$i = $close - 1;
				continue;
			}
			$i = $end;
		}

		return null;
	}

	private static function tagEnd(string $text, int $start): ?int
	{
		$quote = null;
		$length = strlen($text);
		for ($i = $start; $i < $length; $i++)
		{
			if ($quote !== null)
			{
				if ($text[$i] === $quote)
				{
					$quote = null;
				}
			}
			elseif ($text[$i] === '"' || $text[$i] === "'")
			{
				$quote = $text[$i];
			}
			elseif ($text[$i] === '>')
			{
				return $i;
			}
		}

		return null;
	}

	private static function charsetInMeta(string $tag): ?string
	{
		preg_match_all(
			'/([^\s=\/>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]*)))?/',
			$tag,
			$matches,
			PREG_SET_ORDER,
		);
		$attributes = [];
		foreach ($matches as $match)
		{
			$name = strtolower($match[1]);
			$value = strtolower($match[2] ?: ($match[3] ?: ($match[4] ?? '')));
			if (!array_key_exists($name, $attributes) && ($name !== 'charset' || trim($value) !== ''))
			{
				$attributes[$name] = $value;
			}
		}
		$value = $attributes['charset'] ?? null;
		if ($value === null && ($attributes['http-equiv'] ?? null) === 'content-type')
		{
			if (preg_match('/charset\s*=\s*(?:["\']([^"\']+)["\']|([^;\s]+))/i', $attributes['content'] ?? '', $found) === 1)
			{
				$value = $found[1] ?: ($found[2] ?? null);
			}
		}
		if ($value === null || preg_match('/^\s*([\w.:-]+)/', $value, $found) !== 1)
		{
			return null;
		}
		$charset = self::canonical($found[1]);

		return in_array($charset, ['latin9', 'koi8r'], true) ? null : $charset;
	}
}
