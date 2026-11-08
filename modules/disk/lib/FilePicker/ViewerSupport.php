<?php

declare(strict_types=1);

namespace Bitrix\Disk\FilePicker;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Document\DocumentHandler;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\Document\DocumentViewPolicy;
use Bitrix\Disk\Internal\Service\UnifiedLink;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\Ui\FileAttributes;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\UI\Viewer\Renderer;

/**
 * Tells whether the picker may offer the standard viewer for a file, judging by what the file model
 * already knows. The answer is optimistic by design: it costs no read of b_file and no write at all.
 * The objects behind the links of a listed set are resolved in one query, so no item costs a query.
 */
final class ViewerSupport
{
	private ?DocumentHandler $defaultHandlerForView = null;
	private ?UnifiedLink\UnifiedLinkSupportService $unifiedLinkSupport = null;

	public function __construct(
		private readonly RealObjectResolver $realObjectResolver = new RealObjectResolver(),
	)
	{
	}

	/**
	 * Resolves the objects the links of the set stand for in one query. A link owns no stored file of its
	 * own, so asking it for one goes to the object behind it, which would cost a query per listed link.
	 *
	 * @param array<BaseObject> $objects
	 */
	public function warmUpLinkedFiles(array $objects): void
	{
		$this->realObjectResolver->warmUp($objects);
	}

	public function isPreviewable(File $file): bool
	{
		$fileId = $this->resolveFileId($file);
		if ($fileId <= 0)
		{
			return false;
		}

		$contentType = TypeFile::getMimeTypeByFilename((string)$file->getName());
		$type = FileAttributes::resolveViewerTypeByFileModel($file, $contentType, $fileId);

		// Both the stub and the size-restricted renderer report this type: no viewer renders the file.
		if ($type === Renderer\Stub::getJsType())
		{
			return false;
		}

		if (!FileAttributes::requiresUnifiedLinkToOpen($type, $contentType))
		{
			return true;
		}

		return $this->allowsUnifiedLinkByConfiguration($file);
	}

	/**
	 * The warmed up set answers for a link; one left out of it falls back to the model, which resolves the
	 * object behind the link on its own.
	 */
	private function resolveFileId(File $file): int
	{
		$realObject = $this->realObjectResolver->resolve($file);

		return $realObject === null ? (int)$file->getFileId() : (int)$realObject->getFileId();
	}

	/**
	 * A separate item opens from the picker by its unified link and by nothing else, so the link has to be
	 * possible at all. Only the configuration is asked: the support of the object writes the unique code
	 * back when it is missing, which a listing must not do.
	 */
	private function allowsUnifiedLinkByConfiguration(File $file): bool
	{
		return UnifiedLink\Configuration::isDocumentHandlerAllowed($this->getDefaultHandlerForView())
			&& $this->getUnifiedLinkSupport()->supportsByConfiguration($file)
		;
	}

	/**
	 * Resolving the handler walks the list of registered ones, and a listing judges a page of files at once.
	 */
	private function getDefaultHandlerForView(): DocumentHandler
	{
		return $this->defaultHandlerForView ??= DocumentViewPolicy::getDefaultHandlerForView();
	}

	private function getUnifiedLinkSupport(): UnifiedLink\UnifiedLinkSupportService
	{
		return $this->unifiedLinkSupport ??= ServiceLocator::getInstance()
			->get(UnifiedLink\UnifiedLinkSupportService::class)
		;
	}
}
