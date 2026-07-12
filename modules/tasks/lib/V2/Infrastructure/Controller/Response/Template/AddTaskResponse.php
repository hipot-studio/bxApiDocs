<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Response\Template;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Entity\DiskFileCollection;

class AddTaskResponse implements Arrayable
{
	public function __construct(
		private readonly Task $task,
		private readonly DiskFileCollection $files,
	)
	{

	}

	public function toArray(): array
	{
		return [
			...$this->task->toArray(),
			'files' => $this->files->toArray(),
		];
	}
}
