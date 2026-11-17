<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Document\DocumentSource;
use Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler;
use Bitrix\Disk\Document\Vibeoffice\OpenRoutePolicy;
use Bitrix\Disk\Document\Vibeoffice\VibeofficeHandler;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\Document\DocumentViewPolicy;
use Bitrix\Disk\Internal\Service\HtmlViewerPolicy;
use Bitrix\Disk\Internal\Service\MarkdownTabPolicy;
use Bitrix\Disk\Internal\Service\UnifiedLink\Render\ClientContext;
use Bitrix\Disk\Internal\Service\UnifiedLink\ShortLinkOpening;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkUrl;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\Version;
use Bitrix\Main\Engine\CurrentUser;

readonly class HtmlRenderableHandlerFactory
{
	public function __construct(
		protected UnifiedLinkAccessService $accessService,
		protected UnifiedLinkUrl $unifiedLinkUrl,
	)
	{
	}

	public function createHandler(
		BaseObject $baseObject,
		?AttachedObject $attachedObject = null,
		?Version $version = null,
		array $analytics = [],
		?CurrentUser $currentUser = null,
		bool $deferred = false,
		?ExternalLink $externalLink = null,
		?UnifiedLinkAccessLevel $accessLevel = null,
		?ShortLinkOpening $shortLinkOpening = null,
		ClientContext $client = ClientContext::Web,
	): HtmlRenderableHandler
	{
		if ($shortLinkOpening !== null && $baseObject instanceof File)
		{
			return $this->createHandlerForShortLinkOpening(
				opening: $shortLinkOpening,
				attachedObject: $attachedObject,
				analytics: $analytics,
				currentUser: $currentUser,
				deferred: $deferred,
				externalLink: $externalLink,
				accessLevel: $accessLevel,
				client: $client,
			);
		}

		return match (true) {
			$baseObject instanceof File => $this->createHandlerForFile(
				file: $baseObject,
				attachedObject: $attachedObject,
				version: $version,
				analytics: $analytics,
				currentUser: $currentUser,
				deferred: $deferred,
				externalLink: $externalLink,
				accessLevel: $accessLevel,
				client: $client,
			),
			$baseObject instanceof Folder => $this->createHandlerForFolder(
				folder: $baseObject,
				currentUser: $currentUser,
				externalLink: $externalLink,
			),
			default => $this->getDefaultHandler($baseObject),
		};
	}

	/**
	 * The short opening keeps its source and its address whoever follows it. A guest gets the public page of the
	 * very link the code names. An employee gets the office editors by the usual rules; any other type is shown
	 * on the public page of the source in view, never moved to a list, a HASH page or the own Disk.
	 */
	private function createHandlerForShortLinkOpening(
		ShortLinkOpening $opening,
		?AttachedObject $attachedObject,
		array $analytics,
		?CurrentUser $currentUser,
		bool $deferred,
		?ExternalLink $externalLink,
		?UnifiedLinkAccessLevel $accessLevel,
		ClientContext $client,
	): HtmlRenderableHandler
	{
		$sourcePage = static fn (bool $viewOnly): ExternalLinkHandler => new ExternalLinkHandler(
			$opening->getFile(),
			$opening->getExternalLink(),
			null,
			$opening->getVersion(),
			$opening,
			$viewOnly,
		);
		if ($externalLink instanceof ExternalLink || !$currentUser instanceof CurrentUser)
		{
			return $sourcePage(false);
		}

		$handler = $this->createHandlerForFile(
			file: $opening->getFile(),
			attachedObject: $attachedObject,
			version: $opening->getVersion(),
			analytics: $analytics,
			currentUser: $currentUser,
			deferred: $deferred,
			externalLink: null,
			accessLevel: $accessLevel,
			client: $client,
		);

		// The stub of the deferred load goes on at the address of the opening.
		if ($handler instanceof DeferredOnlyOfficeHtmlRenderableFileHandler)
		{
			return new DeferredOnlyOfficeHtmlRenderableFileHandler($opening);
		}

		if ($handler instanceof VibeofficeHtmlRenderableHandler || $handler instanceof OnlyOfficeHtmlRenderableHandler)
		{
			$handler->setShortLinkOpening($opening);
		}

		return self::isOfficeEditor($handler) ? $handler : $sourcePage(true);
	}

	private static function isOfficeEditor(HtmlRenderableHandler $handler): bool
	{
		return $handler instanceof VibeofficeHtmlRenderableHandler
			|| $handler instanceof OnlyOfficeHtmlRenderableHandler
			|| $handler instanceof DeferredOnlyOfficeHtmlRenderableFileHandler
		;
	}

	private function createHandlerForFile(
		File $file,
		?AttachedObject $attachedObject,
		?Version $version,
		array $analytics,
		?CurrentUser $currentUser,
		bool $deferred,
		?ExternalLink $externalLink,
		?UnifiedLinkAccessLevel $accessLevel,
		ClientContext $client,
	): HtmlRenderableHandler
	{
		if ($externalLink instanceof ExternalLink)
		{
			return new ExternalLinkHandler($file, $externalLink, $attachedObject, $version);
		}

		if (!$currentUser instanceof CurrentUser)
		{
			return $this->getDefaultHandler($file);
		}

		$typeFile = (int)$file->getTypeFile();
		$documentSource = $this->getDocumentSource($file, $attachedObject, $version);

		// The html branch of TypeFile::KNOWN, checked before the match because the rest of that type only
		// leads to the grid.
		if ($this->isHtmlViewerTarget($file, $attachedObject, $version))
		{
			return new HtmlFileHandler($documentSource, $accessLevel);
		}

		// Markdown is TypeFile::KNOWN as well; an attachment and the mobile application keep the viewer they have.
		if (
			$client === ClientContext::Web
			&& $attachedObject === null
			&& MarkdownTabPolicy::isTabSource($file, $version)
		)
		{
			return new MarkdownFileHandler($file, $version, $accessLevel);
		}

		$selectedVersion = $version ?? $attachedObject?->getVersion();
		if (
			OpenRoutePolicy::isAdditionalOfficeSource($file, $selectedVersion)
			&& ($file->getFile()['CONTENT_TYPE'] ?? '') === 'text/plain'
		)
		{
			return new FolderListHandler($this->accessService, $this->unifiedLinkUrl, $file, $currentUser);
		}

		if (DocumentViewPolicy::isAdditionalVibeofficeSource($file, $selectedVersion))
		{
			return $this->getDocumentHandler($file, $documentSource, $currentUser, $analytics, $deferred);
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
			TypeFile::BOARD => new BoardHtmlRenderableFileHandler($file, $documentSource),
			TypeFile::IMAGE,
			TypeFile::VIDEO,
			TypeFile::ARCHIVE,
			TypeFile::SCRIPT,
			TypeFile::UNKNOWN,
			TypeFile::AUDIO,
			TypeFile::KNOWN,
			TypeFile::VECTOR_IMAGE => new FolderListHandler(
				accessService: $this->accessService,
				unifiedLinkUrl: $this->unifiedLinkUrl,
				baseObject: $file,
				currentUser: $currentUser,
			),
			default => $this->getDefaultHandler($file),
		};
	}

	private function createHandlerForFolder(
		Folder $folder,
		?CurrentUser $currentUser,
		?ExternalLink $externalLink,
	): HtmlRenderableHandler
	{
		if ($externalLink instanceof ExternalLink)
		{
			return new ExternalLinkHandler($folder, $externalLink);
		}

		if (!$currentUser instanceof CurrentUser)
		{
			return $this->getDefaultHandler($folder);
		}

		return new FolderListHandler($this->accessService, $this->unifiedLinkUrl, $folder, $currentUser);
	}

	private function getDefaultHandler(BaseObject $baseObject): DefaultHtmlRenderableHandler
	{
		return new DefaultHtmlRenderableHandler($baseObject);
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
	): HtmlRenderableHandler
	{
		$contentType = $file->getFile()['CONTENT_TYPE'] ?? '';
		$extension = $documentSource->getVersion()?->getExtension()
			?? $documentSource->getAttachedObject()?->getVersion()?->getExtension()
			?? $file->getExtension();

		if ($contentType === 'text/plain' || mb_strtolower($extension) === 'txt')
		{
			return new FolderListHandler($this->accessService, $this->unifiedLinkUrl, $file, $currentUser);
		}

		$attachedObjectId = $documentSource->getAttachedObject()?->getId();

		$documentHandler = DocumentViewPolicy::getEffectiveHandlerForView(
			$file,
			$attachedObjectId !== null ? (int)$attachedObjectId : null,
		);

		if (!$documentHandler)
		{
			return $this->getDefaultHandler($file);
		}

		if (
			!$documentHandler instanceof OnlyOfficeHandler
			&& !$documentHandler instanceof VibeofficeHandler
		)
		{
			return new FolderListHandler($this->accessService, $this->unifiedLinkUrl, $file, $currentUser);
		}

		if ($documentHandler instanceof VibeofficeHandler)
		{
			return new VibeofficeHtmlRenderableHandler($file, $documentSource, $analytics);
		}

		if ($deferred)
		{
			return new DeferredOnlyOfficeHtmlRenderableFileHandler();
		}

		return new OnlyOfficeHtmlRenderableHandler($file, $documentSource, $analytics);
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
