<?php
namespace Bitrix\Landing\AI\SiteBuilder\Tailwind;

final class TailwindManagedFileService
{
	public function extractManagedFileIdFromCssUrl(
		string $cssFileUrl,
		int $landingId,
		?array $landingManagedFileIds = null,
	): int
	{
		if ($landingId <= 0)
		{
			return 0;
		}

		$fileId = $this->extractRuntimeMarkerFileIdFromUrl($cssFileUrl);
		if ($fileId <= 0)
		{
			return 0;
		}

		$landingManagedFileIds = $landingManagedFileIds ?? $this->collectLandingManagedFileIds($landingId);
		$isLinkedToLanding = in_array($fileId, $landingManagedFileIds, true);
		$isManagedLandingFile = $this->isManagedLandingFileId($fileId, $landingId);
		if (!$isLinkedToLanding && !$isManagedLandingFile)
		{
			return 0;
		}

		return $isManagedLandingFile ? $fileId : 0;
	}

	public function buildManagedFileUrl(string $path, int $fileId): string
	{
		$path = trim($path);
		if ($path === '')
		{
			return '';
		}

		$query = http_build_query([
			'v' => $fileId,
			'ai-tailwind-runtime' => 1,
		]);

		return $path . (strpos($path, '?') === false ? '?' : '&') . $query;
	}

	public function isLandingManagedFileName(string $fileName, int $landingId): bool
	{
		$fileName = trim($fileName);
		if ($fileName === '' || $landingId <= 0)
		{
			return false;
		}

		return (bool)preg_match(
			'/^tailwind-landing-' . preg_quote((string)$landingId, '/') . '(?:-[a-f0-9]{12})?(?:_[0-9]+)?\.css$/i',
			$fileName
		);
	}

	/**
	 * @return array<int, int>
	 */
	public function collectLandingManagedFileIds(int $landingId): array
	{
		if ($landingId <= 0 || !class_exists('\\Bitrix\\Landing\\File'))
		{
			return [];
		}

		$fileIds = [];
		foreach (\Bitrix\Landing\File::getFilesFromLanding($landingId) as $fileId)
		{
			$fileId = (int)$fileId;
			if ($fileId <= 0)
			{
				continue;
			}

			$file = \Bitrix\Landing\File::getFileArray($fileId);
			if (!$file)
			{
				continue;
			}

			$originalName = (string)($file['ORIGINAL_NAME'] ?? '');
			$storedName = (string)($file['FILE_NAME'] ?? '');
			if (
				$this->isLandingManagedFileName($originalName, $landingId)
				|| $this->isLandingManagedFileName($storedName, $landingId)
			)
			{
				$fileIds[$fileId] = $fileId;
			}
		}

		return array_values($fileIds);
	}

	/**
	 * @param callable(string, array, string): void $logger
	 * @return array<string, mixed>
	 */
	public function persistLandingManagedFile(int $landingId, string $sourcePath, callable $logger): array
	{
		if ($landingId <= 0)
		{
			return [
				'success' => false,
				'error' => 'managed_file_landing_missing',
			];
		}

		if (!class_exists('\\Bitrix\\Landing\\File') || !class_exists('\\CFile'))
		{
			$logger('tailwind_runtime_managed_file_api_missing', [
				'landingId' => $landingId,
			], 'error');

			return [
				'success' => false,
				'error' => 'managed_file_api_missing',
			];
		}

		$realPath = realpath($sourcePath);
		if ($realPath === false || !is_file($realPath))
		{
			$logger('tailwind_runtime_managed_file_source_missing', [
				'landingId' => $landingId,
				'source_path' => $sourcePath,
			], 'error');

			return [
				'success' => false,
				'error' => 'managed_file_source_missing',
			];
		}

		$fileHash = sha1_file($realPath);
		if (!is_string($fileHash) || $fileHash === '')
		{
			$fileHash = sha1($realPath . '|' . microtime(true));
		}

		$fileArray = \CFile::makeFileArray($realPath);
		if (!is_array($fileArray) || empty($fileArray['tmp_name']))
		{
			$logger('tailwind_runtime_managed_file_prepare_failed', [
				'landingId' => $landingId,
				'source_path' => $realPath,
			], 'error');

			return [
				'success' => false,
				'error' => 'managed_file_prepare_failed',
			];
		}

		$fileArray['name'] = 'tailwind-landing-' . $landingId . '-' . substr((string)$fileHash, 0, 12) . '.css';
		$fileArray['type'] = 'text/css';
		$fileArray['MODULE_ID'] = 'landing';

		$fileId = (int)\CFile::saveFile($fileArray, 'landing');
		if ($fileId <= 0)
		{
			$logger('tailwind_runtime_managed_file_save_failed', [
				'landingId' => $landingId,
				'source_path' => $realPath,
				'file_name' => $fileArray['name'],
			], 'error');

			return [
				'success' => false,
				'error' => 'managed_file_save_failed',
			];
		}

		\Bitrix\Landing\File::addToLanding($landingId, $fileId);
		$filePath = (string)\Bitrix\Landing\File::getFilePath($fileId);
		if ($filePath === '')
		{
			$logger('tailwind_runtime_managed_file_path_missing', [
				'landingId' => $landingId,
				'fileId' => $fileId,
			], 'error');

			return [
				'success' => false,
				'error' => 'managed_file_path_missing',
			];
		}

		$staleCandidateFileIds = array_values(array_diff(
			$this->collectLandingManagedFileIds($landingId),
			[$fileId]
		));
		$bytes = (int)(filesize($realPath) ?: 0);
		$url = $this->buildManagedFileUrl($filePath, $fileId);
		$logger('tailwind_runtime_managed_file_saved', [
			'landingId' => $landingId,
			'fileId' => $fileId,
			'filePath' => $filePath,
			'fileName' => $fileArray['name'],
			'bytes' => $bytes,
			'stale_candidate_count' => count($staleCandidateFileIds),
		]);

		return [
			'success' => true,
			'fileId' => $fileId,
			'path' => $filePath,
			'url' => $url,
			'bytes' => $bytes,
			'replacedFileCount' => count($staleCandidateFileIds),
		];
	}

	private function extractRuntimeMarkerFileIdFromUrl(string $cssFileUrl): int
	{
		$cssFileUrl = trim($cssFileUrl);
		if ($cssFileUrl === '')
		{
			return 0;
		}

		$parts = parse_url($cssFileUrl);
		if (!is_array($parts))
		{
			return 0;
		}

		$query = [];
		parse_str((string)($parts['query'] ?? ''), $query);
		if ((string)($query['ai-tailwind-runtime'] ?? '') !== '1')
		{
			return 0;
		}

		$fileId = (int)($query['v'] ?? 0);

		return $fileId > 0 ? $fileId : 0;
	}

	private function isManagedLandingFileId(int $fileId, int $landingId): bool
	{
		if ($fileId <= 0 || $landingId <= 0 || !class_exists('\\Bitrix\\Landing\\File'))
		{
			return false;
		}

		$file = \Bitrix\Landing\File::getFileArray($fileId);
		if (!$file)
		{
			return false;
		}

		$originalName = (string)($file['ORIGINAL_NAME'] ?? '');
		$storedName = (string)($file['FILE_NAME'] ?? '');

		return
			$this->isLandingManagedFileName($originalName, $landingId)
			|| $this->isLandingManagedFileName($storedName, $landingId)
		;
	}
}
