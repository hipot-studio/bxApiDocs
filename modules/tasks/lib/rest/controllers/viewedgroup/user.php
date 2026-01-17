<?php

namespace Bitrix\Tasks\Rest\Controllers\ViewedGroup;

use Bitrix\Main\SystemException;
use Bitrix\Tasks\Comments;
use Bitrix\Tasks\Rest\Controllers\Task\Comment;

final class User extends Base
{
	protected const ITEM = 'USER';
	protected const LIST = 'USERS';

	protected const VIEWED_TYPE = 1;

	/**
	 * @param $fields
	 * @return bool|null
	 * @throws SystemException
	 */
	public function markAsReadAction($fields): ?bool
	{
		$fields['GROUP_ID'] = ($fields['GROUP_ID'] ?? null);
		$fields['ROLE'] = ($fields['ROLE'] ?? null);

		return $this->forward(
			new Comment(),
			'readAll',
			[
				'groupId' => $fields['GROUP_ID'],
				'userId' => $fields['USER_ID'],
				'role' => $fields['ROLE'],
			]
		);
	}
}
