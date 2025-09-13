<?php

declare(strict_types=1);


namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add;

use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Trait\ConfigTrait;
use Bitrix\Tasks\V2\Internal\Service\Task\Trait\ParticipantTrait;
use Bitrix\Tasks\Internals\TaskObject;
use CIBlock;
use CIBlockElement;
use CIBlockElementRights;
use CWebDavIblock;

class AddWebDavFiles
{
	use ParticipantTrait;
	use ConfigTrait;

	public function __invoke(array $fields): void
	{
		if (
			!isset($fields['UF_TASK_WEBDAV_FILES'])
			|| !is_array($fields['UF_TASK_WEBDAV_FILES'])
		)
		{
			return;
		}

		$filesIds = array_filter($fields['UF_TASK_WEBDAV_FILES']);

		if (empty($filesIds))
		{
			return;
		}

		$this->addFilesRights($filesIds, $fields);
	}

	private function addFilesRights(array $filesIds, array $fields): void
	{
		$filesIds = array_unique(array_filter($filesIds));

		// Nothing to do?
		if (empty($filesIds))
		{
			return;
		}

		if (
			!Loader::includeModule('webdav')
			|| !Loader::includeModule('iblock')
		)
		{
			return;
		}

		$arRightsTasks = CWebDavIblock::GetTasks();

		$members = $this->getParticipants($fields);

		$ibe = new CIBlockElement();
		$dbWDFile = $ibe->GetList(
			[],
			[
				'ID' => $filesIds,
				'SHOW_NEW' => 'Y',
			],
			false,
			false,
			['ID', 'NAME', 'SECTION_ID', 'IBLOCK_ID', 'WF_NEW']
		);

		if (!$dbWDFile)
		{
			return;
		}

		$i = 0;
		$arRightsForTaskMembers = [];
		foreach ($members as $userId)
		{
			// For intranet users and their managers
			$arRightsForTaskMembers['n' . $i++] = [
				'GROUP_CODE' => 'IU' . $userId,
				'TASK_ID' => $arRightsTasks['R'],        // rights for reading
			];

			// For extranet users
			$arRightsForTaskMembers['n' . $i++] = [
				'GROUP_CODE' => 'U' . $userId,
				'TASK_ID' => $arRightsTasks['R'],        // rights for reading
			];
		}
		$iNext = $i;

		while ($arWDFile = $dbWDFile->Fetch())
		{
			if (!$arWDFile['IBLOCK_ID'])
			{
				continue;
			}

			$fileId = $arWDFile['ID'];

			if (!CIBlock::GetArrayByID($arWDFile['IBLOCK_ID'], "RIGHTS_MODE") === "E")
			{
				continue;
			}
			$ibRights = new CIBlockElementRights($arWDFile['IBLOCK_ID'], $fileId);
			$arCurRightsRaw = $ibRights->getRights();

			// Preserve existing rights
			$i = $iNext;
			$arRights = $arRightsForTaskMembers;
			foreach ($arCurRightsRaw as $arRightsData)
			{
				$arRights['n' . $i++] = [
					'GROUP_CODE' => $arRightsData['GROUP_CODE'],
					'TASK_ID' => $arRightsData['TASK_ID'],
				];
			}

			$ibRights->setRights($arRights);
		}
	}
}