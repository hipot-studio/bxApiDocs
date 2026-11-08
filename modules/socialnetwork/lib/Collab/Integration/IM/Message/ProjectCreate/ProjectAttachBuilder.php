<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\IM\Message\ProjectCreate;

use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Message\ProjectMembersEnumeration;
use CIMMessageParamAttach;

class ProjectAttachBuilder
{
	private const COLUMN_WIDTH = 120;
	private const MAX_VALUE_LENGTH = 350;

	public function __construct(
		private readonly DateRangeFormatter $dateRangeFormatter,
		private readonly ProjectMembersEnumeration $membersEnumeration,
	)
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

		$owner = trim((string)($parameters['ownerName'] ?? ''));
		if ($owner !== '')
		{
			$grid[] = $this->makeRow(
				Loc::getMessage('SOCIALNETWORK_CHAT_PROJECT_CREATE_OWNER_LABEL'),
				$this->membersEnumeration->plainName($owner),
			);
		}

		$members = $this->formatMembers($parameters);
		if ($members !== '')
		{
			$grid[] = $this->makeRow(
				Loc::getMessage('SOCIALNETWORK_CHAT_PROJECT_CREATE_MEMBERS_LABEL'),
				$members,
			);
		}

		return $grid;
	}

	/**
	 * Everyone but the owner in one list, told apart by nothing: the card shows who is in the project, not
	 * how the rights are split, so a moderator is enumerated as a plain member.
	 *
	 * Departments and teams open the row. The value is cut by length, and an entry that stands for a whole
	 * department is worth more of the visible part than another name.
	 */
	private function formatMembers(array $parameters): string
	{
		$nodes = $parameters['memberNodes'] ?? [];
		$names = array_map(
			fn(string $name): string => $this->membersEnumeration->plainName($name),
			$parameters['memberNames'] ?? [],
		);

		return $this->membersEnumeration->join(
			array_merge([$this->membersEnumeration->renderNodes($nodes)], $names),
		);
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
