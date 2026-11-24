<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\MainTool;

use Bitrix\Intranet\Internal\Access\MainTool\AccessPolicy;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Context;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class ApplySelectionCommand extends AbstractCommand
{
	public readonly string $siteId;

	public function __construct(
		public readonly string $presetId,
		public readonly bool $applyForAll,
	)
	{
		$this->siteId = (string)Context::getCurrent()->getSite();
	}

	protected function beforeRun(): ?Result
	{
		if ($this->siteId === '' || $this->presetId === '')
		{
			return (new Result())->addError(new Error(
				(string)Loc::getMessage('MAIN_TOOL_INVALID_ARGUMENT'),
				'MAIN_TOOL_INVALID_ARGUMENT',
			));
		}

		$accessPolicy = new AccessPolicy();
		if (!$accessPolicy->canUsePicker())
		{
			return $this->createError('MAIN_TOOL_ACCESS_DENIED');
		}

		try
		{
			if ($this->applyForAll && !$accessPolicy->canManageAll())
			{
				return $this->createError('MAIN_TOOL_ACCESS_DENIED');
			}
		}
		catch (\Throwable)
		{
			return $this->createError('MAIN_TOOL_SAVE_FAILED');
		}

		return null;
	}

	protected function execute(): Result
	{
		return (new ApplySelectionHandler())($this);
	}

	private function createError(string $code): Result
	{
		return (new Result())->addError(new Error(Loc::getMessage($code) ?? '', $code));
	}
}
