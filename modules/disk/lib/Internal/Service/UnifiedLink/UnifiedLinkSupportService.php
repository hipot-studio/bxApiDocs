<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Document\DocumentHandler;
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

	public function supportsDocumentHandler(DocumentHandler $documentHandler): bool
	{
		return Configuration::isEnabled()
			&& Configuration::isFileTypeAllowed(TypeFile::DOCUMENT)
			&& Configuration::isDocumentHandlerAllowed($documentHandler);
	}
}
