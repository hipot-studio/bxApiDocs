<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\Rule\Range;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Enum\ProjectTasksForAnalysisSort;

class GetProjectTasksForAnalysisDto
{
	use MapTypeTrait;

	public const LIMIT_DEFAULT = 25;
	public const LIMIT_MIN = 1;
	public const LIMIT_MAX = 50;

	private function __construct(
		#[PositiveNumber]
		public readonly ?int $projectId = null,
		public readonly ?int $responsibleId = null,
		public readonly ?int $creatorId = null,
		#[Range(min: self::LIMIT_MIN, max: self::LIMIT_MAX)]
		public readonly int $limit = self::LIMIT_DEFAULT,
		public readonly ProjectTasksForAnalysisSort $sort = ProjectTasksForAnalysisSort::RecentlyChanged,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		$responsibleId = static::mapInteger($props, 'responsibleId');
		if ($responsibleId < 1)
		{
			$responsibleId = null;
		}

		$creatorId = static::mapInteger($props, 'creatorId');
		if ($creatorId < 1)
		{
			$creatorId = null;
		}

		$sort = static::mapBackedEnum($props, 'sort', ProjectTasksForAnalysisSort::class);
		if ($sort === null)
		{
			$sort = ProjectTasksForAnalysisSort::RecentlyChanged;
		}

		return new self(
			projectId: static::mapInteger($props, 'projectId'),
			responsibleId: $responsibleId,
			creatorId: $creatorId,
			limit: static::mapInteger($props, 'limit') ?? self::LIMIT_DEFAULT,
			sort: $sort,
		);
	}
}
