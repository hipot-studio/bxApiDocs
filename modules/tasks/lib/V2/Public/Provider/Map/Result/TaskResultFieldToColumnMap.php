<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Map\Result;

class TaskResultFieldToColumnMap
{
	public const RELATIONS = [
		'id' => 'ID',
		'taskId' => 'TASK_ID',
		'text' => 'TEXT',
		'authorId' => 'CREATED_BY',
		'createdAt' => 'CREATED_AT',
		'updatedAt' => 'UPDATED_AT',
		'status' => 'STATUS',
		'fileIds' => 'UF_RESULT_FILES',
		'messageId' => [
			'MESSAGE_ID' => 'MESSAGE.MESSAGE_ID',
		],
	];
}
