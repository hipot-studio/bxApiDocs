<?php

namespace Bitrix\Lists\Controller;

use Bitrix\Main;

class Export extends Main\Controller\Export
{
	protected $module = 'lists';

	protected function generateExportFileName()
	{
		$fileExt = $this->exportType === self::EXPORT_TYPE_CSV ? 'csv' : 'xls';
		$prefix = 'list_' . date('Ymd');
		$hash = str_pad(dechex(crc32($prefix)), 8, '0', STR_PAD_LEFT);

		return uniqid($prefix . '_' . $hash . '_', false) . '.' . $fileExt;
	}

	protected function generateTempDirPath()
	{
		$tempDir = \CTempFile::GetDirectoryName(
			self::KEEP_FILE_HOURS,
			[
				$this->module,
				uniqid('list_export_', true),
			]
		);

		\CheckDirPath($tempDir);

		return $tempDir;
	}
}
