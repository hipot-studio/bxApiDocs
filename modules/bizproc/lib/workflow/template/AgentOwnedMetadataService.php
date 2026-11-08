<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Result;

final class AgentOwnedMetadataService
{
	/** @param list<string> $fieldsToRelease */
	public function release(int $templateId, array $fieldsToRelease): Result
	{
		$result = new Result();
		$fields = AgentOwnedMetadata::onlyKnownFields($fieldsToRelease);
		if ($templateId <= 0 || $fields === [])
		{
			return $result;
		}

		$draft = WorkflowTemplateDraftTable::getLatestDraftWithDataByTemplateId($templateId);
		if ($draft === null)
		{
			return $result;
		}

		$templateData = is_array($draft['TEMPLATE_DATA']) ? $draft['TEMPLATE_DATA'] : [];
		$ownedFields = AgentOwnedMetadata::readFrom($templateData);
		$remainingFields = array_values(array_diff($ownedFields, $fields));
		if ($remainingFields === $ownedFields)
		{
			return $result;
		}

		$draftId = (int)$draft['ID'];
		$nextRevision = (int)$draft['REVISION'] + 1;
		$affected = WorkflowTemplateDraftTable::updateByFilter(
			[
				'=ID' => $draftId,
				'=REVISION' => (int)$draft['REVISION'],
			],
			[
				'TEMPLATE_DATA' => WorkflowTemplateTable::toSerializedForm(
					AgentOwnedMetadata::applyTo($templateData, $remainingFields),
				),
				'REVISION' => $nextRevision,
			],
		);

		if ($affected === 0)
		{
			$result->addError(ErrorMessage::DRAFT_REVISION_CONFLICT->getCodedError());
		}
		else
		{
			$result->setData([
				'templateDraftId' => $draftId,
				'draftRevision' => $nextRevision,
			]);
		}

		return $result;
	}
}
