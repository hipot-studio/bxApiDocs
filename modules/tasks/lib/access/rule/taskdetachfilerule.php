<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Access\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\Model\TaskModel;
use Bitrix\Tasks\Access\TaskAccessController;

/**
 * @property TaskAccessController $controller
 */
class TaskDetachFileRule extends AbstractRule
{
	public function execute(AccessibleItem $item = null, $params = null): bool
	{
		if (!$item instanceof TaskModel)
		{
			$this->controller->addError(static::class, 'Incorrect task');

			return false;
		}

		if ($this->controller->check(ActionDictionary::ACTION_TASK_EDIT, $item, $params))
		{
			return true;
		}

		if (
			!is_array($params)
			|| !isset($params['attachments'])
			|| !is_array($params['attachments'])
			|| !isset($params['attachmentsCreatedByMap'])
			|| !is_array($params['attachmentsCreatedByMap'])
		)
		{
			$this->controller->addError(static::class, 'Incorrect parameters');

			return false;
		}

		if (!$item->isMember($this->user->getUserId()))
		{
			return $this->denyIncorrectAttachmentData();
		}

		$attachments = $params['attachments'];
		$attachmentsCreatedByMap = $params['attachmentsCreatedByMap'];
		$attachmentIds = [];

		foreach ($attachments as $attachment)
		{
			if (!is_array($attachment) || !isset($attachment['id']))
			{
				return $this->denyIncorrectAttachmentData();
			}

			$attachmentId = $attachment['id'];
			if (
				(!is_int($attachmentId) && !is_string($attachmentId))
				|| $attachmentId === ''
				|| (string)$attachmentId === '0'
			)
			{
				return $this->denyIncorrectAttachmentData();
			}

			$attachmentIds[(string)$attachmentId] = true;
		}

		$attachmentCreatedByIds = [];
		foreach (array_keys($attachmentsCreatedByMap) as $attachmentId)
		{
			$attachmentCreatedByIds[(string)$attachmentId] = true;
		}

		if (
			$attachmentIds === []
			|| count($attachmentIds) !== count($attachments)
			|| array_diff_key($attachmentIds, $attachmentCreatedByIds) !== []
			|| array_diff_key($attachmentCreatedByIds, $attachmentIds) !== []
		)
		{
			return $this->denyIncorrectAttachmentData();
		}

		foreach ($attachments as $attachment)
		{
			$attachmentId = $attachment['id'];

			$isAttachmentCreator = $attachmentId
				&& isset($attachmentsCreatedByMap[$attachmentId])
				&& (int)$attachmentsCreatedByMap[$attachmentId] === $this->user->getUserId()
			;

			$isFileOwner = is_array($attachment)
				&& isset($attachment['owner']['id'])
				&& (int)$attachment['owner']['id'] === $this->user->getUserId()
			;

			if (!$isAttachmentCreator && !$isFileOwner)
			{
				return $this->denyIncorrectAttachmentData();
			}
		}

		return true;
	}

	private function denyIncorrectAttachmentData(): bool
	{
		$this->controller->addError(static::class, 'Incorrect attachment data');

		$this->controller->addUserError(new Error(Loc::getMessage('TASKS_TASK_DETACH_FILE_RULE_NO_PERMISSIONS')));

		return false;
	}
}
