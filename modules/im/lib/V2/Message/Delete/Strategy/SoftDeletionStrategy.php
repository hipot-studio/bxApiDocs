<?php

namespace Bitrix\Im\V2\Message\Delete\Strategy;

use Bitrix\Disk\SystemUser;
use Bitrix\Im\V2\Entity\File\FileCollection;
use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Localization\Loc;

class SoftDeletionStrategy extends DeletionStrategy
{
	protected ?FileCollection $files = null;

	protected function execute(): void
	{
		$result = $this->messages->save();
		$this->checkResult($result);
	}

	protected function onBeforeDelete(): void
	{
		$this->files = $this->messages->getFiles();

		foreach ($this->messages as $message)
		{
			$message->setMessage(Loc::getMessage('IM_MESSAGE_DELETED'));
			$message->setMessageOut($this->getMessageOut($message));
		}

		$this->messages->resetParams([
			'IS_DELETED' => 'Y',
		]);
	}

	protected function onAfterDelete(): void
	{
		if (!isset($this->files))
		{
			return;
		}

		foreach ($this->files as $file)
		{
			/**
			 * @var FileItem $file
			 */
			$file->getDiskFile()?->delete(SystemUser::SYSTEM_USER_ID);
		}
	}

	private function getMessageOut(Message $message): string
	{
		$date = $message->getDateCreate()?->toString();

		return Loc::getMessage('IM_MESSAGE_DELETED_OUT', ['#DATE#' => $date]) ?? '';
	}
}