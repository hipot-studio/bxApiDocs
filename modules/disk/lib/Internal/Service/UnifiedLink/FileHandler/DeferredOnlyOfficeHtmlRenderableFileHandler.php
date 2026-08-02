<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

class DeferredOnlyOfficeHtmlRenderableFileHandler implements HtmlRenderableFileHandler
{
	private const COMPONENT = 'bitrix:disk.deferred.doc.load';

	public function view(): FileHandlerOperationResult
	{
		return FileHandlerOperationResult::createSuccess(
			value: '',
			component: self::COMPONENT,
		);
	}

	public function edit(): FileHandlerOperationResult
	{
		return FileHandlerOperationResult::createSuccess(
			value: '',
			component: self::COMPONENT,
		);
	}

}