<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\MainTool;

use Bitrix\Intranet\Internal\Access\MainTool\AccessPolicy;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\SiteTable;

class DisableForcedMainToolCommand extends AbstractCommand
{
	public function __construct(
		public readonly string $siteId,
	)
	{
	}

	protected function beforeRun(): ?Result
	{
		if (!(new AccessPolicy())->canManageAll())
		{
			return $this->createError(new AccessDeniedException(Loc::getMessage('MAIN_TOOL_ACCESS_DENIED') ?? ''));
		}

		if ($this->siteId === '')
		{
			return $this->createError(new ArgumentException(Loc::getMessage('MAIN_TOOL_INVALID_ARGUMENT') ?? ''));
		}

		$site = SiteTable::getById($this->siteId)->fetch();

		if (!$site || $site['LID'] !== $this->siteId)
		{
			return $this->createError(new ArgumentException(Loc::getMessage('MAIN_TOOL_INVALID_ARGUMENT') ?? ''));
		}

		return null;
	}

	protected function execute(): Result
	{
		return (new DisableForcedMainToolHandler())($this);
	}

	private function createError(\Throwable $exception): Result
	{
		return (new Result())->addError(Error::createFromThrowable($exception));
	}
}
