<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\UnifiedLink\AccessCheckHandlerFactory;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Version;

class UnifiedLinkAccessService
{
	public function __construct(
		private readonly AccessCheckHandlerFactory $accessCheckHandlerFactory,
	)
	{
	}

	public function check(
		File $file,
		?AttachedObject $attachedObject = null,
		int $userId = 0,
		?ExternalLink $externalLink = null,
		?Version $version = null,
	): UnifiedLinkAccessLevel
	{
		return $this->accessCheckHandlerFactory->create(
			$attachedObject,
			$userId,
			$externalLink,
			$version,
		)->check($file);
	}
}
