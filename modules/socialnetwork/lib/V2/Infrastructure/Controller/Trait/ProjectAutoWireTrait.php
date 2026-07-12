<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Controller\Trait;

use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Socialnetwork\V2\Public\Dto;

trait ProjectAutoWireTrait
{
	/**
	 * @return ExactParameter[]
	 */
	protected function getProjectAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				Dto\Project\Project::class,
				'project',
				fn (string $className, array $project): ?Dto\Project\Project
					=> $this->getWithAccess($this, 'project', Dto\Project\Project::mapFromArray($project)),
			),
			new ExactParameter(
				Dto\Project\Project::class,
				'project',
				fn (string $className, int $projectId): ?Dto\Project\Project
					=> $this->getWithAccess($this, 'project', new Dto\Project\Project(id: $projectId)),
			),
		];
	}
}
