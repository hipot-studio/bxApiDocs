<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\MainTool;

use Bitrix\Intranet\Composite\CacheProvider;
use Bitrix\Intranet\Internal\Access\MainTool\AccessPolicy;
use Bitrix\Intranet\Internal\Repository\MenuSettingsRepository;
use Bitrix\Intranet\Internal\Service\MainTool\AvailabilityService;
use Bitrix\Intranet\Portal\FirstPage;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\Error;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class ApplySelectionHandler
{
	public function __construct(
		private MenuSettingsRepository $repository = new MenuSettingsRepository(),
		private AvailabilityService $availabilityService = new AvailabilityService(),
		private AccessPolicy $accessPolicy = new AccessPolicy(),
	)
	{
	}

	public function __invoke(ApplySelectionCommand $command): Result
	{
		$siteId = $command->siteId;
		$userId = (int)CurrentUser::get()->getId();

		try
		{
			$firstPage = $this->availabilityService->getFirstPage(
				$siteId,
				$userId,
				$command->presetId,
				$command->applyForAll,
			);

			if (!$this->isValidFirstPage($firstPage))
			{
				return $this->failure('MAIN_TOOL_UNAVAILABLE');
			}

			$isForced = false;

			if (!$command->applyForAll)
			{
				$isForced = $this->repository->isForced($siteId);

				if ($isForced && !$this->accessPolicy->canManageAll())
				{
					return $this->failure('MAIN_TOOL_FORCED');
				}
			}

			if ($command->applyForAll)
			{
				$this->repository->saveCommonSelection($siteId, $command->presetId, $firstPage);
				$this->repository->setForced($siteId, true);
			}
			else
			{
				if (!$this->repository->savePersonalSelection($siteId, $userId, $command->presetId, $firstPage))
				{
					return $this->failure('MAIN_TOOL_SAVE_FAILED');
				}

				if ($isForced)
				{
					$this->repository->setForced($siteId, false);
				}
			}

			$clearAllFirstPages = $command->applyForAll || $isForced;
			$result = (new Result())->setData([
				'url' => str_replace('#USER_ID#', (string)$userId, $firstPage),
				'clearAllFirstPages' => $clearAllFirstPages,
			]);
		}
		catch (ArgumentException)
		{
			return $this->failure('MAIN_TOOL_INVALID_ARGUMENT');
		}
		catch (\Throwable)
		{
			return $this->failure('MAIN_TOOL_SAVE_FAILED');
		}

		$this->onSelectionApplied($command, $clearAllFirstPages, $userId);

		return $result;
	}

	private function onSelectionApplied(ApplySelectionCommand $command, bool $clearAllFirstPages, int $userId): void
	{
		if ($clearAllFirstPages)
		{
			FirstPage::getInstance()->clearCacheForAll();
		}
		else
		{
			FirstPage::getInstance()->clearCache();
		}

		CacheProvider::deleteUserCache($userId);

		if ($command->applyForAll)
		{
			$event = new Event('intranet', 'onAfterChangeLeftMenuPreset', [
				'SITE_ID' => $command->siteId,
				'VALUE' => $command->presetId,
			]);
			EventManager::getInstance()->send($event);
		}
	}

	private function isValidFirstPage(string $firstPage): bool
	{
		return
			str_starts_with($firstPage, '/')
			&& !str_starts_with($firstPage, '//')
			&& !str_contains($firstPage, '\\')
			&& preg_match('/[\x00-\x20\x7f]/', $firstPage) !== 1
		;
	}

	private function failure(string $code): Result
	{
		return (new Result())->addError(new Error(Loc::getMessage($code) ?? '', $code));
	}
}
