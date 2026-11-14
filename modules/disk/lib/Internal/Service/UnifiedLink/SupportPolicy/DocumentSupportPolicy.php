<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\SupportPolicy;

use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\Document\DocumentViewPolicy;

class DocumentSupportPolicy extends FileSupportPolicy
{
	protected function passesSpecializedChecks(File $file): bool
	{
		$contentType = $file->getFile()['CONTENT_TYPE'] ?? '';

		if ($contentType === 'text/plain' || $file->getExtension() === 'txt')
		{
			return true;
		}

		return DocumentViewPolicy::isAllowedUseVibeofficeSource($file, null, $contentType);
	}
}
