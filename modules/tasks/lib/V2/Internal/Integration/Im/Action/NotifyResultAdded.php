<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Provider\DiskFileProvider;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: true, responsible: true, accomplices: true, auditors: false)]
class NotifyResultAdded extends AbstractNotify
{
	public function __construct(
		private readonly Entity\Task $task,
		MessageSenderInterface $sender,
		protected readonly ?Entity\User $triggeredBy = null,
		private readonly string $resultText = '',
		private readonly int $dateTs = 0,
		private readonly array $fileIds = [],

	)
	{
		$sender->sendMessage(task: $task, notification: $this);
	}

	public function getMessageCode(): string
	{
		return $this->triggeredBy?->getGender() === Entity\User\Gender::Female
			? 'TASKS_IM_RESULT_ADDED_MSGVER_1_F'
			: 'TASKS_IM_RESULT_ADDED_MSGVER_1_M'
		;
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#DATE#' => "[TIMESTAMP=$this->dateTs FORMAT=LONG_DATE_FORMAT]",
		];
	}

	public function getAttach(): ?\CIMMessageParamAttach
	{
		$attach = new \CIMMessageParamAttach();

		$resultText = $this->prepareResultText();
		if (empty($resultText) && empty($this->fileIds))
		{
			return null;
		}

		$attach->AddMessage('[b]' . Loc::getMessage('TASKS_IM_NOTIFY_ATTACH_RESULT_TEXT') . '[/b][br]');
		$attach->AddMessage($resultText);

		if (!empty($this->fileIds) && Loader::includeModule('disk'))
		{
			$diskFileProvider = Container::getInstance()->get(DiskFileProvider::class);
			$files = $diskFileProvider->getObjectsByIds($this->fileIds);

			$fileNames = array_map(static fn($file) => $file['name'], $files->toArray());

			if (!empty($fileNames))
			{
				$attach->AddDelimiter(['SIZE' => 400]);
				$attach->AddMessage('[b]' . Loc::getMessage('TASKS_IM_NOTIFY_ATTACH_FILE') . '[/b][br]');
				$attach->AddMessage(implode('[br]', $fileNames));
			}
		}

		return $attach;
	}

	private function prepareResultText(): string
	{
		if ($this->resultText === '')
		{
			return '';
		}

		$resultText = htmlspecialchars_decode(htmlspecialcharsback($this->resultText), ENT_QUOTES);
		$resultText = trim(\CTextParser::clearAllTags($resultText));
		$resultText = str_replace(
			["&#91;", "&#93;"],
			["[", "]"],
			$resultText,
		);

		return preg_replace('/\n{3,}/', "\n\n", $resultText);
	}
}
