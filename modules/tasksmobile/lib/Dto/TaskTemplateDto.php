<?php

declare(strict_types=1);

namespace Bitrix\TasksMobile\Dto;

use Bitrix\TasksMobile\Enum\TaskPriority;

final class TaskTemplateDto
{
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $description,
		public readonly TaskPriority $priority,

		public readonly ?int $creatorId = null,

		/** @var int[] */
		public readonly array $accomplices = [],

		/** @var int[] */
		public readonly array $auditors = [],

		/** @var DiskFileDto[] */
		public readonly array $files = [],

		public readonly array|null $checklist = null,

		/** @var TaskTemplateTagDto[] */
		public readonly array $tags = [],

		/** @var RelatedCrmItemDto[] */
		public readonly array $crm = [],

		public readonly ?int $groupId = null,

		public readonly ?int $responsibleId = null,

		public readonly bool $isRepeatable = false,

		public readonly ?array $replicateParams = null,

		public readonly int $deadlineAfter = 0,

		public readonly bool $allowChangeDeadline = false,

		public readonly bool $allowTimeTracking = false,

		public readonly bool $allowTaskControl = false,

		public readonly bool $isMatchWorkTime = false,

		public readonly bool $isResultRequired = false,

		public readonly int $timeEstimate = 0,

		public readonly int $startDatePlanAfter = 0,

		public readonly int $endDatePlanAfter = 0,

		public readonly bool $addInReport = false,

		public readonly bool $descriptionInBbcode = true,

		public readonly array $userFields = [],
	) {}
}
