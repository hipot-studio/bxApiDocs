<?php

namespace Bitrix\Tasksmobile\Dto;

use Bitrix\Mobile\Dto\Dto;

final class ProjectListItemDto extends Dto
{
	public function __construct(
		public readonly int $id,
		public readonly ?int $activityDate,
		public readonly bool $isPinned,
		public readonly bool $opened,
		public readonly bool $closed,
		public readonly bool $visible,
		public readonly int $ownerId = 0,
		public readonly array $moderatorIds = [],
		public readonly array $memberIds = [],
		public readonly array $counter = [],
		public readonly array $actions = [],
		public readonly bool $hasCollabers = false,
	)
	{
		parent::__construct();
	}
}
