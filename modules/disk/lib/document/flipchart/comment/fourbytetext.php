<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Comment;

use Bitrix\Main\Text\Emoji;

/**
 * The form four-byte characters are stored in.
 *
 * The portal connection is not guaranteed to carry them, so a text that holds one is stored escaped
 * and opens with ESC to be told apart from a text stored as it is: inside, ESC escapes itself and
 * each four-byte character stands as its eight hexadecimal digits. A text that needs no escaping is
 * stored untouched, so what was stored before is read back unchanged.
 *
 * `Emoji::encode()` of the kernel is deliberately not used here: its `:f09f9880:` form cannot be
 * told from the same text typed by hand, and reading it back turned literal text into emoji. Nothing
 * is normalized either, so a ZWJ sequence keeps the exact code points it came with and the same
 * bytes answer a write, a filter and a unique key alike.
 */
final class FourByteText
{
	private const ESCAPE = "\x1B";
	private const ESCAPED_SEQUENCE = '/\x1B(\x1B|[0-9a-f]{8})/';

	public static function encode(string $value): string
	{
		$escaped = Emoji::replace(
			str_replace(self::ESCAPE, self::ESCAPE . self::ESCAPE, $value),
			static fn (array $match): string => self::ESCAPE . bin2hex($match[0]),
		);

		return $escaped === $value ? $value : self::ESCAPE . $escaped;
	}

	public static function decode(string $value): string
	{
		if (!str_starts_with($value, self::ESCAPE))
		{
			return $value;
		}

		return (string)preg_replace_callback(
			self::ESCAPED_SEQUENCE,
			static fn (array $match): string => $match[1] === self::ESCAPE ? self::ESCAPE : hex2bin($match[1]),
			substr($value, 1),
		);
	}
}
