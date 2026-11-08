<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller;

use Bitrix\Disk\FilePicker\Context;
use Bitrix\Disk\FilePicker\Filter;
use Bitrix\Disk\FilePicker\Provider;
use Bitrix\Disk\FilePicker\SignedConfig;
use Bitrix\Disk\FilePicker\Validation\StrictInteger;
use Bitrix\Disk\Internals\Engine\Controller;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Result;

class FilePicker extends Controller
{
	public function configureActions(): array
	{
		return [
			'getInitialStage' => [
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\CloseSession(),
				],
			],
			'listChildren' => [
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\CloseSession(),
				],
			],
			'search' => [
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\CloseSession(),
				],
			],
			'resolveSelection' => [
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\CloseSession(),
				],
			],
			'getViewerAttributes' => [
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\CloseSession(),
				],
			],
		];
	}

	public function getInitialStageAction(
		CurrentUser $currentUser,
		?array $initialStage = null,
		string $objectTypeFilter = Filter::OBJECT_TYPE_ALL,
		array $fileTypeFilters = [],
		array $allowedFileTypes = [],
		int $pageSize = Provider::DEFAULT_PAGE_SIZE,
		bool $includeTotal = false,
		?array $signedConfig = null,
		int $page = Provider::DEFAULT_PAGE,
	): ?array
	{
		$startedAt = microtime(true);
		$isSigned = $signedConfig !== null;
		$signedConfigResult = $this->createSignedConfig($signedConfig, $currentUser);
		if (!$signedConfigResult->isSuccess())
		{
			return $this->processResult(
				$signedConfigResult,
				'getInitialStage',
				$pageSize,
				$includeTotal,
				$isSigned,
				$startedAt,
			);
		}

		$signedConfig = $signedConfigResult->getData()['signedConfig'];
		$initialStage = $this->resolveInitialStage($initialStage, $signedConfig);
		$allowedFileTypes = $this->getAllowedFileTypes($allowedFileTypes, $signedConfig);

		$filterResult = Filter::create($objectTypeFilter, $allowedFileTypes, $fileTypeFilters);
		if (!$filterResult->isSuccess())
		{
			return $this->processResult(
				$filterResult,
				'getInitialStage',
				$pageSize,
				$includeTotal,
				$isSigned,
				$startedAt,
			);
		}

		return $this->processResult(
			$this->createProvider($currentUser, $signedConfig)->getInitialStage(
				$initialStage,
				$filterResult->getData()['filter'],
				$pageSize,
				$includeTotal,
				$page,
			),
			'getInitialStage',
			$pageSize,
			$includeTotal,
			$isSigned,
			$startedAt,
		);
	}

	public function listChildrenAction(
		CurrentUser $currentUser,
		int $storageId,
		int $folderId,
		string $objectTypeFilter = Filter::OBJECT_TYPE_ALL,
		array $fileTypeFilters = [],
		array $allowedFileTypes = [],
		array $order = Provider::DEFAULT_ORDER,
		int $page = Provider::DEFAULT_PAGE,
		int $pageSize = Provider::DEFAULT_PAGE_SIZE,
		bool $includeTotal = false,
		?array $signedConfig = null,
	): ?array
	{
		$startedAt = microtime(true);
		$isSigned = $signedConfig !== null;
		$signedConfigResult = $this->createSignedConfig($signedConfig, $currentUser);
		if (!$signedConfigResult->isSuccess())
		{
			return $this->processResult(
				$signedConfigResult,
				'listChildren',
				$pageSize,
				$includeTotal,
				$isSigned,
				$startedAt,
			);
		}

		$signedConfig = $signedConfigResult->getData()['signedConfig'];
		$allowedFileTypes = $this->getAllowedFileTypes($allowedFileTypes, $signedConfig);

		$filterResult = Filter::create($objectTypeFilter, $allowedFileTypes, $fileTypeFilters);
		if (!$filterResult->isSuccess())
		{
			return $this->processResult(
				$filterResult,
				'listChildren',
				$pageSize,
				$includeTotal,
				$isSigned,
				$startedAt,
			);
		}

		return $this->processResult(
			$this->createProvider($currentUser, $signedConfig)->listChildren(
				$storageId,
				$folderId,
				$filterResult->getData()['filter'],
				$order,
				$page,
				$pageSize,
				$includeTotal,
			),
			'listChildren',
			$pageSize,
			$includeTotal,
			$isSigned,
			$startedAt,
		);
	}

	public function searchAction(
		CurrentUser $currentUser,
		string $query,
		?int $storageId = null,
		string $objectTypeFilter = Filter::OBJECT_TYPE_FILES,
		array $fileTypeFilters = [],
		array $allowedFileTypes = [],
		int $page = Provider::DEFAULT_PAGE,
		int $pageSize = Provider::DEFAULT_PAGE_SIZE,
		bool $includeTotal = false,
		?array $signedConfig = null,
	): ?array
	{
		$startedAt = microtime(true);
		$isSigned = $signedConfig !== null;
		$signedConfigResult = $this->createSignedConfig($signedConfig, $currentUser);
		if (!$signedConfigResult->isSuccess())
		{
			return $this->processResult(
				$signedConfigResult,
				'search',
				$pageSize,
				$includeTotal,
				$isSigned,
				$startedAt,
			);
		}

		$signedConfig = $signedConfigResult->getData()['signedConfig'];
		$allowedFileTypes = $this->getAllowedFileTypes($allowedFileTypes, $signedConfig);

		$filterResult = Filter::create($objectTypeFilter, $allowedFileTypes, $fileTypeFilters);
		if (!$filterResult->isSuccess())
		{
			return $this->processResult(
				$filterResult,
				'search',
				$pageSize,
				$includeTotal,
				$isSigned,
				$startedAt,
			);
		}

		return $this->processResult(
			$this->createProvider($currentUser, $signedConfig)->search(
				$query,
				$filterResult->getData()['filter'],
				$page,
				$pageSize,
				$storageId,
				$includeTotal,
			),
			'search',
			$pageSize,
			$includeTotal,
			$isSigned,
			$startedAt,
		);
	}

	public function resolveSelectionAction(
		CurrentUser $currentUser,
		array $objectIds,
		string $selectionMode = SignedConfig::SELECTION_MODE_SINGLE,
		#[StrictInteger]
		?int $maxItems = null,
		array $allowedFileTypes = [],
		?array $signedConfig = null,
	): ?array
	{
		$startedAt = microtime(true);
		$isSigned = $signedConfig !== null;
		$signedConfigResult = $this->createSignedConfig($signedConfig, $currentUser);
		if (!$signedConfigResult->isSuccess())
		{
			return $this->processResult(
				$signedConfigResult,
				'resolveSelection',
				null,
				false,
				$isSigned,
				$startedAt,
			);
		}

		$signedConfig = $signedConfigResult->getData()['signedConfig'];

		return $this->processResult(
			$this->createProvider($currentUser, $signedConfig)->resolveSelection(
				$objectIds,
				$selectionMode,
				$maxItems,
				$allowedFileTypes,
			),
			'resolveSelection',
			null,
			false,
			$isSigned,
			$startedAt,
		);
	}

	/**
	 * The signed config is validated and bound to the user and the site as everywhere else, but it does not
	 * narrow the answer: the right to preview a file is the right to read it, not a match with the types the
	 * host allows to select.
	 */
	public function getViewerAttributesAction(
		CurrentUser $currentUser,
		array $objectIds,
		?array $signedConfig = null,
	): ?array
	{
		$startedAt = microtime(true);
		$isSigned = $signedConfig !== null;
		$signedConfigResult = $this->createSignedConfig($signedConfig, $currentUser);
		if (!$signedConfigResult->isSuccess())
		{
			return $this->processResult(
				$signedConfigResult,
				'getViewerAttributes',
				null,
				false,
				$isSigned,
				$startedAt,
			);
		}

		$signedConfig = $signedConfigResult->getData()['signedConfig'];

		return $this->processResult(
			$this->createProvider($currentUser, $signedConfig)->getViewerAttributes($objectIds),
			'getViewerAttributes',
			null,
			false,
			$isSigned,
			$startedAt,
		);
	}

	protected function createProvider(CurrentUser $currentUser, ?SignedConfig $signedConfig): Provider
	{
		return new Provider(new Context((int)$currentUser->getId(), $signedConfig));
	}

	private function resolveInitialStage(?array $requestStage, ?SignedConfig $signedConfig): array
	{
		$signedStage = $signedConfig?->getInitialStage();
		if ($signedStage === null)
		{
			return $this->normalizeUnsignedInitialStage($requestStage ?? Provider::DEFAULT_INITIAL_STAGE);
		}

		$requestStageType = $requestStage['type'] ?? null;
		if (in_array($requestStageType, [
			Provider::INITIAL_STAGE_SOURCES,
			Provider::INITIAL_STAGE_RECENT,
		], true))
		{
			return $requestStage;
		}

		return $signedStage;
	}

	private function normalizeUnsignedInitialStage(array $initialStage): array
	{
		if (($initialStage['type'] ?? null) !== Provider::INITIAL_STAGE_FOLDER)
		{
			return $initialStage;
		}

		foreach (['storageId', 'folderId'] as $field)
		{
			$value = $initialStage[$field] ?? null;
			if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1)
			{
				continue;
			}

			$normalized = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
			if ($normalized !== false)
			{
				$initialStage[$field] = $normalized;
			}
		}

		return $initialStage;
	}

	private function createSignedConfig(?array $signedConfig, CurrentUser $currentUser): Result
	{
		$result = new Result();
		if ($signedConfig === null)
		{
			return $result->setData(['signedConfig' => null]);
		}

		$currentSiteId = defined('SITE_ID') && SITE_ID !== '' ? (string)SITE_ID : null;

		return SignedConfig::createFromSignedArray(
			$signedConfig,
			(int)$currentUser->getId(),
			$currentSiteId,
		);
	}

	private function getAllowedFileTypes(array $requestFileTypes, ?SignedConfig $signedConfig): array
	{
		return $signedConfig?->getAllowedFileTypes() ?? $requestFileTypes;
	}

	private function processResult(
		Result $result,
		string $action,
		?int $pageSize,
		bool $includeTotal,
		bool $isSigned,
		float $startedAt,
	): ?array
	{
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
			foreach ($result->getErrors() as $error)
			{
				$this->writeFailureDiagnostic([
					'action' => $action,
					'errorCode' => (string)$error->getCode(),
					'pageSize' => $pageSize,
					'includeTotal' => $includeTotal,
					'mode' => $isSigned ? 'signed' : 'simple',
					'elapsedBucket' => $this->getElapsedBucket($startedAt),
				]);
			}

			return null;
		}

		return $result->getData();
	}

	protected function writeFailureDiagnostic(array $context): void
	{
		(new LoggerFactory())
			->createById('disk.filePicker')
			?->warning('File picker request failed.', $context)
		;
	}

	private function getElapsedBucket(float $startedAt): string
	{
		$elapsedMilliseconds = (microtime(true) - $startedAt) * 1000;

		return match (true)
		{
			$elapsedMilliseconds < 10 => 'under_10ms',
			$elapsedMilliseconds < 50 => 'under_50ms',
			$elapsedMilliseconds < 200 => 'under_200ms',
			$elapsedMilliseconds < 1000 => 'under_1000ms',
			default => 'at_least_1000ms',
		};
	}
}
