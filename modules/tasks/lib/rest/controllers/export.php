<?php

namespace Bitrix\Tasks\Rest\Controllers;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Controller;
use CTempFile;

class Export extends Controller\Export
{
	protected $module = 'tasks';

	protected ?string $entityType = null;

	/**
	 * Initializes controller.
	 */
	protected function init(): void
	{
		$this->keepFieldInProcess('entityType');

		$entityType = $this->request->get('ENTITY_TYPE');
		$this->entityType = is_string($entityType) ? $entityType : null;

		parent::init();
	}

	/**
	 * Returns file name
	 */
	protected function generateExportFileName(): string
	{
		$date = (new DateTime())->format('Y-m-d_H-i-s');

		return 'tasks_' . $date . '.xls';
	}

	/**
	 * Returns temporally directory
	 */
	protected function generateTempDirPath(): string
	{
		$tempDir = CTempFile::GetDirectoryName(
			self::KEEP_FILE_HOURS,
			[
				$this->module,
				uniqid((string)$this->entityType. '_export_', true)
			]
		);

		CheckDirPath($tempDir);

		return $tempDir;
	}

	/**
	 * Generate link to download local exported temporally file.
	 */
	protected function generateDownloadLink(): string
	{
		$params = [
			'PROCESS_TOKEN' => $this->processToken,
			'EXPORT_TYPE' => $this->exportType,
			'COMPONENT_NAME' => $this->componentName,
			'ENTITY_TYPE' => $this->entityType,
		];

		return $this->getActionUri(self::ACTION_DOWNLOAD, $params);
	}
}
