<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\IM\Message\ProjectCreate;

use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\Item\Workgroup;
use CIMMessageParamAttach;

class ProjectAttachBuilder
{
	private const COLUMN_WIDTH = 120;
	private const MAX_VALUE_LENGTH = 350;

	public function __construct(private readonly DateRangeFormatter $dateRangeFormatter)
	{
	}

	public function build(Workgroup $project, int $senderId, array $parameters): CIMMessageParamAttach
	{
		$attach = new CIMMessageParamAttach();
		$attach->AddLink(['NAME' => $project->getName(), 'LINK' => $this->getProjectUrl($project, $senderId)]);

		$grid = $this->buildGrid($project, $parameters);
		if (!empty($grid))
		{
			$attach->AddGrid($grid);
		}

		return $attach;
	}

	private function getProjectUrl(Workgroup $project, int $senderId): string
	{
		$urlData = $project->getGroupUrlData(['USER_ID' => $senderId]);

		return ($urlData['SERVER_NAME'] ?? '') . ($urlData['URL'] ?? '');
	}

	private function buildGrid(Workgroup $project, array $parameters): array
	{
		$grid = [];

		$goal = $parameters['goal'] ?? null;
		if ($goal !== null && $goal !== '')
		{
			$grid[] = $this->makeRow(
				Loc::getMessage('SOCIALNETWORK_CHAT_PROJECT_CREATE_GOAL_LABEL'),
				$goal,
			);
		}

		$description = $project->getDescription();
		if ($description !== '')
		{
			$grid[] = $this->makeRow(
				Loc::getMessage('SOCIALNETWORK_CHAT_PROJECT_CREATE_DESCRIPTION_LABEL'),
				$description,
			);
		}

		$dateStart = $parameters['dateStart'] ?? null;
		$dateFinish = $parameters['dateFinish'] ?? null;
		if ($dateStart !== null || $dateFinish !== null)
		{
			$grid[] = $this->makeRow(
				Loc::getMessage('SOCIALNETWORK_CHAT_PROJECT_CREATE_DATES_LABEL'),
				$this->dateRangeFormatter->format($dateStart, $dateFinish),
			);
		}

		return $grid;
	}

	private function makeRow(string $name, string $value): array
	{
		return [
			'NAME' => $name,
			'VALUE' => $this->truncate($value),
			'DISPLAY' => 'ROW',
			'WIDTH' => self::COLUMN_WIDTH,
		];
	}

	private function truncate(string $value): string
	{
		return mb_strimwidth($value, 0, self::MAX_VALUE_LENGTH, '...');
	}
}
