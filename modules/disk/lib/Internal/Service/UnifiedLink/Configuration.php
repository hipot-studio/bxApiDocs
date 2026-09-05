<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink;

use Bitrix\Disk\Document\DocumentHandler;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Main\Config\Option;

class Configuration
{
	/**
	 * @deprecated use \Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkSupportService instead
	 * @param File $object
	 * @return bool
	 */
	public static function supportsUnifiedLink(File $object): bool
	{
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

	public static function isEnabled(): bool
	{
		return true;
	}

	public static function isFileTypeAllowed(int $fileType): bool
	{
		return true;
	}

	private static function getOptionNameForFileType(int $fileType): string
	{
		return "unified_link.allow_type_{$fileType}";
	}

	public static function isDocumentHandlerAllowed(DocumentHandler $documentHandler): bool
	{
		return Option::get('disk', self::getOptionNameForDocumentHandler($documentHandler), 'N') === 'Y';
	}

	private static function getOptionNameForDocumentHandler(DocumentHandler $documentHandler): string
	{
		return "unified_link.allow_document_handler_{$documentHandler::getCode()}";
	}
}