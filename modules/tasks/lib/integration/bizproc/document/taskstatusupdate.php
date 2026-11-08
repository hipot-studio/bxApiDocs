<?php

namespace Bitrix\Tasks\Integration\Bizproc\Document;

final class TaskStatusUpdate extends Task
{
	public static function updateDocument($documentId, $fields, $modifiedById = null)
	{
		if (count($fields) !== 1 || !array_key_exists('STATUS', $fields))
		{
			return false;
		}

		return parent::updateDocumentStatus($documentId, (int)$fields['STATUS'], $modifiedById);
	}
}
