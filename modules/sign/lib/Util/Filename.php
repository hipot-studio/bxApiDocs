<?php

namespace Bitrix\Sign\Util;

use Bitrix\Main\IO\Path;

class Filename
{
	/**
	 * Names reserved by Windows for its devices. Such a name stays reserved with any extension
	 * added to it, so `CON.pdf` is as unusable as `CON`.
	 */
	private const RESERVED_BASE_NAMES = [
		'CON', 'PRN', 'AUX', 'NUL',
		'COM0', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
		'LPT0', 'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
	];

	public static function compose(string $defaultName, ?string $overrideName): string
	{
		if ($overrideName === null)
		{
			return $defaultName;
		}

		$overrideName = trim($overrideName);
		if ($overrideName === '')
		{
			return $defaultName;
		}

		$extension = Path::getExtension($defaultName);
		if ($extension === '')
		{
			return $overrideName;
		}

		if (strcasecmp(Path::getExtension($overrideName), $extension) === 0)
		{
			return $overrideName;
		}

		return $overrideName . '.' . $extension;
	}

	/**
	 * Makes the name usable as a single path component: the length is cut to what the component
	 * allows and a name reserved by the file system is moved aside. Both are answered here so that
	 * every place serving a file to the user asks one question instead of two.
	 *
	 * The cut goes first because it is the cut that can uncover a reserved base name, while the
	 * underscore it may add afterwards is one byte inside the gap the budget keeps below the limit
	 * of the component.
	 */
	public static function forFileSystem(string $fileName, int $maxBytes = 200): string
	{
		return self::avoidReservedName(self::truncate($fileName, $maxBytes));
	}

	/**
	 * Appends an underscore to a base name reserved by Windows, keeping the extension: a file named
	 * after such a device cannot be extracted from an archive there at all.
	 */
	public static function avoidReservedName(string $fileName): string
	{
		$dotPosition = mb_strpos($fileName, '.');
		$base = $dotPosition === false ? $fileName : mb_substr($fileName, 0, $dotPosition);
		if (!in_array(mb_strtoupper($base), self::RESERVED_BASE_NAMES, true))
		{
			return $fileName;
		}

		$rest = $dotPosition === false ? '' : mb_substr($fileName, $dotPosition);

		return $base . '_' . $rest;
	}

	/**
	 * Cuts the name down to a length a file system accepts as a single path component, keeping the
	 * extension. Titles of entities are long enough to overflow it on their own: 255 characters are
	 * allowed there, while the usual limit of a component is 255 BYTES, and a multibyte title runs
	 * out of them two or three times sooner. The default budget leaves room for a numeric suffix a
	 * caller may append to tell equal names apart.
	 */
	public static function truncate(string $fileName, int $maxBytes = 200): string
	{
		if ($maxBytes < 1 || strlen($fileName) <= $maxBytes)
		{
			return $fileName;
		}

		$extension = Path::getExtension($fileName);
		$suffix = $extension === '' ? '' : '.' . $extension;
		$baseBudget = $maxBytes - strlen($suffix);
		if ($baseBudget < 1)
		{
			return mb_strcut($fileName, 0, $maxBytes);
		}

		$base = $suffix === ''
			? $fileName
			: mb_substr($fileName, 0, mb_strlen($fileName) - mb_strlen($suffix))
		;
		// Windows accepts neither a trailing space nor a trailing dot in a path component
		$base = rtrim(mb_strcut($base, 0, $baseBudget), " .");

		return $base === '' ? mb_strcut($fileName, 0, $maxBytes) : $base . $suffix;
	}
}
