<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink;

use Bitrix\Disk\File;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Main\Config\Option;

class Configuration
{
	private const ALLOWED_FILE_TYPES = [
		TypeFile::FLIPCHART,
	];

	public static function supportsUnifiedLink(File $object): bool
	{
		$isEnabled = Option::get('disk', 'unified_link.enabled', 'N') === 'Y';
		if (!$isEnabled)
		{
			return false;
		}

		$fileType = (int)$object->getTypeFile();
		if (!in_array($fileType, self::ALLOWED_FILE_TYPES, true))
		{
			return false;
		}

		$isFileTypeAllowedByOption = Option::get('disk', self::getOptionNameForFileType($fileType), 'N') === 'Y';
		if (!$isFileTypeAllowedByOption)
		{
			return false;
		}

		$uniqueCode = $object->getUniqueCode();

		return !empty($uniqueCode);
	}

	public static function getDefaultAccessLevel(): UnifiedLinkAccessLevel
	{
		$accessLevel = Option::get('disk', 'unified_link.default_access_level', UnifiedLinkAccessLevel::Edit->value);

		return UnifiedLinkAccessLevel::tryFrom($accessLevel) ?? UnifiedLinkAccessLevel::Edit;
	}

	public static function setDefaultAccessLevel(UnifiedLinkAccessLevel $unifiedLinkAccessLevel): void
	{
		Option::set('disk', 'unified_link.default_access_level', $unifiedLinkAccessLevel->value);
	}

	public static function setFileTypeSupport(int $fileType, bool $isSupported = true): void
	{
		Option::set(
			'disk',
			self::getOptionNameForFileType($fileType),
			$isSupported ? 'Y' : 'N',
		);
	}

	private static function getOptionNameForFileType(int $fileType): string
	{
		return "unified_link.allow_type_{$fileType}";
	}
}