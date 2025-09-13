<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\DiskFileRepositoryInterface;

class File extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.File.list
	 */
	#[CloseSession]
	public function listAction(
		array $ids,
		DiskFileRepositoryInterface $fileRepository,
	): Entity\DiskFileCollection
	{
		return $fileRepository->getByIds($ids);
	}
}
