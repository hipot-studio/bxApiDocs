<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Document\Vibeoffice\VibeofficeHandler;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\UnifiedLink\Render\StandalonePageRenderer;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\Version;

class ExternalLinkHandler implements HtmlRenderableHandler, AutomaticEditModeCapable
{
	/**
	 * @param BaseObject $baseObject
	 */
	public function __construct(
		protected BaseObject $baseObject,
		protected ?ExternalLink $externalLink = null,
		protected ?AttachedObject $attachedObject = null,
		protected ?Version $version = null,
	)
	{
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
			&& VibeofficeHandler::isEditable($this->version?->getExtension() ?? $this->baseObject->getExtension())
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
			[
				'action' => $accessLevel === UnifiedLinkAccessLevel::Edit ? 'goToEdit' : 'default',
				'FROM_UNIFIED_LINK' => true,
				'BASE_OBJECT' => $this->baseObject,
				'EXTERNAL_LINK' => $this->externalLink,
				'ATTACHED_OBJECT' => $this->attachedObject,
				'VERSION' => $this->version,
			],
		);

		return HandlerOperationResult::createSuccess($content);
	}
}
