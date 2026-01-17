<?php

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Request\Task\File;

use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Interaction\Request\Request;

class AttachFileRequest extends Request
{
	public int $taskId;

	#[ElementType('int')]
	public array $fileIds;
}
