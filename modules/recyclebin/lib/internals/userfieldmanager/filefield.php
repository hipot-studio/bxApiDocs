<?php

namespace Bitrix\Recyclebin\Internals\UserFieldManager;

use Bitrix\Main\UserField\File\ManualUploadRegistry;

class FileField extends BaseField
{
	public function onEraseFromRecycleBin(&$value): void
	{
		if (is_array($value))
		{
			foreach ($value as $valueItem)
			{
				\CFile::Delete($valueItem);
			}
		}
		else
		{
			\CFile::Delete($value);
		}

		$value = null;
	}

	public function onMoveToRecycleBin(&$value): void
	{
		$this->generateValueClone($value);
	}

	public function onRestoreFromRecycleBin(&$value): void
	{
		$values = is_array($value) ? $value : [$value];

		$uf = \CUserTypeEntity::GetList([], [
			'FIELD_NAME' => $this->userField['FIELD_NAME'],
			'ENTITY_ID' => $this->userField['ENTITY_ID'],
		])->Fetch();

		if (!$uf) // user field was deleted, so need to remove garbage files
		{
			foreach ($values as $fileId)
			{
				\CFile::Delete($fileId);
			}
		}

		$registry = ManualUploadRegistry::getInstance();
		foreach ($values as $fileId)
		{
			$registry->registerFile($uf, $fileId); // mark files as manually uploaded to prevent their deletion in onBeforeSave
		}
	}

	private function generateValueClone(&$value): void
	{
		if (!isset($value) || !$value)
		{
			return;
		}

		if (is_array($value))
		{
			$values = [];
			foreach ($value as $fileId)
			{
				$values[] = \CFile::CloneFile((int)$fileId);
			}
			$value = $values;
		}
		else
		{
			$value = \CFile::CloneFile((int)$value);
		}
	}
}
