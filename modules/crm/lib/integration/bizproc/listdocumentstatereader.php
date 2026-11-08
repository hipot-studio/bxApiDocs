<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc;

use Bitrix\Bizproc\Public\Provider\WorkflowStateBriefProvider;

/**
 * Workflow states of a page of documents for lists and exports: one read per page,
 * with brief states when bizproc provides them and the legacy full states otherwise.
 * The caller includes the bizproc module.
 */
class ListDocumentStateReader
{
	/**
	 * @param array $documentType [MODULE_ID, ENTITY, DOCUMENT_TYPE], e.g. ['crm', 'CCrmDocumentContact', 'CONTACT']
	 * @param string[] $documentIds e.g. ['CONTACT_15']
	 * @param bool $withEditAutostartTemplates append the pseudo states of templates started on edit,
	 * 	as CBPDocument::GetDocumentStates does for a single document
	 * @return array<string, array<string|int, array>> states by document id, then by workflow id
	 * 	(template id for pseudo states); documents without states are absent.
	 * 	Guaranteed keys of a state: ID, TEMPLATE_ID, TEMPLATE_NAME, STATE_NAME, STATE_TITLE.
	 * 	Other keys depend on the path: DOCUMENT_ID is a string on the brief path and an array
	 * 	[MODULE_ID, ENTITY, ID] on the legacy one, so callers must not rely on its format.
	 */
	public function read(array $documentType, array $documentIds, bool $withEditAutostartTemplates = false): array
	{
		if (empty($documentIds))
		{
			return [];
		}

		$states =
			$this->isBriefProviderAvailable()
				? $this->readBrief($documentType, $documentIds)
				: $this->readLegacy($documentType, $documentIds)
		;

		if ($withEditAutostartTemplates)
		{
			$templateStates = \CBPWorkflowTemplateLoader::getDocumentTypeStates(
				$documentType,
				\CBPDocumentEventType::Edit,
			);

			foreach ($documentIds as $documentId)
			{
				if (isset($states[$documentId]))
				{
					$states[$documentId] += $templateStates;
				}
				elseif (!empty($templateStates))
				{
					// one shared copy-on-write array instead of a table per document
					$states[$documentId] = $templateStates;
				}
			}
		}

		return $states;
	}

	protected function isBriefProviderAvailable(): bool
	{
		return class_exists(WorkflowStateBriefProvider::class);
	}

	private function readBrief(array $documentType, array $documentIds): array
	{
		return (new WorkflowStateBriefProvider())->getByDocuments(
			(string)$documentType[0],
			(string)$documentType[1],
			$documentIds,
		);
	}

	private function readLegacy(array $documentType, array $documentIds): array
	{
		$documentStates = \CBPDocument::GetDocumentStates(
			$documentType,
			[$documentType[0], $documentType[1], $documentIds],
		);

		$states = [];
		foreach ($documentStates as $stateId => $documentState)
		{
			if (isset($documentState['DOCUMENT_ID']))
			{
				$states[$documentState['DOCUMENT_ID'][2]][$stateId] = $documentState;
			}
		}

		return $states;
	}
}
