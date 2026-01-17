<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Provider\DiskFileProvider;

#[Recipients(creator: false, responsible: true, accomplices: true, auditors: false)]
class NotifyFilesAdded extends AbstractNotify implements ShouldSend
{
	public function __construct(
		private readonly Entity\Task $task,
		protected readonly ?Entity\User $triggeredBy = null,
		protected readonly ?array $fileIds = null,
	) {
	}

	public function getMessageCode(): string
	{
		$filesCount = $this->fileIds ? count($this->fileIds) : 1;
		$gender = $this->triggeredBy?->getGender();

		if ($filesCount === 1)
		{
			return match ($gender) {
				Entity\User\Gender::Female => 'TASKS_IM_TASK_FILE_ADDED_F',
				default => 'TASKS_IM_TASK_FILE_ADDED_M',
			};
		}

		return match ($gender) {
			Entity\User\Gender::Female => 'TASKS_IM_TASK_FILES_ADDED_F',
			default => 'TASKS_IM_TASK_FILES_ADDED_M',
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

		if (!empty($this->fileIds) && Loader::includeModule('disk'))
		{
			$diskFileProvider = Container::getInstance()->get(DiskFileProvider::class);
			$files = $diskFileProvider->getObjectsByIds($this->fileIds);

			$fileNames = array_map(static fn($file) => $file['name'], $files->toArray());

			if (!empty($fileNames))
			{
				$attach->AddMessage('[b]' . Loc::getMessage('TASKS_IM_NOTIFY_ATTACH_FILE') . '[/b][br]');
				$attach->AddMessage(implode('[br]', $fileNames));

				return $attach;
			}
		}

		return null;
	}
}
