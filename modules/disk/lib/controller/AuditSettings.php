<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller;

use Bitrix\Disk\Internal\Dto\Audit\UpdateAuditSettingsRequest;
use Bitrix\Disk\Internal\Enum\AuditRetentionPeriod;
use Bitrix\Disk\Internal\Service\Audit\AuditAccessService;
use Bitrix\Disk\Internal\Service\Audit\AuditAvailabilityService;
use Bitrix\Disk\Internal\Service\Audit\AuditCaptureService;
use Bitrix\Disk\Internal\Service\Audit\AuditSettingsService;
use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Request;

final class AuditSettings extends Controller
{
	public const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';
	public const ERROR_AUDIT_FEATURE_DISABLED = 'AUDIT_FEATURE_DISABLED';
	public const ERROR_AUDIT_LICENSE_UNAVAILABLE = 'AUDIT_LICENSE_UNAVAILABLE';
	public const ERROR_INVALID_ENABLED = 'INVALID_ENABLED';
	public const ERROR_INVALID_RETENTION_PERIOD = 'INVALID_RETENTION_PERIOD';
	public const ERROR_INVALID_CONFIRM_RETENTION_DECREASE = 'INVALID_CONFIRM_RETENTION_DECREASE';
	public const ERROR_RETENTION_DECREASE_CONFIRMATION_REQUIRED = 'RETENTION_DECREASE_CONFIRMATION_REQUIRED';
	public const ERROR_SETTINGS_UPDATE_LOCK_FAILED = 'AUDIT_SETTINGS_UPDATE_LOCK_FAILED';

	public function __construct(
		?Request $request = null,
		private readonly ?AuditSettingsService $settingsService = null,
		private readonly ?AuditAvailabilityService $availabilityService = null,
		private readonly ?AuditAccessService $accessService = null,
		private readonly ?AuditCaptureService $captureService = null,
	)
	{
		parent::__construct($request);
	}

	public function configureActions(): array
	{
		return [
			'get' => [
				'prefilters' => [
					new Authentication(),
					new HttpMethod([HttpMethod::METHOD_GET]),
				],
			],
			'update' => [
				'prefilters' => [
					new Authentication(),
					new Csrf(),
					new HttpMethod([HttpMethod::METHOD_POST]),
				],
			],
		];
	}

	public function getAction(CurrentUser $currentUser): ?array
	{
		if (!$this->checkAccess($currentUser))
		{
			return null;
		}

		if (!$this->checkLicense())
		{
			return null;
		}

		return $this->getSettingsService()
			->getState($this->getAvailabilityService())
			->jsonSerialize()
		;
	}

	public function updateAction(
		CurrentUser $currentUser,
		array $settings,
	): ?array
	{
		if (!$this->checkAccess($currentUser))
		{
			return null;
		}

		if (!$this->checkLicense())
		{
			return null;
		}

		$request = UpdateAuditSettingsRequest::createFromArray($settings);
		if (
			(array_key_exists('enabled', $settings) && $request->enabled === null)
			|| (!array_key_exists('enabled', $settings) && !array_key_exists('retentionPeriod', $settings))
		)
		{
			$this->addError($this->createError(
				'Invalid audit enabled flag.',
				self::ERROR_INVALID_ENABLED,
				422,
			));

			return null;
		}

		if (
			array_key_exists('retentionPeriod', $settings)
			&& ($request->retentionPeriod === null || !AuditRetentionPeriod::isValid($request->retentionPeriod))
		)
		{
			$this->addError($this->createError(
				'Invalid audit retention period.',
				self::ERROR_INVALID_RETENTION_PERIOD,
				422,
			));

			return null;
		}

		if ($request->confirmRetentionDecrease === null)
		{
			$this->addError($this->createError(
				'Invalid retention decrease confirmation.',
				self::ERROR_INVALID_CONFIRM_RETENTION_DECREASE,
				422,
			));

			return null;
		}

		$result = $this->getSettingsService()->updateSettings(
			enabled: $request->enabled,
			retentionPeriod: $request->retentionPeriod,
			confirmRetentionDecrease: $request->confirmRetentionDecrease,
			currentUser: $currentUser,
			availabilityService: $this->getAvailabilityService(),
			captureService: $this->getCaptureService(),
		);
		if (!$result->isSuccess())
		{
			$this->addErrors($this->mapUpdateErrors($result->getErrors()));

			return null;
		}

		return $result->getData()['state']->jsonSerialize();
	}

	private function checkAccess(CurrentUser $currentUser): bool
	{
		if (!$this->getAccessService()->isAdmin($currentUser))
		{
			$this->addError($this->createError('Access denied.', self::ERROR_ACCESS_DENIED, 403));

			return false;
		}

		if (!$this->getAvailabilityService()->isFeatureEnabled())
		{
			$this->addError($this->createError(
				'Audit feature is disabled.',
				self::ERROR_AUDIT_FEATURE_DISABLED,
				404,
			));

			return false;
		}

		return true;
	}

	private function checkLicense(): bool
	{
		if (!$this->getAvailabilityService()->isLicenseAvailable())
		{
			$this->addError($this->createError(
				'Audit license is unavailable.',
				self::ERROR_AUDIT_LICENSE_UNAVAILABLE,
				423,
			));

			return false;
		}

		return true;
	}

	private function getSettingsService(): AuditSettingsService
	{
		return $this->settingsService ?? ServiceLocator::getInstance()->get(AuditSettingsService::class);
	}

	private function getAvailabilityService(): AuditAvailabilityService
	{
		return $this->availabilityService ?? ServiceLocator::getInstance()->get(AuditAvailabilityService::class);
	}

	private function getAccessService(): AuditAccessService
	{
		return $this->accessService ?? ServiceLocator::getInstance()->get(AuditAccessService::class);
	}

	private function getCaptureService(): AuditCaptureService
	{
		return $this->captureService ?? ServiceLocator::getInstance()->get(AuditCaptureService::class);
	}

	/**
	 * @param Error[] $errors
	 * @return Error[]
	 */
	private function mapUpdateErrors(array $errors): array
	{
		return array_map(
			function (Error $error): Error {
				return match ($error->getCode()) {
					AuditSettingsService::ERROR_AUDIT_FEATURE_DISABLED => $this->createError(
						$error->getMessage(),
						self::ERROR_AUDIT_FEATURE_DISABLED,
						404,
					),
					AuditSettingsService::ERROR_AUDIT_LICENSE_UNAVAILABLE => $this->createError(
						$error->getMessage(),
						self::ERROR_AUDIT_LICENSE_UNAVAILABLE,
						423,
					),
					AuditSettingsService::ERROR_INVALID_RETENTION_PERIOD => $this->createError(
						$error->getMessage(),
						self::ERROR_INVALID_RETENTION_PERIOD,
						422,
					),
					AuditSettingsService::ERROR_RETENTION_DECREASE_CONFIRMATION_REQUIRED => $this->createError(
						$error->getMessage(),
						self::ERROR_RETENTION_DECREASE_CONFIRMATION_REQUIRED,
						422,
					),
					AuditSettingsService::ERROR_SETTINGS_UPDATE_LOCK_FAILED => $this->createError(
						$error->getMessage(),
						self::ERROR_SETTINGS_UPDATE_LOCK_FAILED,
						503,
					),
					default => $error,
				};
			},
			$errors,
		);
	}

	private function createError(string $message, string $code, int $httpStatus): Error
	{
		return new Error(
			$message,
			$code,
			[
				'httpStatus' => $httpStatus,
			],
		);
	}
}
