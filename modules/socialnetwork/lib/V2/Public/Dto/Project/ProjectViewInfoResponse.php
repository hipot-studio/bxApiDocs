<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto\Project;

use Bitrix\Main\Type\Contract\Arrayable;

class ProjectViewInfoResponse implements Arrayable
{
	/**
	 * @param string[] $tags
	 */
	public function __construct(
		public readonly string $description,
		public readonly string $goal,
		public readonly ?string $dateStart,
		public readonly ?string $dateFinish,
		public readonly array $tags,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'description' => $this->description,
			'goal' => $this->goal,
			'dateStart' => $this->dateStart,
			'dateFinish' => $this->dateFinish,
			'tags' => $this->tags,
		];
	}
}
