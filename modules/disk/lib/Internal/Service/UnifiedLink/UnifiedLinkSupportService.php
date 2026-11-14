<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Document\DocumentHandler;
use Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler;
use Bitrix\Disk\Document\Vibeoffice\VibeofficeHandler;
use Bitrix\Disk\Internal\Service\Document\DocumentViewPolicy;
use Bitrix\Disk\Internal\Service\UnifiedLink\SupportPolicy\SupportPolicyFactory;
use Bitrix\Disk\TypeFile;

readonly class UnifiedLinkSupportService
{
	public function __construct(
		private SupportPolicyFactory $factory,
	)
	{
	}

	public function supports(BaseObject $baseObject): bool
	{
		return $this->factory->create($baseObject)->supports($baseObject);
	}

	/**
	 * The optimistic answer of the configuration: no unique code is written and no stored file is read, so
	 * a listing may ask it about every object it shows.
	 */
	public function supportsByConfiguration(BaseObject $baseObject): bool
	{
		return $this->factory->create($baseObject)->supportsByConfiguration($baseObject);
	}

	/**
	 * A document created by the unified link is opened by the handler of the portal viewer, which edits
	 * documents only for OnlyOffice and Vibeoffice; any other viewer would show the grid instead.
	 */
	public function supportsDocumentHandler(DocumentHandler $documentHandler): bool
	{
		return Configuration::isEnabled()
			&& Configuration::isFileTypeAllowed(TypeFile::DOCUMENT)
			&& $this->isViewerEditingDocuments();
	}

	private function isViewerEditingDocuments(): bool
	{
		$viewHandler = DocumentViewPolicy::getDefaultHandlerForView();

		return $viewHandler instanceof OnlyOfficeHandler || $viewHandler instanceof VibeofficeHandler;
	}
}
