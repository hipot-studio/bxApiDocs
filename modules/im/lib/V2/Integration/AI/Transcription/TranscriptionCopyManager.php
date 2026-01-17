<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\Transcription;

use Bitrix\Im\Model\FileTranscriptionTable;
use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Im\V2\Entity\File\Param\ParamName;
use Bitrix\Im\V2\Entity\File\ParamCollection;
use Bitrix\Im\V2\Message\Forward\FileCopyMap;

class TranscriptionCopyManager
{
	private FileCopyMap $fileCopyMap;

	public function __construct(FileCopyMap $fileCopyMap)
	{
		$this->fileCopyMap = $fileCopyMap;
	}

	public function copy(): void
	{
		$originalFileMap = $this->buildOriginalFileMap();
		if (empty($originalFileMap))
		{
			return;
		}

		$transcriptions = $this->getTranscriptionsByFileIds(array_keys($originalFileMap));

		$insertFields = $this->prepareInsertFields($transcriptions, $originalFileMap);
		if (empty($insertFields))
		{
			return;
		}

		FileTranscriptionTable::multiplyInsertWithoutDuplicate(
			$insertFields,
			['DEADLOCK_SAFE' => true, 'UNIQUE_FIELDS' => ['FILE_ID']]
		);
	}

	private function buildOriginalFileMap(): array
	{
		$originalFileMap = [];
		ParamCollection::loadByFileIds($this->fileCopyMap->getOldFileIds());

		foreach ($this->fileCopyMap->getFileMap() as $copyFileId => $oldFileId)
		{
			$fileParams = ParamCollection::getInstance($oldFileId);

			if($fileParams->getParam(ParamName::IsTranscribable) !== null)
			{
				$oldFile = $this->fileCopyMap->getFiles()->offsetGet($oldFileId);
				$copyFile = $this->fileCopyMap->getFiles()->offsetGet($copyFileId);

				if ($oldFile instanceof FileItem && $copyFile instanceof FileItem)
				{
					$originalFileMap[$oldFile->getOriginalFileId()][] = $copyFile->getOriginalFileId();
				}
			}
		}

		return $originalFileMap;
	}

	private function getTranscriptionsByFileIds(array $fileIds): array
	{
		return FileTranscriptionTable::query()
			->setSelect(['FILE_ID', 'TEXT'])
			->whereIn('FILE_ID', $fileIds)
			->fetchAll();
	}

	private function prepareInsertFields(array $transcriptions, array $originalFileMap): array
	{
		$insertFields = [];

		foreach ($transcriptions as $transcription)
		{
			$copyFileIds = $originalFileMap[(int)$transcription['FILE_ID']] ?? [];

			foreach ($copyFileIds as $fileId)
			{
				$transcription['FILE_ID'] = $fileId;
				$insertFields[] = $transcription;
			}
		}

		return $insertFields;
	}
}
