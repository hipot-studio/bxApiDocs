<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Folder;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Site;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;

class UpdateFolderReferences extends Blank
{
	public function action(): void
	{
		$ratio = $this->context->getRatio();
		$siteId = $this->context->getSiteId() ?? 0;
		$folderRefs = $ratio->get(RatioPart::FolderReferences) ?? [];
		$foldersNew = $ratio->get(RatioPart::FoldersNew) ?? [];
		$landings = $ratio->get(RatioPart::Landings) ?? [];
		if ($siteId <= 0)
		{
			return;
		}

		// move pages to the folders if needed (backward compatibility)
		if (!empty($folderRefs))
		{
			$res = Landing::getList([
				'select' => [
					'ID', 'FOLDER_ID',
				],
				'filter' => [
					'SITE_ID' => $siteId,
					'FOLDER_ID' => array_keys($folderRefs),
				],
			]);
			while ($row = $res->fetch())
			{
				Landing::update($row['ID'], [
					'FOLDER_ID' => $folderRefs[$row['FOLDER_ID']],
				]);
			}
		}

		if (!empty($foldersNew))
		{
			$this->addFolders($siteId, $foldersNew, $landings);
		}
	}

	/**
	 * Add folders and move pages to the folders.
	 * @param int $siteId Site id.
	 * @param array $foldersNew Folders' array to add.
	 * @param array $landingMapIds Landing's map from old to new ids.
	 * @return void
	 */
	private function addFolders(int $siteId, array $foldersNew, array $landingMapIds): void
	{
		$folderMapIds = [];
		foreach ($foldersNew as $folderId => $folder)
		{
			$indexId = null;

			if (!$folder['PARENT_ID'])
			{
				unset($folder['PARENT_ID']);
			}

			if ($folder['INDEX_ID'] ?? null)
			{
				$indexId = $landingMapIds[$folder['INDEX_ID']] ?? null;
				unset($folder['INDEX_ID']);
			}

			$res = Site::addFolder($siteId, $folder);
			if ($res->isSuccess())
			{
				if ($indexId)
				{
					$resLanding = Landing::update($indexId, [
						'FOLDER_ID' => $res->getId(),
					]);
					if ($resLanding->isSuccess())
					{
						Folder::update($res->getId(), [
							'INDEX_ID' => $indexId,
						]);
					}
				}
				$folderMapIds[$folderId] = $res->getId();
			}
		}

		$newFolders = Site::getFolders($siteId);
		foreach ($newFolders as $folder)
		{
			if ($folderMapIds[$folder['PARENT_ID']] ?? null)
			{
				Folder::update($folder['ID'], [
					'PARENT_ID' => $folderMapIds[$folder['PARENT_ID']],
				]);
			}
		}

		$this->updateFolderIds($siteId, $folderMapIds);
	}

	/**
	 * Updates added pages on new folder ids.
	 * @param int $siteId Site id.
	 * @param array $folderMapIds References between old and new folders.
	 * @return void
	 */
	private function updateFolderIds(int $siteId, array $folderMapIds): void
	{
		$res = Landing::getList([
			'select' => [
				'ID', 'FOLDER_ID',
			],
			'filter' => [
				'SITE_ID' => $siteId,
				'FOLDER_ID' => array_keys($folderMapIds),
			],
		]);
		while ($row = $res->fetch())
		{
			if (isset($folderMapIds[$row['FOLDER_ID']]))
			{
				Landing::update($row['ID'], [
					'FOLDER_ID' => $folderMapIds[$row['FOLDER_ID']],
				]);
			}
		}
	}
}
