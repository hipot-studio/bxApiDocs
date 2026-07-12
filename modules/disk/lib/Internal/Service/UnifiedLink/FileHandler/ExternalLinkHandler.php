<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;

class ExternalLinkHandler implements HtmlRenderableFileHandler
{
	/**
	 * @param File $file
	 */
	public function __construct(
		protected File $file,
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
		$content = $GLOBALS['APPLICATION']->includeComponent(
			'bitrix:ui.sidepanel.wrapper',
			'',
			[
				'RETURN_CONTENT' => true,
				'POPUP_COMPONENT_NAME' => 'bitrix:disk.external.link',
				'POPUP_COMPONENT_TEMPLATE_NAME' => '',
				'POPUP_COMPONENT_PARAMS' => [
					'action' =>
						$accessLevel === UnifiedLinkAccessLevel::Edit
							? 'goToEdit'
							: 'default'
					,
					'FROM_UNIFIED_LINK' => true,
					'FILE' => $this->file,
				],
				'PLAIN_VIEW' => true,
				'IFRAME_MODE' => true,
				'PREVENT_LOADING_WITHOUT_IFRAME' => false,
				'USE_PADDING' => false,
			],
		);

		return FileHandlerOperationResult::createSuccess($content);
	}
}
