<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Document\DocumentSource;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\Version;
use Bitrix\Main\Engine\CurrentUser;

readonly class HtmlRenderableFileHandlerFactory
{
	public function __construct(
		protected UnifiedLinkAccessService $accessService,
	)
	{
	}

	public function createHandler(
		File $file,
		?AttachedObject $attachedObject = null,
		?Version $version = null,
		array $analytics = [],
		?CurrentUser $currentUser = null,
		bool $forceExternal = false,
		bool $deferred = false,
	): HtmlRenderableFileHandler
	{
		if (!$currentUser instanceof CurrentUser || $forceExternal)
		{
			return new ExternalLinkHandler($file);
		}

		$typeFile = (int)$file->getTypeFile();
		$documentSource = $this->getDocumentSource($file, $attachedObject, $version);

		return match ($typeFile)
		{
			TypeFile::DOCUMENT, TypeFile::PDF => $this->getDocumentHandler(
				file: $file,
				documentSource: $documentSource,
				currentUser: $currentUser,
				analytics: $analytics,
				deferred: $deferred,
			),
			TypeFile::FLIPCHART => new BoardHtmlRenderableFileHandler($file, $documentSource),
			TypeFile::IMAGE,
			TypeFile::VIDEO,
			TypeFile::ARCHIVE,
			TypeFile::SCRIPT,
			TypeFile::UNKNOWN,
			TypeFile::AUDIO,
			TypeFile::KNOWN,
			TypeFile::VECTOR_IMAGE => new FolderListFileHandler($this->accessService, $file, $currentUser),
			default => new DefaultHtmlRenderableFileHandler($file),
		};
	}

	private function getDocumentHandler(
		File $file,
		DocumentSource $documentSource,
		CurrentUser $currentUser,
		array $analytics = [],
		bool $deferred = false,
	): HtmlRenderableFileHandler
	{
		$contentType = $file->getFile()['CONTENT_TYPE'] ?? '';

		if ($contentType === 'text/plain' || $file->getExtension() === 'txt')
		{
			return new FolderListFileHandler($this->accessService, $file, $currentUser);
		}

		$driver = Driver::getInstance();
		$handlersManager = $driver->getDocumentHandlersManager();
		$documentHandler = $handlersManager->getHandlerByCode('onlyoffice');

		if (!$documentHandler)
		{
			return new DefaultHtmlRenderableFileHandler($file);
		}

		if ($deferred)
		{
			return new DeferredOnlyOfficeHtmlRenderableFileHandler();
		}

		return new OnlyOfficeHtmlRenderableFileHandler($file, $documentSource, $analytics);
	}

	private function getDocumentSource(File $file, ?AttachedObject $attachedObject, ?Version $version = null): DocumentSource
	{
		if ($version !== null)
		{
			return DocumentSource::fromVersion($version);
		}

		if ($attachedObject !== null)
		{
			return DocumentSource::fromAttachedObject($attachedObject);
		}

		return DocumentSource::fromFile($file);
	}
}
