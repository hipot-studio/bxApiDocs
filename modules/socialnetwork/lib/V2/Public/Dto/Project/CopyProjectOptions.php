<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto\Project;

use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;
use Bitrix\Socialnetwork\V2\Internal\Entity\Trait\MapTypeTrait;

class CopyProjectOptions implements EntityInterface
{
	use MapTypeTrait;

	public function __construct(
		#[Validatable]
		public readonly ?CopyProjectTasksOptions $tasks = null,
		#[Validatable]
		public readonly ?CopyProjectDiskOptions $disk = null,
	)
	{
	}

	public function getId(): ?int
	{
		return null;
	}

	public static function mapFromArray(array $props): static
	{
		$tasks = null;
		if (is_array($props['tasks'] ?? null))
		{
			$tasks = CopyProjectTasksOptions::mapFromArray($props['tasks']);
		}

		$disk = null;
		if (is_array($props['disk'] ?? null))
		{
			$disk = CopyProjectDiskOptions::mapFromArray($props['disk']);
		}

		return new static(
			tasks: $tasks,
			disk: $disk,
		);
	}

	public function toArray(): array
	{
		return [
			'tasks' => $this->tasks?->toArray(),
			'disk' => $this->disk?->toArray(),
		];
	}
}
