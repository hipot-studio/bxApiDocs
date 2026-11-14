<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Document;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\DocumentHandler;
use Bitrix\Disk\Document\GoogleViewerHandler;
use Bitrix\Disk\Document\DocumentResolveContext;
use Bitrix\Disk\Document\Vibeoffice\OpenRoutePolicy;
use Bitrix\Disk\Document\Vibeoffice\VibeofficeHandler;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Version;
use Bitrix\Main\Web\MimeType;

class DocumentViewPolicy
{
	/** @var array<string, bool> */
	private static array $allowedUseCloudsContentTypes = [];

	public static function isAllowedUseClouds(string $contentType): bool
	{
		if (!isset(self::$allowedUseCloudsContentTypes[$contentType]))
		{
			if (!Configuration::canCreateFileByCloud())
			{
				self::$allowedUseCloudsContentTypes[$contentType] = false;

				return false;
			}

			$handler = self::getDefaultHandlerForView();

			if ($handler instanceof GoogleViewerHandler && !Configuration::isEnabledAutoExternalLink())
			{
				self::$allowedUseCloudsContentTypes[$contentType] = false;

				return false;
			}

			self::$allowedUseCloudsContentTypes[$contentType] = in_array($contentType, self::getInputContentTypes(), true);
		}

		return self::$allowedUseCloudsContentTypes[$contentType];
	}

	/**
	 * Forgets the answers remembered by {@see self::isAllowedUseClouds()}, so that the next call judges
	 * the content type anew. Needed by whoever changes the viewer or its options within a running process.
	 */
	public static function resetAllowedUseClouds(): void
	{
		self::$allowedUseCloudsContentTypes = [];
	}

	public static function isAllowedUseVibeofficeSource(File $file, ?Version $version, string $contentType): bool
	{
		if (self::isAllowedUseClouds($contentType))
		{
			return self::getEffectiveHandlerForView($file) !== null;
		}

		if (
			$contentType === 'text/plain'
			|| !Configuration::canCreateFileByCloud()
			|| !OpenRoutePolicy::isAdditionalOfficeSource($file, $version)
		)
		{
			return false;
		}

		return self::isAdditionalVibeofficeSource($file, $version);
	}

	public static function isAdditionalVibeofficeSource(File $file, ?Version $version = null): bool
	{
		if (!OpenRoutePolicy::isAdditionalOfficeSource($file, $version))
		{
			return false;
		}

		$handler = self::getEffectiveHandlerForView($file);

		return $handler instanceof VibeofficeHandler;
	}

	public static function getEffectiveHandlerForView(File $file, ?int $attachedObjectId = null): ?DocumentHandler
	{
		return self::resolveEffectiveHandlerForView(DocumentResolveContext::forLoadedFile($file, $attachedObjectId));
	}

	public static function resolveEffectiveHandlerForView(DocumentResolveContext $context): ?DocumentHandler
	{
		$defaultHandler = self::getDefaultHandlerForView();
		// The portal viewer can stand for a missing OnlyOffice; file routing still needs the saved entry code.
		$serviceCode = Configuration::getDefaultViewerServiceCode();
		if (!OfficeEngineRecognizer::isEntryCode($serviceCode))
		{
			$serviceCode = $defaultHandler::getCode();
		}

		return Driver::getInstance()->getDocumentHandlersManager()->resolveEffectiveHandler(
			$serviceCode,
			$context,
		);
	}

	private static function getInputContentTypes(): array
	{
		$types = [
			MimeType::getByFileExtension('pdf'),
			'application/rtf',
			'application/vnd.ms-powerpoint',
		];

		$handler = self::getDefaultHandlerForView();
		$extensions = $handler::listMimeRoutableExtensions();

		foreach ($extensions as $extension)
		{
			$type = MimeType::getByFileExtension($extension);
			if ($type === 'application/octet-stream')
			{
				continue;
			}
			$types[] = $type;
		}

		return $types;
	}

	public static function getDefaultHandlerForView(): DocumentHandler
	{
		return Driver::getInstance()
			->getDocumentHandlersManager()
			->getDefaultHandlerForView()
		;
	}
}
