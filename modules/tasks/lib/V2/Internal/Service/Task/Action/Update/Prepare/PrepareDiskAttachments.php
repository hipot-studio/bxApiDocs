<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Prepare;

use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Trait\ConfigTrait;
use Bitrix\Tasks\V2\Internal\Service\Trait\DiskAttachmentsTrait;

class PrepareDiskAttachments implements PrepareFieldInterface
{
	use ConfigTrait;
	use DiskAttachmentsTrait;

	public function __invoke(array $fields, array $fullTaskData): array
	{
		return $this->stripDetachedInlineFiles($fields, $fullTaskData);
	}
}
