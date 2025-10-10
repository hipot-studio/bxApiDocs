<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Access\UnifiedLink;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\File;
use Bitrix\Main\Engine\CurrentUser;

class AttachedObjectsAccessCheckHandler extends ChainableAccessCheckHandler
{
	public function __construct(
		private readonly ?AttachedObject $attachedObject
	)
	{
	}

	protected function doCheck(File $file): UnifiedLinkAccessLevel
	{
		if ($this->attachedObject !== null)
		{
			$userId = (int)CurrentUser::get()->getId();

			if ($this->attachedObject->canUpdate($userId))
			{
				return UnifiedLinkAccessLevel::Edit;
			}

			if ($this->attachedObject->canRead($userId))
			{
				return UnifiedLinkAccessLevel::Read;
			}
		}

		return UnifiedLinkAccessLevel::Denied;
	}
}