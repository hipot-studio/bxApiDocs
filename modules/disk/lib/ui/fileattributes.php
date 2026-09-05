<?php

namespace Bitrix\Disk\Ui;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\BitrixHandler;
use Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\HtmlViewerPolicy;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\UI\Viewer\Renderer\Html;
use Bitrix\Disk\UI\Viewer\Renderer\Markdown;
use Bitrix\Disk\Uf\Integration\DiskUploaderController;
use Bitrix\Disk\Version;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\UI\Viewer\ItemAttributes;
use Bitrix\Main\UI\Viewer\Renderer;
use \Bitrix\Disk;
use \Bitrix\Disk\Internal\Service\Document;

final class FileAttributes extends ItemAttributes
{
	public const ATTRIBUTE_OBJECT_ID = 'data-object-id';
	public const ATTRIBUTE_VERSION_ID = 'data-version-id';
	public const ATTRIBUTE_ATTACHED_OBJECT_ID = 'data-attached-object-id';
	public const ATTRIBUTE_SEPARATE_ITEM = 'data-viewer-separate-item';
	public const ATTRIBUTE_UNIFIED_LINK = 'data-unified-link';
	public const ATTRIBUTE_MARKDOWN_URL = 'data-markdown-url';

	public const JS_TYPE_CLASS_MARKDOWN = 'BX.Disk.Viewer.MarkdownItem';

	public const JS_TYPE = 'cloud-document';

	public const JS_TYPE_CLASS_CLOUD_DOCUMENT = 'BX.Disk.Viewer.DocumentItem';
	public const JS_TYPE_CLASS_ONLYOFFICE = 'BX.Disk.Viewer.OnlyOfficeItem';
	public const JS_TYPE_CLASS_UNIFIED_LINK = 'BX.Disk.Viewer.UnifiedLinkItem';
	public const JS_TYPE_CLASS_BOARD = 'BX.Disk.Viewer.BoardItem';

	public const KEY_FILE_OBJECT = 'FILE_OBJECT';
	// The revision the item shows, when it shows one: the file it belongs to answers for everything else.
	public const KEY_VERSION_OBJECT = 'VERSION_OBJECT';

	private bool $needSetUnifiedLink = false;
	private array $unifiedLinkOptions = [];
	private bool $useUnifiedEditLink = false;

	public static function tryBuildByFileId($fileId, $sourceUri, ?File $file = null, ?Version $version = null): self
	{
		try
		{
			return self::buildByFileId($fileId, $sourceUri, $file, $version);
		}
		catch (ArgumentException)
		{
			return self::buildAsUnknownType($sourceUri);
		}
	}

	public static function buildByFileId($fileId, $sourceUri, ?File $file = null, ?Version $version = null): self
	{
		$fileData = \CFile::getByID($fileId)->fetch();

		if ($fileData === false)
		{
			throw new ArgumentException('Invalid fileId', 'fileId');
		}

		$fileData[self::KEY_FILE_OBJECT] = $file;
		$fileData[self::KEY_VERSION_OBJECT] = $version;

		return self::buildByFileData($fileData, $sourceUri);
	}

	private function supportsUnifiedLink(): bool
	{
		$file = $this->getFileObject();
		if ($file === null)
		{
			return false;
		}

		return $file->supportsUnifiedLink();
	}

	private function setUnifiedLink(): void
	{
		$file = $this->getFileObject();
		if ($this->needSetUnifiedLink && isset($file))
		{
			$unifiedLinkOptions = $this->unifiedLinkOptions;

			if (!array_key_exists('absolute', $unifiedLinkOptions))
			{
				$unifiedLinkOptions['absolute'] = true;
			}

			$versionId = (int)$this->getAttribute(self::ATTRIBUTE_VERSION_ID);
			if ($versionId > 0)
			{
				$unifiedLinkOptions['versionId'] = $versionId;
			}

			$attachedObjectId = (int)$this->getAttribute(self::ATTRIBUTE_ATTACHED_OBJECT_ID);
			if ($attachedObjectId > 0)
			{
				$unifiedLinkOptions['attachedId'] = $attachedObjectId;
			}

			$urlManager = Driver::getInstance()->getUrlManager();
			$unifiedLink = $this->useUnifiedEditLink
				? $urlManager->getUnifiedEditLink($file, $unifiedLinkOptions)
				: $urlManager->getUnifiedLink($file, $unifiedLinkOptions);

			$this->setAttribute(self::ATTRIBUTE_UNIFIED_LINK, $unifiedLink);
		}
	}

	/**
	 * Builds the markdown render url once version/attached context is known, picking the matching
	 * endpoint so the formatted view shows the right revision with the right access:
	 *  - attached object -> disk.attachedObject (revision is intrinsic to the attach);
	 *  - else version     -> disk.version;
	 *  - else file        -> disk.file (head).
	 */
	private function setMarkdownUrl(): void
	{
		if ($this->getViewerType() !== Markdown::getJsType())
		{
			return;
		}

		$urlManager = Driver::getInstance()->getUrlManager();

		$attachedObjectId = (int)$this->getAttribute(self::ATTRIBUTE_ATTACHED_OBJECT_ID);
		$versionId = (int)$this->getAttribute(self::ATTRIBUTE_VERSION_ID);

		if ($attachedObjectId > 0)
		{
			$markdownUrl = $urlManager->getUrlForShowMarkdownAttached($attachedObjectId);
		}
		elseif ($versionId > 0)
		{
			$markdownUrl = $urlManager->getUrlForShowMarkdownVersion($versionId);
		}
		else
		{
			$file = $this->getFileObject();
			if ($file === null)
			{
				return;
			}

			$markdownUrl = $urlManager->getUrlForShowMarkdown($file);
		}

		$this->setAttribute(self::ATTRIBUTE_MARKDOWN_URL, $markdownUrl);
	}

	/**
	 * @param array{
	 *      absolute?: bool,
	 *      attachedId?: ?int,
	 *      versionId?: ?int,
	 *      noRedirect?: bool,
	 *      additionalQueryParams?: array
	 * } $options
	 * @return $this
	 */
	public function setUnifiedLinkOptions(array $options): self
	{
		$this->unifiedLinkOptions = $options;

		return $this;
	}

	public function setUseUnifiedEditLink(bool $use = true): self
	{
		$this->useUnifiedEditLink = $use;

		return $this;
	}

	private function getFileObject(): ?File
	{
		if (isset($this->fileData[self::KEY_FILE_OBJECT]) && $this->fileData[self::KEY_FILE_OBJECT] instanceof File)
		{
			return $this->fileData[self::KEY_FILE_OBJECT];
		}

		return null;
	}

	public function setVersionId($versionId)
	{
		$this->setAttribute(self::ATTRIBUTE_VERSION_ID, $versionId);

		return $this;
	}

	public function setObjectId($objectId)
	{
		$this->setAttribute(self::ATTRIBUTE_OBJECT_ID, $objectId);

		return $this;
	}

	public function setAttachedObjectId($attachedObjectId)
	{
		$this->setAttribute(self::ATTRIBUTE_ATTACHED_OBJECT_ID, $attachedObjectId);

		return $this;
	}

	public function setAsSeparateItem()
	{
		$this->setAttribute(self::ATTRIBUTE_SEPARATE_ITEM, true);

		return $this;
	}

	protected function setDefaultAttributes(): void
	{
		parent::setDefaultAttributes();

		$isSupportsUnifiedLink = $this->supportsUnifiedLink();

		if ($isSupportsUnifiedLink)
		{
			$this->needSetUnifiedLink = true;
		}

		if ($this->getViewerType() === Disk\UI\Viewer\Renderer\Board::getJsType())
		{
			if ($isSupportsUnifiedLink)
			{
				$this->setUnifiedLinkViewer();
			}
			else
			{
				$this
					->setAttribute('data-viewer-type-class', 'BX.Disk.Viewer.BoardItem')
					->setTypeClass(self::JS_TYPE_CLASS_BOARD)
					->setAsSeparateItem()
					->setExtension('disk.viewer.board-item')
				;

				Extension::load('disk.viewer.board-item');
			}
		}

		if ($this->getViewerType() === Markdown::getJsType())
		{
			$this
				->setAttribute('data-viewer-type-class', self::JS_TYPE_CLASS_MARKDOWN)
				->setExtension('disk.viewer.markdown-item')
			;
			// The render url is built later, in setMarkdownUrl() (deferred to output, once the
			// version/attached context is known); it self-gates on the markdown viewer type.
			Extension::load('disk.viewer.markdown-item');
		}

		// The html viewer lives on its unified link page, so the item just opens that link in a new tab.
		// refineType() only yields this type for a file that supports the unified link.
		if ($this->getViewerType() === Html::getJsType())
		{
			$this->setUnifiedLinkViewer();
		}

		if (self::isSetViewDocumentInClouds() && Document\DocumentViewPolicy::isAllowedUseClouds($this->fileData['CONTENT_TYPE']))
		{
			$documentHandler = Document\DocumentViewPolicy::getDefaultHandlerForView();
			if ($documentHandler instanceof OnlyOfficeHandler)
			{
				if ($isSupportsUnifiedLink)
				{
					$this->setUnifiedLinkViewer();
				}
				else
				{
					$this
						->setTypeClass(self::JS_TYPE_CLASS_ONLYOFFICE)
						->setAsSeparateItem()
						->setExtension('disk.viewer.onlyoffice-item')
					;

					Extension::load('disk.viewer.onlyoffice-item');
				}
			}
			else
			{
				$this->setTypeClass(self::JS_TYPE_CLASS_CLOUD_DOCUMENT);
				$this->setExtension('disk.viewer.document-item');

				Extension::load('disk.viewer.document-item');
			}
		}
	}

	private function setUnifiedLinkViewer(): void
	{
		$this
			->setTypeClass(self::JS_TYPE_CLASS_UNIFIED_LINK)
			->setAsSeparateItem()
			->setExtension('disk.viewer.unified-link-item')
		;

		Extension::load('disk.viewer.unified-link-item');
	}

	public function setGroupBy($id)
	{
		if (in_array(
			$this->getTypeClass(),
			[self::JS_TYPE_CLASS_ONLYOFFICE, self::JS_TYPE_CLASS_BOARD, self::JS_TYPE_CLASS_UNIFIED_LINK],
			true)
		)
		{
			//temp fix: we have to disable view in group because onlyoffice uses SidePanel
			$this->unsetGroupBy();

			return $this;
		}

		return parent::setGroupBy($id);
	}

	protected static function getViewerTypeByFile(array $fileArray)
	{
		$type = parent::getViewerTypeByFile($fileArray);
		$type = self::refineType($type, $fileArray);

		if (!self::isSetViewDocumentInClouds())
		{
			return $type;
		}

		if ($type === Renderer\Pdf::getJsType() || Document\DocumentViewPolicy::isAllowedUseClouds($fileArray['CONTENT_TYPE']))
		{
			return self::JS_TYPE;
		}

		return $type;
	}

	/**
	 * @internal Should be deleted after main module will be updated.
	 * @return bool
	 */
	protected static function isFakeFileData(array $fileData): bool
	{
		return
			($fileData['ID'] === -1) && ($fileData['CONTENT_TYPE'] === 'application/octet-stream')
		;
	}

	protected static function refineType($type, $fileArray)
	{
		if (static::isFakeFileData($fileArray))
		{
			return $type;
		}

		$fileObject = $fileArray[self::KEY_FILE_OBJECT] ?? null;
		if (
			$fileObject instanceof File
			&& Configuration::isEnabledMarkdownViewer()
			&& self::isMarkdownFile($fileObject)
			&& $fileObject->getSize() <= Configuration::getMaxSizeForMarkdownRender()
		)
		{
			return Markdown::getJsType();
		}

		// The formatted view of an html file lives on its unified link page, so the type is claimed only
		// when that link exists. Size is not checked here (unlike markdown): the limit is enforced when
		// the content is served, so an oversized file still opens the page (with a 413 stub) instead of
		// the code view.
		if (
			$fileObject instanceof File
			&& self::isHtmlViewerSource($fileObject, self::getVersionObject($fileArray))
			&& $fileObject->supportsUnifiedLink()
		)
		{
			return Html::getJsType();
		}

		if (
			$type === Renderer\Stub::getJsType() &&
			!empty($fileArray['ORIGINAL_NAME']) &&
			TypeFile::isImage($fileArray['ORIGINAL_NAME'])
		)
		{
			$type = Renderer\Image::getJsType();
		}

		if ($type === Renderer\Image::getJsType())
		{
			$treatImageAsFile = DiskUploaderController::shouldTreatImageAsFile($fileArray);
			if ($treatImageAsFile)
			{
				$type = Renderer\Stub::getJsType();
			}
		}

		if (self::isBoardType($fileArray))
		{
			$type = Disk\UI\Viewer\Renderer\Board::getJsType();
		}

		return $type;
	}

	private static function getVersionObject(array $fileArray): ?Version
	{
		$version = $fileArray[self::KEY_VERSION_OBJECT] ?? null;

		return $version instanceof Version ? $version : null;
	}

	/**
	 * Asks about the source the item actually shows — the revision when one is given, the file otherwise.
	 * A revision keeps the name it was saved under and has no stored type of its own, so renaming the file
	 * neither takes the formatted view away from a revision that has it nor sends one that has not into a
	 * page whose endpoint would refuse it. The stored type stays part of the file answer: it is what keeps
	 * a document renamed to .html with the editors it was stored for.
	 */
	private static function isHtmlViewerSource(File $file, ?Version $version): bool
	{
		if (!Configuration::isEnabledHtmlViewer())
		{
			return false;
		}

		return $version === null
			? HtmlViewerPolicy::isViewableFile($file)
			: HtmlViewerPolicy::isViewableExtension($version->getExtension())
		;
	}

	protected static function isBoardType(array $fileData): bool
	{
		return !empty($fileData['CONTENT_TYPE'])
			&& $fileData['CONTENT_TYPE'] === 'application/octet-stream'
			&& GetFileExtension($fileData['ORIGINAL_NAME'] ?? '') === 'board'
		;
	}

	protected static function isMarkdownFile(File $file): bool
	{
		$extension = mb_strtolower($file->getExtension());

		return in_array($extension, ['md', 'markdown'], true);
	}

	protected static function isSetViewDocumentInClouds()
	{
		$documentHandler = Document\DocumentViewPolicy::getDefaultHandlerForView();

		return !($documentHandler instanceof BitrixHandler);
	}

	public function __toString()
	{
		$extension = $this->getExtension();
		if ($extension)
		{
			Extension::load($extension);
		}

		$this->setUnifiedLink();
		$this->setMarkdownUrl();

		return parent::__toString();
	}

	public function toDataSet()
	{
		$this->setUnifiedLink();
		$this->setMarkdownUrl();

		return parent::toDataSet();
	}

	public function toVueBind(): array
	{
		$this->setUnifiedLink();
		$this->setMarkdownUrl();

		return parent::toVueBind();
	}
}
