<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\MainTool;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class InitialShowService
{
	public function __construct(
		private Repository $repository,
		private AvailabilityProvider $availabilityProvider,
		private AccessPolicy $accessPolicy,
	)
	{
	}

	public function confirm(string $siteId, int $userId): Result
	{
		if (!$this->accessPolicy::isFeatureEnabled())
		{
			return $this->featureDisabled();
		}
		if (!$this->accessPolicy->canUsePicker($userId))
		{
			return $this->failure('MAIN_TOOL_ACCESS_DENIED');
		}
		if ($siteId === '' || strlen($siteId) > 2)
		{
			return $this->failure('MAIN_TOOL_INVALID_ARGUMENT');
		}

		try
		{
			$snapshot = $this->repository->readSnapshot($siteId, $userId);
			if ($snapshot['personal']['shown'])
			{
				return (new Result())->setData(['shown' => true]);
			}
			if ($snapshot['common']['forced'] && !$this->accessPolicy->canManageAll($userId))
			{
				return $this->failure('MAIN_TOOL_FORCED');
			}
			if ($this->availabilityProvider->getAvailablePresets($siteId, $userId) === [])
			{
				return $this->failure('MAIN_TOOL_UNAVAILABLE');
			}
			if (!$this->accessPolicy::isFeatureEnabled())
			{
				return $this->featureDisabled();
			}
			if (!$this->repository->saveInitialShow($siteId, $userId))
			{
				return $this->failure('MAIN_TOOL_SAVE_FAILED');
			}

			return (new Result())->setData(['shown' => true]);
		}
		catch (ArgumentException)
		{
			return $this->failure('MAIN_TOOL_INVALID_ARGUMENT');
		}
		catch (\Throwable)
		{
			return $this->failure('MAIN_TOOL_SAVE_FAILED');
		}
	}

	private function featureDisabled(): Result
	{
		return (new Result())->addError(new Error('', 'MAIN_TOOL_FEATURE_DISABLED', [
			'isMainToolPickerEnabled' => false,
		]));
	}

	private function failure(string $code): Result
	{
		return (new Result())->addError(new Error('', $code));
	}
}
