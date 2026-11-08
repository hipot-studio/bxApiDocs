<?php

namespace Bitrix\BizprocMobile\Workflow\Task;

use Bitrix\Bizproc\FieldType;
use Bitrix\BizprocMobile\EntityEditor\Converter;
use Bitrix\Main\Loader;
use Bitrix\UI\FileUploader\PendingFile;

Loader::requireModule('ui');
Loader::requireModule('bizproc');

class Fields
{
	/** @var PendingFile[] */
	private array $pendingFiles = [];
	private int $taskId;
	private ?Converter $converter = null;

	public function __construct(int $taskId)
	{
		$this->taskId = $taskId;
	}

	public function extract(array $fields, int $userId = 0): array
	{
		$task = \CBPTaskService::getList(
			arFilter: ['ID' => $this->taskId],
			arSelectFields: ['ID', 'ACTIVITY', 'PARAMETERS', 'STATUS']
		)->fetch();

		if ($task && (int)$task['STATUS'] === \CBPTaskStatus::Running)
		{
			$taskFields = \CBPDocument::getTaskControls($task, $userId)['FIELDS'] ?? [];
			$documentId =
				is_array($task['PARAMETERS']['DOCUMENT_ID'] ?? null)
					? $task['PARAMETERS']['DOCUMENT_ID']
					: null
			;

			$this->converter =
				(new Converter($taskFields, $documentId, $fields))
					->setContext(Converter::CONTEXT_TASK, ['taskId' => $this->taskId])
					->beginAttempt($userId)
			;

			$this->converter->toWeb();
			$this->pendingFiles = $this->converter->getPendingFiles();
			$fields = $this->converter->getConvertedValues();
		}

		return $fields;
	}

	public function compensateAttempt(): \Bitrix\Main\Result
	{
		return $this->converter?->compensateAttempt() ?? new \Bitrix\Main\Result();
	}

	public function savePendingFiles(): void
	{
		$this->converter?->acceptAttempt();
		$this->converter?->completeAttempt();
		$this->pendingFiles = [];
	}
}
