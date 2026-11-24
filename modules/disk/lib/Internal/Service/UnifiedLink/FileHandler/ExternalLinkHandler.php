<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Document\DocumentHandler;
use Bitrix\Disk\Document\Vibeoffice\VibeofficeHandler;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\Document\DocumentViewPolicy;
use Bitrix\Disk\Internal\Service\UnifiedLink\Render\StandalonePageRenderer;
use Bitrix\Disk\Internal\Service\UnifiedLink\ShortLinkOpening;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\Version;

class ExternalLinkHandler implements HtmlRenderableHandler, AutomaticEditModeCapable
{
	/**
	 * @param BaseObject $baseObject
	 * @param bool $shortLinkViewOnly The page of a short opening is only viewed, whatever the link and the
	 *     visitor may: an employee sees a type without an office editor on the page of the source in view.
	 */
	public function __construct(
		protected BaseObject $baseObject,
		protected ?ExternalLink $externalLink = null,
		protected ?AttachedObject $attachedObject = null,
		protected ?Version $version = null,
		protected ?ShortLinkOpening $shortLinkOpening = null,
		protected bool $shortLinkViewOnly = false,
	)
	{
	}

	/** The link the page is drawn for: the source of a short opening is never swapped for another link. */
	public function getExternalLink(): ?ExternalLink
	{
		return $this->externalLink;
	}

	public function getShortLinkOpening(): ?ShortLinkOpening
	{
		return $this->shortLinkOpening;
	}

	public function isShortLinkViewOnly(): bool
	{
		return $this->shortLinkOpening !== null && $this->shortLinkViewOnly;
	}

	public function view(): HandlerOperationResult
	{
		return $this->renderComponent(UnifiedLinkAccessLevel::Read);
	}

	public function edit(): HandlerOperationResult
	{
		return $this->renderComponent(UnifiedLinkAccessLevel::Edit);
	}

	public function supportsAutomaticEditMode(): bool
	{
		return $this->baseObject instanceof File
			&& (int)$this->baseObject->getTypeFile() === TypeFile::DOCUMENT
			&& $this->isViewableDocument($this->baseObject, $this->version)
			&& VibeofficeHandler::isEditable($this->version?->getExtension() ?? $this->baseObject->getExtension())
		;
	}

	private function isViewableDocument(File $file, ?Version $version = null): bool
	{
		$extension = $version?->getExtension() ?? $file->getExtension();

		return DocumentHandler::isEditable($extension)
			|| mb_strtolower($extension) === 'pdf'
			|| DocumentViewPolicy::isAdditionalVibeofficeSource($file, $version)
		;
	}

	/**
	 * @param UnifiedLinkAccessLevel $accessLevel
	 * @return HandlerOperationResult
	 */
	protected function renderComponent(UnifiedLinkAccessLevel $accessLevel): HandlerOperationResult
	{
		$content = StandalonePageRenderer::render(
			'bitrix:disk.external.link',
			'',
			$this->getComponentParameters($accessLevel),
		);

		return HandlerOperationResult::createSuccess($content);
	}

	/**
	 * The short opening travels to the page apart from the access context of a document session.
	 */
	protected function getComponentParameters(UnifiedLinkAccessLevel $accessLevel): array
	{
		return [
			'action' => $accessLevel === UnifiedLinkAccessLevel::Edit && !$this->isShortLinkViewOnly()
				? 'goToEdit'
				: 'default'
			,
			'FROM_UNIFIED_LINK' => true,
			'BASE_OBJECT' => $this->baseObject,
			'EXTERNAL_LINK' => $this->externalLink,
			'ATTACHED_OBJECT' => $this->attachedObject,
			'VERSION' => $this->version,
			'SHORT_LINK_OPENING' => $this->shortLinkOpening,
			'SHORT_LINK_VIEW_ONLY' => $this->isShortLinkViewOnly(),
		];
	}
}
