<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Disk\File;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\V2\Internal\Entity;

#[Recipients(creator: false, responsible: false, accomplices: false, auditors: false)]
class NotifyFilesChanged extends AbstractNotify implements ShouldSend
{
	public function __construct(
		private readonly Entity\Task $task,
		protected readonly ?Entity\User $triggeredBy = null,
		protected readonly ?int $fileId = null,
	) {
	}

	public function getMessageCode(): string
	{
		$gender = $this->triggeredBy?->getGender();

		return match ($gender) {
			Entity\User\Gender::Female => 'TASKS_IM_TASK_FILE_MODIFIED_F',
			default => 'TASKS_IM_TASK_FILE_MODIFIED_M',
		};
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
		];
	}

	public function getAttach(): ?\CIMMessageParamAttach
	{
		$attach = new \CIMMessageParamAttach();

		if (!empty($this->fileId) && Loader::includeModule('disk'))
		{
			$file = File::getById($this->fileId);

			if ($file !== null)
			{
				$attach->AddMessage('[b]' . Loc::getMessage('TASKS_IM_NOTIFY_ATTACH_FILE') . '[/b][br]');
				$attach->AddMessage($file->getName());

				return $attach;
			}
		}

		return null;
	}
}
