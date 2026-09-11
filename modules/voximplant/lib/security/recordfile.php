<?php

namespace Bitrix\Voximplant\Security;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

class RecordFile
{
	private static $availableMimeType = 'audio/';
	private static $availableExtensionTypes = [
		'mp3',
		'flac',
		'wav',
	];
	private static $extensionByMimeType = [
		'audio/mpeg' => 'mp3',
		'audio/mp3' => 'mp3',
		'audio/flac' => 'flac',
		'audio/x-flac' => 'flac',
		'audio/wav' => 'wav',
		'audio/wave' => 'wav',
		'audio/x-wav' => 'wav',
	];

	/**
	 * @return string[]
	 */
	public static function getAvailableExtensions(): array
	{
		return self::$availableExtensionTypes;
	}

	public static function resolveExtensionByType(string $type): ?string
	{
		$extension = self::$extensionByMimeType[mb_strtolower($type)] ?? null;

		return in_array($extension, self::$availableExtensionTypes, true) ? $extension : null;
	}

	/**
	 * @param array $file
	 * @see \CFile::MakeFileArray()
	 * @return Result
	 */
	public static function isCorrectFromArray(array $file): Result
	{
		$errorMessage =
			\CFile::CheckFile(
				$file,
				0,
				self::$availableMimeType,
				implode(',', self::$availableExtensionTypes),
			)
		;
		$result = new Result();

		if ($errorMessage !== '')
		{
			$result->addError(new Error($errorMessage));
		}

		return $result;

	}
}