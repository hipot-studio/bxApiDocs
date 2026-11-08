<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\MainTool;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class SelectionService
{
	public function __construct(
		private Repository $repository,
		private AvailabilityProvider $availabilityProvider,
		private AccessPolicy $accessPolicy,
	)
	{
	}

	/** @param array{presetId:string, applyForAll:bool} $request */
	public function apply(string $siteId, int $userId, array $request): Result
	{
		if (!$this->accessPolicy::isFeatureEnabled())
		{
			return $this->featureDisabled();
		}
		if (!$this->accessPolicy->canUsePicker($userId))
		{
			return $this->failure('MAIN_TOOL_ACCESS_DENIED');
		}
		if (
			$siteId === ''
			|| strlen($siteId) > 2
			|| !is_string($request['presetId'] ?? null)
			|| $request['presetId'] === ''
			|| strlen($request['presetId']) > 16
			|| !is_bool($request['applyForAll'] ?? null)
		)
		{
			return $this->failure('MAIN_TOOL_INVALID_ARGUMENT');
		}

		try
		{
			$canManageAll = $this->accessPolicy->canManageAll($userId);
			if ($request['applyForAll'] && !$canManageAll)
			{
				return $this->failure('MAIN_TOOL_ACCESS_DENIED');
			}

			$validation = $this->validatePreset($siteId, $userId, $request);
			if (!$validation->isSuccess())
			{
				return $validation;
			}
			if (!$this->accessPolicy::isFeatureEnabled())
			{
				return $this->featureDisabled();
			}

			$firstPage = $validation->getData()['firstPage'];
			$clearAllFirstPages = $request['applyForAll'];
			if ($request['applyForAll'])
			{
				$this->repository->saveCommonSelection($siteId, $request['presetId'], $firstPage);
				$this->repository->setForced($siteId, true);
			}
			else
			{
				$forced = $this->repository->isForced($siteId);
				$clearAllFirstPages = $forced;
				if ($forced && !$canManageAll)
				{
					return $this->failure('MAIN_TOOL_FORCED');
				}
				if (!$this->repository->savePersonalSelection($siteId, $userId, $request['presetId'], $firstPage))
				{
					return $this->failure('MAIN_TOOL_SAVE_FAILED');
				}
				if ($forced)
				{
					$this->repository->setForced($siteId, false);
				}
			}

			return (new Result())->setData([
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
	}

	private function validatePreset(string $siteId, int $userId, array $request): Result
	{
		$available = false;
		foreach ($this->availabilityProvider->getAvailablePresets($siteId, $userId) as $preset)
		{
			if ($preset['id'] === $request['presetId'] && (!$request['applyForAll'] || $preset['canApplyForAll']))
			{
				$available = true;

				break;
			}
		}
		if (!$available)
		{
			return $this->failure('MAIN_TOOL_UNAVAILABLE');
		}

		$firstPage = $this->availabilityProvider->getFirstPage(
			$siteId,
			$userId,
			$request['presetId'],
			$request['applyForAll'],
		);
		if (
			!str_starts_with($firstPage, '/')
			|| str_starts_with($firstPage, '//')
			|| str_contains($firstPage, '\\')
			|| strlen($firstPage) > 2048
			|| preg_match('/[\x00-\x20\x7f]/', $firstPage) === 1
		)
		{
			return $this->failure('MAIN_TOOL_UNAVAILABLE');
		}

		return (new Result())->setData(['firstPage' => $firstPage]);
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
