<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project;

use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Project;

class ProjectCopyDatesBuilder
{
	public function build(Project $project): ?array
	{
		if (
			$project->dates === null
			|| ($project->dates->start === null && $project->dates->finish === null)
		)
		{
			return null;
		}

		$datesPayload = [
			'project' => true,
		];

		if ($project->dates->start !== null)
		{
			$datesPayload['start_point'] = $this->formatDate($project->dates->start);
		}

		if ($project->dates->finish !== null)
		{
			$datesPayload['end_point'] = $this->formatDate($project->dates->finish);
		}

		return $datesPayload;
	}

	private function formatDate(DateTime $date): string
	{
		$bitrixDateFormat = \defined('FORMAT_DATE') ? FORMAT_DATE : 'DD.MM.YYYY';
		$phpDateFormat = DateTime::convertFormatToPhp($bitrixDateFormat);

		return $date->format($phpDateFormat);
	}
}
