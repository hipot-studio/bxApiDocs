<?php
namespace Bitrix\Bizproc\Copy\Implement;

use Bitrix\Bizproc\Public\ProcessData\Service\ProcessDataMechanicsResolver;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class WorkflowTemplate
{
	const WORKFLOW_TEMPLATE_COPY_ERROR = 'WORKFLOW_TEMPLATE_COPY_ERROR';

	protected $targetDocumentType = [];
	protected $mapStatusIdsCopiedDocument = [];

	protected $result;

	public function __construct($targetDocumentType = [], $mapStatusIdsCopiedDocument = [])
	{
		$this->targetDocumentType = $targetDocumentType;
		$this->mapStatusIdsCopiedDocument = $mapStatusIdsCopiedDocument;

		$this->result = new Result();
	}

	public function getFields($workflowTemplateId)
	{
		$queryResult = \CBPWorkflowTemplateLoader::getList([], ['ID' => $workflowTemplateId]);
		return (($fields = $queryResult->fetch()) ? $fields : []);
	}

	public function prepareFieldsToCopy(array $fields)
	{
		$sourceTemplateId = (int)($fields['ID'] ?? 0);

		if (isset($fields['ID']))
		{
			unset($fields['ID']);
		}

		$settingsToCopy = (new ProcessDataMechanicsResolver())->settingsToCopy($fields, $sourceTemplateId);
		if ($settingsToCopy)
		{
			$fields['TEMPLATE_SETTINGS'] = $settingsToCopy;
		}

		if ($this->targetDocumentType)
		{
			$fields['DOCUMENT_TYPE'] = $this->targetDocumentType;
		}

		if (array_key_exists($fields['DOCUMENT_STATUS'], $this->mapStatusIdsCopiedDocument))
		{
			$fields['DOCUMENT_STATUS'] = $this->mapStatusIdsCopiedDocument[$fields['DOCUMENT_STATUS']];
		}

		return $fields;
	}

	public function add(array $fields)
	{
		$result = false;

		if ($fields)
		{
			// a copy inherits neither the date of the first publication of the original nor one of its own
			$result = \CBPWorkflowTemplateLoader::add($fields, true, markFirstPublication: false);
		}

		if (!$result)
		{
			$this->result->addError(
				new Error('Failed to copy workflow template', self::WORKFLOW_TEMPLATE_COPY_ERROR)
			);
		}

		return $result;
	}
}
