<?php

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task\Result;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Access\Task\Result\Permission;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Entity\DiskFileCollection;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Provider\DiskFileProvider;

class File extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.Result.File.list
	 */
	#[CloseSession]
	public function listAction(
		#[Permission\Read]
		Entity\Result $result,
		#[ElementsType(Type::Integer)]
		array $ids,
		DiskFileProvider $diskFileProvider,
	): DiskFileCollection
	{
		return $diskFileProvider->getResultAttachmentsByIds(
			ids: $ids,
			taskId: $result->taskId,
			resultId: $result->id,
			userId: $this->userId,
		);
	}
}
