<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\DocumentResolveContext;
use Bitrix\Disk\Document\DocumentSource;
use Bitrix\Disk\Document\Vibeoffice\VibeofficeHandler;
use Bitrix\Disk\Driver;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\HtmlViewerPolicy;
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
		bool $deferred = false,
		?ExternalLink $externalLink = null,
	): HtmlRenderableFileHandler
	{
		if ($externalLink instanceof ExternalLink)
		{
			return new ExternalLinkHandler($file, $externalLink, $attachedObject, $version);
		}

		if (!$currentUser instanceof CurrentUser)
		{
			return new DefaultHtmlRenderableFileHandler($file);
		}

		$typeFile = (int)$file->getTypeFile();
		$documentSource = $this->getDocumentSource($file, $attachedObject, $version);

		// The html branch of TypeFile::KNOWN, checked before the match because the rest of that type only
		// leads to the grid.
		if ($this->isHtmlViewerTarget($file, $attachedObject, $version))
		{
			return new HtmlFileHandler($documentSource);
		}

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

	/**
	 * Asks about the very source the handler shows, in the order getDocumentSource() picks it: a revision
	 * keeps the name it was saved under and has no stored type of its own, so renaming the file neither
	 * takes the formatted view away from a revision that has it nor sends one that has not into a page
	 * whose endpoint would refuse it. The file answers with its stored type as well, which is what keeps
	 * a document renamed to .html with the editors it was stored for.
	 */
	private function isHtmlViewerTarget(File $file, ?AttachedObject $attachedObject, ?Version $version): bool
	{
		if (!Configuration::isEnabledHtmlViewer())
		{
			return false;
		}

		$revision = $version ?? $attachedObject?->getVersion();

		return $revision === null
			? HtmlViewerPolicy::isViewableFile($file)
			: HtmlViewerPolicy::isViewableExtension($revision->getExtension())
		;
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
		// Single engine-selection seam (same as controller-dispatch): the object context is
		// passed through as the same no-op seam and does not affect the decision yet.
		$attachedObjectId = $documentSource->getAttachedObject()?->getId();
		$documentHandler = $handlersManager->resolveEffectiveHandler(
			'onlyoffice',
			DocumentResolveContext::forObject(
				(int)$file->getId(),
				$attachedObjectId !== null ? (int)$attachedObjectId : null,
			),
		);

		if (!$documentHandler)
		{
			return new DefaultHtmlRenderableFileHandler($file);
		}

		if ($deferred)
		{
			return new DeferredOnlyOfficeHtmlRenderableFileHandler();
		}

		if ($documentHandler instanceof VibeofficeHandler)
		{
			return new VibeofficeHtmlRenderableFileHandler($file, $documentSource, $analytics);
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
