<?php

namespace Bitrix\Bizproc\Internal\Repository\WorkflowTemplate;

use Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile;
use Bitrix\Bizproc\Internal\Model\EO_WorkflowTemplateFile_Collection;
use Bitrix\Bizproc\Internal\Model\WorkflowTemplateFileTable;
use Bitrix\Main\Result;

class FileRepository
{
	public function syncByTemplateId(int $templateId, array $newFileIds = []): Result
	{
		$currentFiles = $this->listByTemplateId($templateId);
		$saved = [];
		foreach ($currentFiles as $model)
		{
			$fileId = $model->getFileId();
			if (in_array($fileId, $newFileIds, true))
			{
				$saved[] = $fileId;

				continue;
			}

			$this->removeFileWithModuleCheck($fileId);
			$this->remove($model->getId());
		}

		$notSaved = array_diff($newFileIds, $saved);

		return $this->add($templateId, $notSaved);
	}

	public function add(int $templateId, array $fileIds): Result
	{
		if (empty($fileIds))
		{
			return new Result();
		}

		$collection = new EO_WorkflowTemplateFile_Collection();
		foreach ($fileIds as $fileId)
		{
			$collection->add(
				(new EO_WorkflowTemplateFile())
				->setTemplateId($templateId)
				->setFileId($fileId)
			);
		}

		return $collection->save(true);
	}

	private function remove(int $id): Result
	{
		return WorkflowTemplateFileTable::delete($id);
	}

	private function listByTemplateId(int $templateId, int $limit = 1000): EO_WorkflowTemplateFile_Collection
	{
		return WorkflowTemplateFileTable::query()
			->where('TEMPLATE_ID', $templateId)
			->setSelect(['ID', 'FILE_ID'])
			->setLimit($limit)
			->fetchCollection()
		;
	}

	private function removeFileWithModuleCheck(int $fileId): void
	{
		$fileArray = \CFile::GetByID($fileId)->fetch();
		if ($fileArray && $fileArray['MODULE_ID'] === 'bizproc')
		{
			\CFile::Delete($fileId);
		}
	}
}