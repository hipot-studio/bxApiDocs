<?php

declare(strict_types=1);

namespace Bitrix\IntranetMobile\Qualification;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Mobile\Tab;

final class MobilePresetApplier
{
	public const ERROR_UNKNOWN_PRESET = 'unknown_preset';
	public const ERROR_MOBILE_PRESET_UNAVAILABLE = 'mobile_preset_unavailable';
	public const ERROR_MOBILE_APPLY_FAILED = 'mobile_apply_failed';
	public const ERROR_WEB_PRESET_UNAVAILABLE = 'web_preset_unavailable';
	public const ERROR_WEB_PRESET_APPLY_FAILED = 'web_preset_apply_failed';
	public const SKIPPED_FEATURE_DISABLED = 'feature_disabled';

	private readonly MobilePresetMap $presetMap;
	private readonly MobilePresetRuntime $runtime;

	public function __construct(?MobilePresetMap $presetMap = null, ?MobilePresetRuntime $runtime = null)
	{
		$this->presetMap = $presetMap ?? new MobilePresetMap();
		$this->runtime = $runtime ?? new DefaultMobilePresetRuntime();
	}

	public function apply(string $mobilePresetId, int $userId, string $siteId): Result
	{
		if (!$this->runtime->isMobileModuleAvailable())
		{
			return $this->createSkippedResult();
		}

		if (!$this->runtime->isManagedModeEnabled())
		{
			return $this->createSkippedResult();
		}

		$webPresetId = $this->presetMap->getWebPresetId($mobilePresetId);
		if ($webPresetId === null)
		{
			return (new Result())->addError(new Error(
				'Mobile preset is unknown.',
				self::ERROR_UNKNOWN_PRESET,
			));
		}

		try
		{
			$mobileTabManager = $this->runtime->createManagedManager($userId, $siteId);
			$mobilePresetAvailable = array_key_exists($mobilePresetId, $mobileTabManager->getPresetList());
		}
		catch (\Throwable $exception)
		{
			return (new Result())->addError(new Error(
				'Mobile preset could not be applied.',
				self::ERROR_MOBILE_APPLY_FAILED,
				[
					'exceptionCode' => $exception->getCode(),
				],
			));
		}

		if (!$mobilePresetAvailable)
		{
			return (new Result())->addError(new Error(
				'Mobile preset is unavailable.',
				self::ERROR_MOBILE_PRESET_UNAVAILABLE,
			));
		}

		$webAvailabilityResult = $this->checkWebPresetAvailability($webPresetId, $siteId);
		$mobileApplyResult = $this->applyMobileDefault(
			$mobileTabManager,
			$siteId,
			$userId,
			$mobilePresetId,
		);
		if (!$mobileApplyResult->isSuccess())
		{
			return $mobileApplyResult;
		}

		$webResult = $webAvailabilityResult->isSuccess()
			? $this->applyWebPreset($webPresetId, $siteId)
			: $webAvailabilityResult;

		if (!$webResult->isSuccess())
		{
			return $this->createAppliedResult(
				$mobilePresetId,
				$webPresetId,
				true,
				$this->formatWarnings($webResult),
			);
		}

		return $this->createAppliedResult($mobilePresetId, $webPresetId, false);
	}

	private function applyMobileDefault(
		Tab\Manager $mobileTabManager,
		string $siteId,
		int $userId,
		string $mobilePresetId,
	): Result
	{
		try
		{
			$this->runtime->applyMobileDefault($mobileTabManager, $siteId, $userId, $mobilePresetId);
		}
		catch (SystemException $exception)
		{
			return (new Result())->addError(new Error(
				'Mobile preset could not be applied.',
				self::ERROR_MOBILE_APPLY_FAILED,
				[
					'exceptionCode' => $exception->getCode(),
				],
			));
		}
		catch (\Throwable)
		{
			return (new Result())->addError(new Error(
				'Mobile preset could not be applied.',
				self::ERROR_MOBILE_APPLY_FAILED,
			));
		}

		return new Result();
	}

	private function checkWebPresetAvailability(string $webPresetId, string $siteId): Result
	{
		try
		{
			if ($this->runtime->isWebPresetAvailable($webPresetId, $siteId))
			{
				return new Result();
			}
		}
		catch (\Throwable $exception)
		{
			return (new Result())->addError(new Error(
				'Web preset availability could not be checked.',
				self::ERROR_WEB_PRESET_APPLY_FAILED,
				[
					'exceptionCode' => $exception->getCode(),
				],
			));
		}

		return (new Result())->addError(new Error(
			'Web preset is unavailable.',
			self::ERROR_WEB_PRESET_UNAVAILABLE,
		));
	}

	private function applyWebPreset(string $webPresetId, string $siteId): Result
	{
		try
		{
			return $this->runtime->applyWebPreset($webPresetId, $siteId);
		}
		catch (\Throwable)
		{
			return (new Result())->addError(new Error(
				'Web preset could not be applied.',
				self::ERROR_WEB_PRESET_APPLY_FAILED,
			));
		}
	}

	private function createSkippedResult(): Result
	{
		$result = new Result();
		$result->setData([
			'applied' => false,
			'partial' => false,
			'mobilePresetId' => null,
			'webPresetId' => null,
			'skippedReason' => self::SKIPPED_FEATURE_DISABLED,
			'warnings' => [],
		]);

		return $result;
	}

	private function createAppliedResult(
		string $mobilePresetId,
		?string $webPresetId,
		bool $partial,
		array $warnings = [],
	): Result
	{
		$result = new Result();
		$result->setData([
			'applied' => true,
			'partial' => $partial,
			'mobilePresetId' => $mobilePresetId,
			'webPresetId' => $webPresetId,
			'skippedReason' => null,
			'warnings' => $warnings,
		]);

		return $result;
	}

	private function formatWarnings(Result $result): array
	{
		return array_map(
			static fn(Error $error): array => [
				'code' => self::getWarningCode($error),
				'message' => $error->getMessage(),
			],
			$result->getErrors(),
		);
	}

	private static function getWarningCode(Error $error): string
	{
		$code = (string)$error->getCode();

		return $code === '' || $code === '0' ? self::ERROR_WEB_PRESET_APPLY_FAILED : $code;
	}
}
