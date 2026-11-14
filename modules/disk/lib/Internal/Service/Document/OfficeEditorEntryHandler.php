<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Document;

use Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler;
use Bitrix\Disk\Document\Vibeoffice\VibeofficeHandler;

/**
 * The item of the office editor under the entry code in lists of services, while OnlyOffice is not registered and
 * VibeOffice opens the documents.
 *
 * Lists and the primary service of a user read only its code, name and unified link support; a document is opened
 * through {@see \Bitrix\Disk\Document\DocumentHandlersManager::resolveEffectiveHandler()}. The item is never put
 * into the registry of handlers.
 */
final class OfficeEditorEntryHandler extends OnlyOfficeHandler
{
	public function checkAccessibleTokenService()
	{
		return OfficeEditorAvailability::isAvailable();
	}

	/**
	 * Answers as VibeOffice: the unified link is created by it, with its own option, see
	 * {@see \Bitrix\Disk\Controller\DocumentService::goToCreateAction()}.
	 */
	public function supportsUnifiedLink(): bool
	{
		return (new VibeofficeHandler($this->userId))->supportsUnifiedLink();
	}
}
