<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Base\TaskStep;
use Bitrix\Landing\Metrika;
use Bitrix\Landing\Rights;
use Bitrix\Landing\Site;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class TaskPublishAiSite extends TaskStep
{
	private const DEFAULT_WARNING = 'The site was created, but publication failed.';

	public function execute(): bool
	{
		parent::execute();

		$siteId = (int)($this->siteData->getSiteId() ?? 0);
		if ($siteId <= 0)
		{
			CreateAiSiteState::setPublication($this->generation, [
				'attempted' => false,
				'published' => false,
				'warning' => null,
				'errorCode' => null,
			]);
			$this->changed = true;

			return true;
		}

		$result = $this->runWithGlobalRightsOff(
			fn(): Result => $this->publishSite($siteId, new Metrika\FieldsDto(
				type: Metrika\Types::ai,
			)),
		);
		$error = $result->getError();

		CreateAiSiteState::setPublication($this->generation, [
			'attempted' => true,
			'published' => $result->isSuccess(),
			'warning' => $result->isSuccess() ? null : $this->resolveWarning($error),
			'errorCode' => $result->isSuccess() ? null : $this->resolveErrorCode($error),
		]);
		$this->changed = true;

		return true;
	}

	protected function publishSite(int $siteId, Metrika\FieldsDto $metrikaParams): Result
	{
		return Site::publication($siteId, true, $metrikaParams);
	}

	private function runWithGlobalRightsOff(callable $callback): mixed
	{
		$rightsBefore = Rights::isOn();
		Rights::setGlobalOff();
		try
		{
			return $callback();
		}
		finally
		{
			if ($rightsBefore)
			{
				Rights::setGlobalOn();
			}
			else
			{
				Rights::setGlobalOff();
			}
		}
	}

	private function resolveWarning(?Error $error): string
	{
		$message = trim((string)($error?->getMessage() ?? ''));

		return $message !== '' ? $message : self::DEFAULT_WARNING;
	}

	private function resolveErrorCode(?Error $error): ?string
	{
		$code = trim((string)($error?->getCode() ?? ''));

		return $code !== '' ? $code : null;
	}
}
