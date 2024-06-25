<?php

namespace Bitrix\Tasks\Flow\Integration\BizProc\Robot;

use Bitrix\Main\Loader;
use Bitrix\Bizproc\Automation\Engine\Template;
use Bitrix\Tasks\Flow\Integration\BizProc\DocumentTrait;

class RobotService
{
	use DocumentTrait;

	protected Template $template;

	protected int $userId;

	private bool $isAvailable;

	final public function __construct(int $stageId, int $projectId, int $userId)
	{
		if (!$this->isAvailable())
		{
			return;
		}

		$this->template = new Template($this->getDocumentType($projectId), $stageId);
		$this->userId = $userId;
	}

	public function add(RobotCommand ...$commands): void
	{
		if (!$this->isAvailable)
		{
			return;
		}

		$robots = [];
		foreach ($commands as $command)
		{
			$robots[] = $command->toArray();
		}

		if ([] !== $robots)
		{
			$this->template->save($robots, $this->userId);
		}
	}

	private function isAvailable(): bool
	{
		$this->isAvailable ??= Loader::includeModule('bizproc');
		return $this->isAvailable;
	}
}