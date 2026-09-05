<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\UnifiedLink\Render\StandalonePageRenderer;
use Bitrix\Disk\Version;

class ExternalLinkHandler implements HtmlRenderableFileHandler
{
	/**
	 * @param File $file
	 */
	public function __construct(
		protected File $file,
		protected ExternalLink $externalLink,
		protected ?AttachedObject $attachedObject = null,
		protected ?Version $version = null,
	)
	{
	}

	public function view(): FileHandlerOperationResult
	{
		return $this->renderComponent(UnifiedLinkAccessLevel::Read);
	}

	public function edit(): FileHandlerOperationResult
	{
		return $this->renderComponent(UnifiedLinkAccessLevel::Edit);
	}

	/**
	 * @param UnifiedLinkAccessLevel $accessLevel
	 * @return FileHandlerOperationResult
	 */
	protected function renderComponent(UnifiedLinkAccessLevel $accessLevel): FileHandlerOperationResult
	{
		$content = StandalonePageRenderer::render(
			'bitrix:disk.external.link',
			'',
			[
				'action' => $accessLevel === UnifiedLinkAccessLevel::Edit ? 'goToEdit' : 'default',
				'FROM_UNIFIED_LINK' => true,
				'FILE' => $this->file,
				'EXTERNAL_LINK' => $this->externalLink,
				'ATTACHED_OBJECT' => $this->attachedObject,
				'VERSION' => $this->version,
			],
		);

		return FileHandlerOperationResult::createSuccess($content);
	}
}
