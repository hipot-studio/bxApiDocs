<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Command\UnifiedLink;

use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\ORM\Data\AddResult;

class SetAccessCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $objectId,
		public readonly UnifiedLinkAccessLevel $level,
		public readonly ?int $changedBy = null,
	) {
	}

	public function toArray(): array
	{
		return [
			'objectId' => $this->objectId,
			'level' => $this->level->value,
			'changedBy' => $this->changedBy,
		];
	}

	protected function execute(): AddResult
	{
		return (new SetAccessCommandHandler())($this);
	}
}
