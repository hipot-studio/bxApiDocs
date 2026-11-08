<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Controller\Location;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Integration\Sale\Location\LocationAdapter;
use Bitrix\Crm\V2\Internal\Service\Location\BatchDelete\BatchDeleteService;
use Bitrix\Crm\V2\Internal\Service\Location\EffectiveScope;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Request;
use Bitrix\Main\Result;

Loc::loadMessages(__FILE__);

final class BatchDelete extends Controller
{
	private readonly BatchDeleteService $service;
	private readonly UserPermissions $userPermissions;
	private readonly LocationAdapter $adapter;

	public function __construct(
		?Request $request = null,
		?BatchDeleteService $service = null,
		?UserPermissions $userPermissions = null,
		?LocationAdapter $adapter = null,
	)
	{
		parent::__construct($request);

		$this->adapter = $adapter ?? new LocationAdapter();
		$this->service = $service ?? new BatchDeleteService($this->adapter);
		$this->userPermissions = $userPermissions ?? Container::getInstance()->getUserPermissions();
	}

	public function configureActions(): array
	{
		$configuration = [
			'+prefilters' => [
				new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			],
		];

		return [
			'process' => $configuration,
			'cancel' => $configuration,
		];
	}

	public function processAction(
		string $PROCESS_TOKEN,
		?string $mode = null,
		array $ids = [],
		?array $filter = null,
	): ?array
	{
		if (!$this->checkAccessAndSession())
		{
			return null;
		}

		$scope = $mode === null ? null : $this->buildScope($filter ?? []);

		return $this->getResponse($this->service->process($PROCESS_TOKEN, $mode, $ids, $scope));
	}

	public function cancelAction(
		string $PROCESS_TOKEN,
		?string $cancelingAction = null,
	): ?array
	{
		if (!$this->checkAccessAndSession())
		{
			return null;
		}

		$response = $this->getResponse($this->service->cancel($PROCESS_TOKEN));
		if ($response === null || $cancelingAction !== 'process')
		{
			return $response;
		}

		return array_replace($response, [
			'STATUS' => 'COMPLETED',
			'PROCESSED_ITEMS' => 0,
			'TOTAL_ITEMS' => 0,
			'SUCCESS_ITEMS' => 0,
			'FAILED_ITEMS' => 0,
			'SUMMARY' => null,
			'SUMMARY_HTML' => null,
		]);
	}

	private function checkAccessAndSession(): bool
	{
		if (!$this->userPermissions->isCrmAdmin())
		{
			$this->addError(new Error(
				Loc::getMessage('CRM_LOCATION_BATCH_DELETE_ACCESS_DENIED') ?? 'Access denied.',
				ErrorCode::ACCESS_DENIED,
			));

			return false;
		}

		if (!check_bitrix_sessid())
		{
			$this->addError(new Error(
				Loc::getMessage('CRM_LOCATION_BATCH_DELETE_INVALID_SESSION') ?? 'Session has expired.',
				'INVALID_SESSION',
			));

			return false;
		}

		return true;
	}

	private function buildScope(array $rawFilter): ?EffectiveScope
	{
		$parentId = $this->normalizeParentId($rawFilter);
		if ($parentId === null)
		{
			return null;
		}

		$find = trim((string)($rawFilter['FIND'] ?? ''));
		$languageId = (string)LANGUAGE_ID;

		return new EffectiveScope(
			$this->adapter->normalize($rawFilter, $parentId, $find, $languageId),
			['ID' => 'ASC'],
			['ID'],
			$this->adapter->nameRuntime($languageId),
		);
	}

	private function normalizeParentId(array $rawFilter): ?int
	{
		$hasCanonicalParent = array_key_exists('PARENT_ID', $rawFilter);
		$hasNumericParent = array_key_exists('PARENT_ID_numsel', $rawFilter)
			|| array_key_exists('PARENT_ID_from', $rawFilter)
			|| array_key_exists('PARENT_ID_to', $rawFilter);

		if (!$hasCanonicalParent && !$hasNumericParent)
		{
			return null;
		}

		$canonicalParentId = null;
		if ($hasCanonicalParent)
		{
			$canonicalParentId = $this->exactInt($rawFilter['PARENT_ID']);
			if ($canonicalParentId === null)
			{
				return null;
			}
		}

		$numericParentId = null;
		if ($hasNumericParent)
		{
			$numericParentId = $this->normalizeNumericParentId($rawFilter);
			if ($numericParentId === null)
			{
				return null;
			}
		}

		if ($hasCanonicalParent && $hasNumericParent && $canonicalParentId !== $numericParentId)
		{
			return null;
		}

		return $canonicalParentId ?? $numericParentId;
	}

	private function normalizeNumericParentId(array $rawFilter): ?int
	{
		if (
			($rawFilter['PARENT_ID_numsel'] ?? null) !== 'exact'
			|| !array_key_exists('PARENT_ID_from', $rawFilter)
			|| !array_key_exists('PARENT_ID_to', $rawFilter)
		)
		{
			return null;
		}

		$parentId = $rawFilter['PARENT_ID_from'] === ''
			? 0
			: $this->exactInt($rawFilter['PARENT_ID_from']);
		if ($parentId === null)
		{
			return null;
		}

		$toParentId = $rawFilter['PARENT_ID_to'] === ''
			? $parentId
			: $this->exactInt($rawFilter['PARENT_ID_to']);

		return $toParentId === $parentId ? $parentId : null;
	}

	private function exactInt(mixed $value): ?int
	{
		if (is_int($value))
		{
			return $value >= 0 ? $value : null;
		}

		if (!is_string($value))
		{
			return null;
		}

		$value = trim($value);
		if (!preg_match('/^(?:0|[1-9]\d*)$/D', $value))
		{
			return null;
		}

		$parentId = (int)$value;

		return (string)$parentId === $value ? $parentId : null;
	}

	private function getResponse(Result $result): ?array
	{
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}
}
