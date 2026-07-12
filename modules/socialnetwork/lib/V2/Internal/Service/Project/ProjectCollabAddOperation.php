<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use Bitrix\Socialnetwork\Collab\Control\Operation\AddOperation;
use Bitrix\Socialnetwork\Control\Command\AddCommand;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Project;

class ProjectCollabAddOperation extends AddOperation
{
	public function __construct(
		AddCommand $command,
		private readonly Project $project,
	)
	{
		parent::__construct($command);
	}

	protected function getFields(): array
	{
		return [
			...parent::getFields(),
			...$this->getProjectFields(),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getProjectFields(): array
	{
		$fields = [
			'PROJECT' => 'Y',
		];

		if ($this->project->dates !== null)
		{
			$fields['PROJECT_DATE_START'] = $this->project->dates->start ?? '';
			$fields['PROJECT_DATE_FINISH'] = $this->project->dates->finish ?? '';
		}

		if ($this->project->goal !== null)
		{
			$fields['GOAL'] = $this->project->goal;
		}

		return $fields;
	}
}
