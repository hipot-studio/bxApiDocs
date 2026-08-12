<?php
namespace Bitrix\Landing\AI\SiteBuilder\Tailwind;

use Bitrix\Landing\Copilot\Services\CreateAiSiteChecker;
use Bitrix\Landing\Hook;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Rights;

final class TailwindRuntimeCssService
{
	private TailwindManagedFileService $managedFileService;
	private TailwindRuntimeStateService $stateService;
	private TailwindRuntimeGenerationSyncService $generationSyncService;
	private TailwindRuntimeEligibilityService $eligibilityService;
	private string $tempRoot;

	public function __construct(
		?TailwindManagedFileService $managedFileService = null,
		?TailwindRuntimeStateService $stateService = null,
		?TailwindRuntimeGenerationSyncService $generationSyncService = null,
		?CreateAiSiteChecker $createAiSiteChecker = null,
		?string $tempRoot = null,
		?TailwindRuntimeEligibilityService $eligibilityService = null
	)
	{
		$this->managedFileService = $managedFileService ?? new TailwindManagedFileService();
		$this->stateService = $stateService ?? new TailwindRuntimeStateService();
		$this->generationSyncService = $generationSyncService ?? new TailwindRuntimeGenerationSyncService($this->stateService);
		$this->eligibilityService = $eligibilityService ?? new TailwindRuntimeEligibilityService($createAiSiteChecker);
		$defaultTempRoot = rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/landing-tailwind-runtime';
		$this->tempRoot = $tempRoot !== null && trim($tempRoot) !== ''
			? rtrim(str_replace('\\', '/', $tempRoot), '/')
			: str_replace('\\', '/', $defaultTempRoot);
	}

	public function saveLandingCss(int $landingId, string $css, array $options = []): array
	{
		if ($landingId <= 0)
		{
			return [
				'success' => false,
				'error' => 'landing_missing',
				'message' => 'Landing ID is required.',
			];
		}

		$css = trim($css);
		if ($css === '')
		{
			return [
				'success' => false,
				'error' => 'css_empty',
				'message' => 'CSS is empty.',
			];
		}

		$maxBytes = (int)($options['maxBytes'] ?? (5 * 1024 * 1024));
		if ($maxBytes > 0 && strlen($css) > $maxBytes)
		{
			return [
				'success' => false,
				'error' => 'css_too_large',
				'message' => 'CSS is too large.',
				'maxBytes' => $maxBytes,
			];
		}

		$landing = Landing::createInstance($landingId);
		if (!$landing->exist())
		{
			return [
				'success' => false,
				'error' => 'landing_not_found',
				'message' => 'Landing not found.',
			];
		}
		if (!$landing->canEdit())
		{
			return [
				'success' => false,
				'error' => 'access_denied',
				'message' => 'Insufficient permissions to edit landing.',
			];
		}
		if (!$this->eligibilityService->isLandingSupported($landingId))
		{
			return [
				'success' => false,
				'error' => 'runtime_not_allowed',
				'message' => 'Tailwind CSS can be saved only for AI-created landing sites.',
			];
		}

		$logger = $this->resolveLogger($options);
		if (!is_dir($this->tempRoot) && !mkdir($this->tempRoot, 0775, true) && !is_dir($this->tempRoot))
		{
			return [
				'success' => false,
				'error' => 'temp_dir_failed',
				'message' => 'Failed to create temp directory.',
			];
		}

		$tempFile = tempnam($this->tempRoot, 'runtime-css-' . $landingId . '-');
		if ($tempFile === false)
		{
			return [
				'success' => false,
				'error' => 'temp_file_failed',
				'message' => 'Failed to allocate CSS temp file.',
			];
		}
		if (file_put_contents($tempFile, $css) === false)
		{
			@unlink($tempFile);

			return [
				'success' => false,
				'error' => 'css_write_failed',
				'message' => 'Failed to write CSS temp file.',
			];
		}

		$managed = $this->managedFileService->persistLandingManagedFile($landingId, $tempFile, $logger);
		@unlink($tempFile);

		if (empty($managed['success']) || empty($managed['url']))
		{
			$managed['success'] = false;
			$managed['message'] = $managed['message'] ?? 'Failed to store CSS as managed file.';

			return $managed;
		}

		$apply = $this->applyCssFileToLanding(
			$landingId,
			(string)$managed['url'],
			(bool)($options['publish'] ?? true)
		);
		if (empty($apply['success']))
		{
			return array_merge($managed, $apply, [
				'success' => false,
			]);
		}

		$runtimeState = $this->stateService->getLandingState($landingId);
		$this->generationSyncService->syncByLanding($landingId, $runtimeState);
		$cleanup = $this->cleanupStaleManagedCssFiles($landingId);

		return array_merge($managed, [
			'success' => true,
			'message' => '',
			'runtimeState' => $runtimeState,
			'cleanup' => $cleanup,
		]);
	}

	private function applyCssFileToLanding(int $landingId, string $cssFileUrl, bool $publish = true): array
	{
		$cssFileUrl = trim($cssFileUrl);
		if ($cssFileUrl === '')
		{
			return [
				'success' => false,
				'error' => 'css_url_missing',
				'message' => 'CSS URL is required.',
			];
		}

		[$draftHookData, $publicHookData] = $this->getHookDataPair($landingId);
		$draft = $this->normalizeHookData($draftHookData);
		$public = $this->normalizeHookData($publicHookData);

		$cssCode = $draft['cssCode'] !== '' ? $draft['cssCode'] : $public['cssCode'];

		$new = [
			'cssCode' => $cssCode,
			'cssFile' => $cssFileUrl,
			'cssUse' => ($cssCode !== '' || $cssFileUrl !== '') ? 'Y' : 'N',
		];

		$draftNeedsSave = (
			$draft['cssCode'] !== $new['cssCode']
			|| $draft['cssFile'] !== $new['cssFile']
			|| $draft['cssUse'] !== $new['cssUse']
		);
		$publicNeedsPublication = $publish && (
			$public['cssCode'] !== $new['cssCode']
			|| $public['cssFile'] !== $new['cssFile']
			|| $public['cssUse'] !== $new['cssUse']
		);

		if ($draftNeedsSave || $publicNeedsPublication)
		{
			$this->runWithGlobalRightsOff(function () use ($landingId, $new, $draftNeedsSave, $publicNeedsPublication): void
			{
				if ($draftNeedsSave)
				{
					Landing::saveAdditionalFields($landingId, [
						'CSSBLOCK_USE' => $new['cssUse'],
						'CSSBLOCK_CODE' => $new['cssCode'],
						'CSSBLOCK_FILE' => $new['cssFile'],
					]);
				}

				if ($publicNeedsPublication)
				{
					$this->publishLanding($landingId);
				}
			});
		}
		else
		{
			return [
				'success' => true,
			];
		}

		[$verifiedDraftHookData, $verifiedPublicHookData] = $this->getHookDataPair($landingId);
		$verifiedDraft = $this->normalizeHookData($verifiedDraftHookData);
		$verifiedPublic = $this->normalizeHookData($verifiedPublicHookData);

		if (
			$new['cssFile'] !== ''
			&& (
				$verifiedDraft['cssFile'] !== $new['cssFile']
				|| $verifiedDraft['cssUse'] !== $new['cssUse']
				|| (
					$publicNeedsPublication
					&& (
						$verifiedPublic['cssFile'] !== $new['cssFile']
						|| $verifiedPublic['cssUse'] !== $new['cssUse']
					)
				)
			)
		)
		{
			return [
				'success' => false,
				'error' => 'hook_save_failed',
				'message' => 'Failed to save Tailwind CSS to landing hooks.',
			];
		}

		return [
			'success' => true,
		];
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

	/**
	 * @param array<string, mixed> $options
	 * @return callable(string, array, string): void
	 */
	private function resolveLogger(array $options): callable
	{
		$logger = $options['logger'] ?? $options['log'] ?? null;

		return is_callable($logger)
			? $logger
			: static function (string $message, array $context = [], string $level = 'info'): void {};
	}

	private function getHookDataPair(int $landingId): array
	{
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

		return [$draft, $public];
	}

	private function normalizeHookData(array $data): array
	{
		return [
			'cssCode' => trim((string)($data['CSSBLOCK']['CODE']['VALUE'] ?? '')),
			'cssFile' => trim((string)($data['CSSBLOCK']['FILE']['VALUE'] ?? '')),
			'cssUse' => (string)($data['CSSBLOCK']['USE']['VALUE'] ?? ''),
		];
	}

	private function publishLanding(int $landingId): void
	{
		$previousHookEditMode = Hook::getEditMode();

		try
		{
			Hook::setEditMode(true);
			Hook::publicationLanding($landingId);
		}
		finally
		{
			Hook::setEditMode($previousHookEditMode);
		}
	}

	private function cleanupStaleManagedCssFiles(int $landingId): array
	{
		$managedFileIds = $this->managedFileService->collectLandingManagedFileIds($landingId);
		$cleanup = [
			'allManagedFileIds' => $managedFileIds,
			'referencedFileIds' => [],
			'staleFileIds' => [],
			'deletedFileIds' => [],
			'errors' => [],
		];
		if ($managedFileIds === [])
		{
			return $cleanup;
		}

		[$draftHookData, $publicHookData] = $this->getHookDataPair($landingId);
		$draft = $this->normalizeHookData($draftHookData);
		$public = $this->normalizeHookData($publicHookData);

		$referenced = [];
		$draftFileId = $this->managedFileService->extractManagedFileIdFromCssUrl(
			$draft['cssFile'],
			$landingId,
			$managedFileIds,
		);
		if ($draftFileId > 0)
		{
			$referenced[$draftFileId] = $draftFileId;
		}

		$publicFileId = $this->managedFileService->extractManagedFileIdFromCssUrl(
			$public['cssFile'],
			$landingId,
			$managedFileIds,
		);
		if ($publicFileId > 0)
		{
			$referenced[$publicFileId] = $publicFileId;
		}

		$referencedIds = array_values($referenced);
		$staleFileIds = array_values(array_diff($managedFileIds, $referencedIds));
		$cleanup['referencedFileIds'] = $referencedIds;
		$cleanup['staleFileIds'] = $staleFileIds;

		if ($staleFileIds === [])
		{
			return $cleanup;
		}

		if (!class_exists('\\Bitrix\\Landing\\File'))
		{
			foreach ($staleFileIds as $fileId)
			{
				$cleanup['errors'][] = [
					'fileId' => (int)$fileId,
					'reason' => 'file_api_missing',
					'message' => 'Landing file API is not available for stale managed CSS cleanup.',
				];
			}

			return $cleanup;
		}

		foreach ($staleFileIds as $fileId)
		{
			$fileId = (int)$fileId;
			if ($fileId <= 0)
			{
				continue;
			}

			try
			{
				$existsBeforeDelete = (bool)\Bitrix\Landing\File::getFileArray($fileId);
				if (!$existsBeforeDelete)
				{
					continue;
				}

				\Bitrix\Landing\File::deletePhysical($fileId);
				if (\Bitrix\Landing\File::getFileArray($fileId))
				{
					$cleanup['errors'][] = [
						'fileId' => $fileId,
						'reason' => 'delete_not_confirmed',
						'message' => 'Managed Tailwind CSS file deletion was not confirmed.',
					];
				}
				else
				{
					$cleanup['deletedFileIds'][] = $fileId;
				}
			}
			catch (\Throwable $exception)
			{
				$cleanup['errors'][] = [
					'fileId' => $fileId,
					'reason' => 'delete_failed',
					'message' => trim((string)$exception->getMessage()) ?: 'Failed to delete stale managed Tailwind CSS file.',
				];
			}
		}

		return $cleanup;
	}
}
