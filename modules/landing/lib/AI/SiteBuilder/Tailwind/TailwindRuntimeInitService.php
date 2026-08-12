<?php
namespace Bitrix\Landing\AI\SiteBuilder\Tailwind;

use Bitrix\Landing\Copilot\Services\CreateAiSiteChecker;
use Bitrix\Landing\Hook;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Rights;

final class TailwindRuntimeInitService
{
	public const INIT_MARKER = TailwindRuntimeStateService::INIT_MARKER;

	private TailwindRuntimeStateService $stateService;
	private TailwindManagedFileService $managedFileService;
	private TailwindRuntimeEligibilityService $eligibilityService;

	public function __construct(
		?TailwindRuntimeStateService $stateService = null,
		?TailwindManagedFileService $managedFileService = null,
		?CreateAiSiteChecker $createAiSiteChecker = null,
		?TailwindRuntimeEligibilityService $eligibilityService = null,
	)
	{
		$this->stateService = $stateService ?? new TailwindRuntimeStateService();
		$this->managedFileService = $managedFileService ?? new TailwindManagedFileService();
		$this->eligibilityService = $eligibilityService ?? new TailwindRuntimeEligibilityService($createAiSiteChecker);
	}

	public function initializeLanding(int $landingId, bool $force = false): array
	{
		$currentState = $this->stateService->getLandingState($landingId);
		if ($this->isMissingLandingState($currentState))
		{
			return $currentState;
		}
		if (!$this->eligibilityService->isLandingSupported($landingId))
		{
			return $this->buildRuntimeNotAllowedState($currentState, $landingId);
		}
		if ($this->shouldPreserveCustomCssFile($currentState))
		{
			return $currentState;
		}

		if (
			!$force
			&& (
				!empty($currentState['success'])
				|| ($currentState['error'] ?? '') !== 'runtime_not_initialized'
			)
		)
		{
			return $currentState;
		}

		$landing = Landing::createInstance($landingId, [
			'check_permissions' => false,
		]);
		if (!$landing->exist())
		{
			return $currentState;
		}

		$cleanup = $force
			? $this->cleanupManagedDraftCssBeforeReset($landingId)
			: []
		;

		$rightsState = Rights::isOn();
		if ($rightsState)
		{
			Rights::setGlobalOff();
		}
		try
		{
			Landing::saveAdditionalFields($landingId, [
				'CSSBLOCK_FILE' => self::INIT_MARKER,
			]);
		}
		finally
		{
			if ($rightsState)
			{
				Rights::setGlobalOn();
			}
		}

		$runtimeState = $this->stateService->getLandingState($landingId);
		if ($cleanup !== [])
		{
			$runtimeState['cleanup'] = $cleanup;
		}

		return $runtimeState;
	}

	private function buildRuntimeNotAllowedState(array $state, int $landingId): array
	{
		return array_merge($state, [
			'success' => false,
			'stage' => TailwindRuntimeStateService::STAGE_RUNTIME_NOT_ALLOWED,
			'landingId' => $landingId,
			'initialized' => false,
			'saved' => false,
			'source' => 'scope_guard',
			'message' => 'Tailwind runtime is available only for AI-created landing sites.',
			'error' => 'runtime_not_allowed',
		]);
	}

	private function isMissingLandingState(array $state): bool
	{
		return in_array((string)($state['stage'] ?? ''), [
			TailwindRuntimeStateService::STAGE_LANDING_MISSING,
			TailwindRuntimeStateService::STAGE_LANDING_NOT_FOUND,
		], true);
	}

	private function shouldPreserveCustomCssFile(array $state): bool
	{
		$stage = trim((string)($state['stage'] ?? ''));
		if ($stage === TailwindRuntimeStateService::STAGE_CUSTOM_CSS_FILE)
		{
			return true;
		}

		return trim((string)($state['error'] ?? '')) === 'cssblock_file_already_set';
	}

	private function cleanupManagedDraftCssBeforeReset(int $landingId): array
	{
		[$draftCssFile, $publicCssFile] = $this->getDraftAndPublicCssFiles($landingId);
		$managedFileIds = $this->managedFileService->collectLandingManagedFileIds($landingId);
		$draftFileId = $this->managedFileService->extractManagedFileIdFromCssUrl(
			$draftCssFile,
			$landingId,
			$managedFileIds,
		);

		$cleanup = [
			'draftCssFile' => $draftCssFile,
			'publicCssFile' => $publicCssFile,
			'attempted' => false,
			'deletedFileIds' => [],
			'skipped' => [],
			'errors' => [],
		];
		if ($draftFileId <= 0)
		{
			return $cleanup;
		}

		$cleanup['attempted'] = true;
		$publicFileId = $this->managedFileService->extractManagedFileIdFromCssUrl(
			$publicCssFile,
			$landingId,
			$managedFileIds,
		);
		if ($publicFileId > 0 && $publicFileId === $draftFileId)
		{
			$cleanup['skipped'][] = [
				'fileId' => $draftFileId,
				'reason' => 'used_by_public',
			];

			return $cleanup;
		}

		if (!class_exists('\\Bitrix\\Landing\\File'))
		{
			$cleanup['errors'][] = [
				'fileId' => $draftFileId,
				'reason' => 'file_api_missing',
				'message' => 'Landing file API is not available for stale managed CSS cleanup.',
			];

			return $cleanup;
		}

		try
		{
			$existsBeforeDelete = (bool)\Bitrix\Landing\File::getFileArray($draftFileId);
			if (!$existsBeforeDelete)
			{
				$cleanup['skipped'][] = [
					'fileId' => $draftFileId,
					'reason' => 'already_missing',
				];

				return $cleanup;
			}

			\Bitrix\Landing\File::deletePhysical($draftFileId);
			if (\Bitrix\Landing\File::getFileArray($draftFileId))
			{
				$cleanup['errors'][] = [
					'fileId' => $draftFileId,
					'reason' => 'delete_not_confirmed',
					'message' => 'Managed Tailwind CSS file deletion was not confirmed.',
				];
			}
			else
			{
				$cleanup['deletedFileIds'][] = $draftFileId;
			}
		}
		catch (\Throwable $exception)
		{
			$cleanup['errors'][] = [
				'fileId' => $draftFileId,
				'reason' => 'delete_failed',
				'message' => trim((string)$exception->getMessage()) ?: 'Failed to delete stale managed Tailwind CSS file.',
			];
		}

		return $cleanup;
	}

	private function getDraftAndPublicCssFiles(int $landingId): array
	{
		$draft = [];
		$public = [];
		$previousHookEditMode = Hook::getEditMode();
		try
		{
			Hook::setEditMode(true);
			$draft = Hook::getData($landingId, Hook::ENTITY_TYPE_LANDING, true);

			Hook::setEditMode(false);
			$public = Hook::getData($landingId, Hook::ENTITY_TYPE_LANDING, true);
		}
		finally
		{
			Hook::setEditMode($previousHookEditMode);
		}

		return [
			trim((string)($draft['CSSBLOCK']['FILE']['VALUE'] ?? '')),
			trim((string)($public['CSSBLOCK']['FILE']['VALUE'] ?? '')),
		];
	}
}
