<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\UI;

use Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout\Context;
use Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout\ErrorCode;
use Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout\Scope;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Collection;
use Throwable;

/**
 * @internal
 */
class EntityEditorScopeGateway
{
	private const ACCESS_CODE_MAX_LENGTH = 11;
	private const ACCESS_CODE_MAX_COUNT = 1001;
	private const NAME_MAX_LENGTH = 100;

	private const TYPE_USER = 'user';
	private const TYPE_DEPARTMENT = 'department';
	private const TYPE_PROJECT = 'project';
	private const TYPE_PROJECT_ACCESS_CODES = 'project-access-codes';
	private const TYPE_STRUCTURE_NODE = 'structure-node';
	private const PREFIX_BY_TYPE = [
		self::TYPE_USER => ['U'],
		self::TYPE_DEPARTMENT => ['DR', 'D'],
		self::TYPE_PROJECT => ['SG'],
		self::TYPE_STRUCTURE_NODE => ['SNDR', 'SND'],
	];

	public function ensureAvailable(): Result
	{
		try
		{
			Loader::requireModule('ui');
		}
		catch (Throwable)
		{
			return $this->storageFailure('The UI entity editor scope storage is not available.');
		}

		return new Result();
	}

	public function getScopesPage(
		int $subjectUserId,
		string $configId,
		string $mode,
		int $limit,
		int $offset,
	): Result
	{
		if ($subjectUserId <= 0 || $configId === '')
		{
			return $this->accessFailure();
		}
		if ($limit <= 0 || $offset < 0 || !in_array($mode, [Context::MODE_CREATE, Context::MODE_UPDATE], true))
		{
			return $this->validationFailure();
		}

		$availabilityResult = $this->ensureAvailable();
		if (!$availabilityResult->isSuccess())
		{
			return $availabilityResult;
		}

		try
		{
			$rows = \Bitrix\Ui\EntityForm\Scope::getInstance($subjectUserId)->getUserScopesPage(
				$configId,
				Context::UI_MODULE_CATEGORY,
				true,
				$limit,
				$offset,
				$mode === Context::MODE_CREATE ? true : null,
				$mode === Context::MODE_UPDATE ? true : null,
				true,
			);
		}
		catch (Throwable)
		{
			return $this->storageFailure('Could not list CRM entity editor custom layouts.');
		}

		$scopes = [];
		foreach ($rows as $scopeId => $row)
		{
			$scopeResult = $this->buildScope((int)$scopeId, $row, $row['ACCESS_CODES'] ?? []);
			if (!$scopeResult->isSuccess())
			{
				return $scopeResult;
			}
			$scopes[] = $scopeResult->getData()['scope'];
		}

		return (new Result())->setData(['scopes' => $scopes]);
	}

	public function getCandidateIds(int $actorUserId, string $configId): Result
	{
		if ($actorUserId <= 0 || $configId === '')
		{
			return $this->accessFailure();
		}

		$availabilityResult = $this->ensureAvailable();
		if (!$availabilityResult->isSuccess())
		{
			return $availabilityResult;
		}

		try
		{
			$candidates = \Bitrix\Ui\EntityForm\Scope::getInstance($actorUserId)->getUserScopesEntityEditor(
				$configId,
				Context::UI_MODULE_CATEGORY,
			);
		}
		catch (Throwable)
		{
			return $this->storageFailure('Could not list CRM entity editor custom layouts.');
		}

		$ids = array_keys($candidates);
		Collection::normalizeArrayValuesByInt($ids);

		return (new Result())->setData([
			'scopeIds' => $ids,
		]);
	}

	/**
	 * @param array<int, int> $scopeIds
	 */
	public function readScopes(int $actorUserId, array $scopeIds, string $configId): Result
	{
		if ($scopeIds === [])
		{
			return (new Result())->setData(['scopes' => []]);
		}

		$scopes = [];
		foreach ($scopeIds as $scopeId)
		{
			$scopeResult = $this->readScope($actorUserId, $scopeId, $configId);
			if (!$scopeResult->isSuccess())
			{
				if (
					$this->isInvalidPersistedAccessCode($scopeResult)
					|| $scopeResult->getError()?->getCode() === ErrorCode::ACCESS_DENIED
				)
				{
					continue;
				}

				return $scopeResult;
			}

			$scopes[] = $scopeResult->getData()['scope'];
		}

		return (new Result())->setData(['scopes' => $scopes]);
	}

	public function readScope(int $actorUserId, int $scopeId, string $configId): Result
	{
		$rawResult = $this->readRawScope($actorUserId, $scopeId);
		if (!$rawResult->isSuccess())
		{
			return $rawResult;
		}

		$scope = $rawResult->getData()['scope'];
		if (!$this->belongsToContext($scope, $configId))
		{
			return $this->accessFailure();
		}

		$accessCodesResult = $this->readCanonicalAccessCodes($actorUserId, $scopeId);
		if (!$accessCodesResult->isSuccess())
		{
			return $accessCodesResult;
		}

		return $this->buildScope($scopeId, $scope, $accessCodesResult->getData()['accessCodes']);
	}

	/**
	 * @param array<int, array{type?: mixed, id?: mixed}|object> $accessCodes
	 * @param array<int, array<string, mixed>> $layout
	 */
	public function createScope(
		int $actorUserId,
		string $configId,
		string $name,
		array $layout,
		array $accessCodes,
		bool $autoApplyScope,
		bool $onAdd,
		bool $onUpdate,
	): Result
	{
		$canonicalResult = $this->canonicalizeInputAccessCodes($accessCodes);
		if (!$canonicalResult->isSuccess())
		{
			return $canonicalResult;
		}

		$nameResult = $this->normalizeInputName($name);
		if (!$nameResult->isSuccess())
		{
			return $nameResult;
		}
		$name = $nameResult->getData()['name'];
		$canonicalAccessCodes = $canonicalResult->getData()['accessCodes'];
		if ($canonicalAccessCodes === [])
		{
			return $this->validationFailure();
		}

		$createdScopeId = null;
		try
		{
			$scopeId = \Bitrix\Ui\EntityForm\Scope::getInstance($actorUserId)->setScopeConfigWithCreatedId(
				Context::UI_MODULE_CATEGORY,
				$configId,
				$name,
				$this->toSelectorAccessCodes($canonicalAccessCodes),
				$layout,
				[
					'common' => 'Y',
					'forceSetToUsers' => $this->flag($autoApplyScope),
					'categoryName' => Context::OPTION_CATEGORY,
					'availableOnAdd' => $this->flag($onAdd),
					'availableOnUpdate' => $this->flag($onUpdate),
				],
				true,
				$createdScopeId,
			);
		}
		catch (Throwable)
		{
			return $createdScopeId !== null
				? $this->partialStorageFailure('The CRM entity editor custom layout was only partially created.')
				: $this->storageFailure('Could not create the CRM entity editor custom layout.');
		}

		if (!is_int($scopeId) || $scopeId <= 0)
		{
			return $createdScopeId !== null
				? $this->partialStorageFailure('The CRM entity editor custom layout was only partially created.')
				: $this->storageFailure('Could not create the CRM entity editor custom layout.');
		}

		$postconditionResult = $this->verifyScope(
			$actorUserId,
			$scopeId,
			$configId,
			$name,
			$layout,
			$canonicalAccessCodes,
			$autoApplyScope,
			$onAdd,
			$onUpdate,
		);
		if (!$postconditionResult->isSuccess())
		{
			return $this->partialStorageFailure('The CRM entity editor custom layout was only partially created.');
		}

		return (new Result())->setData([
			'scopeId' => $scopeId,
			'autoApplyScheduled' => $autoApplyScope,
		]);
	}

	/**
	 * @param array<string, mixed> $changes
	 */
	public function updateScope(int $actorUserId, int $scopeId, string $configId, array $changes): Result
	{
		$currentResult = $this->readRawScope($actorUserId, $scopeId);
		if (!$currentResult->isSuccess())
		{
			return $currentResult;
		}

		$current = $currentResult->getData()['scope'];
		if (!$this->belongsToContext($current, $configId))
		{
			return $this->accessFailure();
		}

		if (array_diff(array_keys($changes), ['name', 'autoApplyScope', 'onAdd', 'onUpdate']) !== [])
		{
			return $this->validationFailure();
		}

		$fields = [];
		if (array_key_exists('name', $changes))
		{
			if (!is_string($changes['name']))
			{
				return $this->validationFailure();
			}
			$nameResult = $this->normalizeInputName($changes['name']);
			if (!$nameResult->isSuccess())
			{
				return $nameResult;
			}
			$fields[Scope::FIELD_NAME] = $nameResult->getData()['name'];
		}
		foreach ([
			'autoApplyScope' => Scope::FIELD_AUTO_APPLY_SCOPE,
			'onAdd' => Scope::FIELD_ON_ADD,
			'onUpdate' => Scope::FIELD_ON_UPDATE,
		] as $key => $field)
		{
			if (array_key_exists($key, $changes))
			{
				if (!is_bool($changes[$key]))
				{
					return $this->validationFailure();
				}
				$fields[$field] = $this->flag($changes[$key]);
			}
		}
		if ($fields === [])
		{
			return (new Result())->setData(['autoApplyScheduled' => false]);
		}

		try
		{
			$updateResult = \Bitrix\Ui\EntityForm\Scope::getInstance($actorUserId)->updateScope(
				$scopeId,
				$fields,
			);
		}
		catch (Throwable)
		{
			return $this->storageFailure('Could not update the CRM entity editor custom layout metadata.');
		}

		if (!$updateResult->isSuccess())
		{
			return $this->storageFailure('Could not update the CRM entity editor custom layout metadata.');
		}

		$readBackResult = $this->readRawScope($actorUserId, $scopeId);
		if (!$readBackResult->isSuccess())
		{
			return $this->partialStorageFailure(
				'The CRM entity editor custom layout metadata was only partially stored.',
			);
		}
		$readBack = $readBackResult->getData()['scope'];
		foreach ($fields as $field => $value)
		{
			$actual = $field === Scope::FIELD_NAME
				? (string)($readBack[$field] ?? '')
				: $this->flag($this->readFlag($readBack, $field));
			if ($actual !== $value)
			{
				return $this->partialStorageFailure(
					'The CRM entity editor custom layout metadata was only partially stored.',
				);
			}
		}

		return (new Result())->setData([
			'autoApplyScheduled' => $changes['autoApplyScope'] ?? $this->readFlag($current, Scope::FIELD_AUTO_APPLY_SCOPE),
		]);
	}

	/**
	 * @param array<int, array<string, mixed>> $layout
	 */
	public function updateScopeConfig(int $actorUserId, int $scopeId, string $configId, array $layout): Result
	{
		$rawResult = $this->readRawScope($actorUserId, $scopeId);
		if (!$rawResult->isSuccess())
		{
			return $rawResult;
		}
		if (!$this->belongsToContext($rawResult->getData()['scope'], $configId))
		{
			return $this->accessFailure();
		}

		try
		{
			$updateResult = \Bitrix\Ui\EntityForm\Scope::getInstance($actorUserId)->updateScopeConfig($scopeId, $layout);
		}
		catch (Throwable)
		{
			return $this->storageFailure('Could not update the CRM entity editor custom layout configuration.');
		}
		if (!$updateResult->isSuccess())
		{
			return $this->storageFailure('Could not update the CRM entity editor custom layout configuration.');
		}

		$readBackResult = $this->readRawScope($actorUserId, $scopeId);
		if (
			!$readBackResult->isSuccess()
			|| ($readBackResult->getData()['scope'][Scope::FIELD_LAYOUT] ?? null) !== $layout
		)
		{
			return $this->partialStorageFailure(
				'The CRM entity editor custom layout configuration was only partially stored.',
			);
		}

		return (new Result())->setData(['autoApplyScheduled' => false]);
	}

	/**
	 * @param array<int, array{type?: mixed, id?: mixed}|object> $accessCodes
	 */
	public function replaceAccessCodes(
		int $actorUserId,
		int $scopeId,
		string $configId,
		array $accessCodes,
	): Result
	{
		$scopeResult = $this->readScope($actorUserId, $scopeId, $configId);
		if (!$scopeResult->isSuccess())
		{
			return $scopeResult;
		}

		$canonicalResult = $this->canonicalizeInputAccessCodes($accessCodes);
		if (!$canonicalResult->isSuccess())
		{
			return $canonicalResult;
		}
		$canonicalAccessCodes = $canonicalResult->getData()['accessCodes'];
		if ($canonicalAccessCodes === [])
		{
			return $this->validationFailure();
		}
		/** @var Scope $scope */
		$scope = $scopeResult->getData()['scope'];

		try
		{
			$scopeStorage = \Bitrix\Ui\EntityForm\Scope::getInstance($actorUserId);
			$accessCodeMap = $this->toAccessCodeMap($canonicalAccessCodes);
			if ($scope->autoApplyScope)
			{
				$scopeStorage->updateScopeAccessCodes($scopeId, $accessCodeMap);
			}
			else
			{
				$replaceResult = $scopeStorage->replaceScopeAccessCodes($scopeId, $accessCodeMap);
				if (!$replaceResult->isSuccess())
				{
					return $this->partialStorageFailure(
						'The CRM entity editor custom layout access codes were only partially replaced.',
					);
				}
			}
		}
		catch (Throwable)
		{
			return $this->partialStorageFailure(
				'The CRM entity editor custom layout access codes were only partially replaced.',
			);
		}

		$readBackResult = $this->readCanonicalAccessCodes($actorUserId, $scopeId);
		if (
			!$readBackResult->isSuccess()
			|| $readBackResult->getData()['accessCodes'] !== $canonicalAccessCodes
		)
		{
			return $this->partialStorageFailure(
				'The CRM entity editor custom layout access codes were only partially replaced.',
			);
		}

		return (new Result())->setData(['autoApplyScheduled' => $scope->autoApplyScope]);
	}

	public function copyScope(int $actorUserId, int $scopeId, string $configId): Result
	{
		$sourceResult = $this->readScope($actorUserId, $scopeId, $configId);
		if (!$sourceResult->isSuccess())
		{
			if ($this->isInvalidPersistedAccessCode($sourceResult))
			{
				return $this->validationFailure();
			}

			return $sourceResult;
		}

		/** @var Scope $source */
		$source = $sourceResult->getData()['scope'];
		try
		{
			$newScopeId = \Bitrix\Ui\EntityForm\Scope::getInstance($actorUserId)->copyScope($scopeId, $configId);
		}
		catch (Throwable)
		{
			return $this->storageFailure('Could not copy the CRM entity editor custom layout.');
		}

		if (!is_int($newScopeId) || $newScopeId <= 0)
		{
			return $this->storageFailure('Could not copy the CRM entity editor custom layout.');
		}

		$copyResult = $this->readScope($actorUserId, $newScopeId, $configId);
		if (!$copyResult->isSuccess())
		{
			return $this->partialStorageFailure('The CRM entity editor custom layout was only partially copied.');
		}

		/** @var Scope $copy */
		$copy = $copyResult->getData()['scope'];
		if (
			$copy->layout !== $source->layout
			|| $copy->accessCodes !== $source->accessCodes
			|| $copy->autoApplyScope !== $source->autoApplyScope
			|| $copy->onAdd !== $source->onAdd
			|| $copy->onUpdate !== $source->onUpdate
		)
		{
			return $this->partialStorageFailure('The CRM entity editor custom layout was only partially copied.');
		}

		return (new Result())->setData([
			'scopeId' => $newScopeId,
			'autoApplyScheduled' => $copy->autoApplyScope,
		]);
	}

	public function deleteScope(int $actorUserId, int $scopeId, string $configId): Result
	{
		$scopeResult = $this->readRawScope($actorUserId, $scopeId);
		if (!$scopeResult->isSuccess())
		{
			return $scopeResult;
		}
		if (!$this->belongsToContext($scopeResult->getData()['scope'], $configId))
		{
			return $this->accessFailure();
		}

		try
		{
			$scope = \Bitrix\Ui\EntityForm\Scope::getInstance($actorUserId);
			$scope->removeByIds([$scopeId]);
		}
		catch (Throwable)
		{
			return $this->partialStorageFailure('The CRM entity editor custom layout was only partially deleted.');
		}

		try
		{
			$readBack = $scope->getById($scopeId);
		}
		catch (Throwable)
		{
			return $this->partialStorageFailure(
				'The CRM entity editor custom layout was only partially deleted.',
			);
		}

		if ($readBack !== null)
		{
			return $this->partialStorageFailure('The CRM entity editor custom layout was only partially deleted.');
		}

		return new Result();
	}

	/**
	 * @param array<int, array{type?: mixed, id?: mixed}|object> $accessCodes
	 */
	public function canonicalizeInputAccessCodes(array $accessCodes): Result
	{
		$canonical = [];
		foreach ($accessCodes as $accessCode)
		{
			if (is_array($accessCode))
			{
				$type = $accessCode['type'] ?? null;
				$id = $accessCode['id'] ?? null;
			}
			elseif (is_object($accessCode))
			{
				$type = $accessCode->type ?? null;
				$id = $accessCode->id ?? null;
			}
			else
			{
				return $this->validationFailure();
			}

			if (
				!is_string($type)
				|| (!isset(self::PREFIX_BY_TYPE[$type]) && $type !== self::TYPE_PROJECT_ACCESS_CODES)
			)
			{
				return $this->validationFailure();
			}
			$code = $this->normalizeInputAccessCode($type, $id);
			if ($code === null)
			{
				return $this->validationFailure();
			}
			if (strlen($code) > self::ACCESS_CODE_MAX_LENGTH)
			{
				return $this->validationFailure();
			}
			$canonical[$code] = $code;
			if (count($canonical) > self::ACCESS_CODE_MAX_COUNT)
			{
				return $this->validationFailure();
			}
		}

		ksort($canonical, SORT_STRING);

		$audience = [];
		foreach ($canonical as $code)
		{
			[$type, $id] = $this->unpackCanonicalAccessCode($code);
			$audience[] = [
				'type' => $type,
				'id' => $id,
			];
		}

		return (new Result())->setData([
			'accessCodes' => array_values($canonical),
			'audience' => $audience,
		]);
	}

	public function normalizeInputName(string $name): Result
	{
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH)
		{
			return $this->validationFailure();
		}

		return (new Result())->setData(['name' => $name]);
	}

	private function normalizeInputAccessCode(string $type, mixed $id): ?string
	{
		if (!is_int($id) && !is_string($id))
		{
			return null;
		}

		$id = trim((string)$id);
		if ($type === self::TYPE_PROJECT)
		{
			return $this->normalizeProjectAccessCode($id);
		}
		if ($type === self::TYPE_PROJECT_ACCESS_CODES)
		{
			return $this->normalizeProjectAccessCode($id, true);
		}
		if ($type === self::TYPE_DEPARTMENT)
		{
			return $this->normalizeDepartmentAccessCode($id, 'D', 'DR');
		}
		if ($type === self::TYPE_STRUCTURE_NODE)
		{
			return $this->normalizeDepartmentAccessCode($id, 'SND', 'SNDR');
		}

		return $this->normalizeSimpleAccessCode(self::PREFIX_BY_TYPE[$type][0], $id);
	}

	private function normalizeSimpleAccessCode(string $prefix, string $id): ?string
	{
		if (preg_match('/^' . preg_quote($prefix, '/') . '([1-9][0-9]*)$/D', $id) === 1)
		{
			return $id;
		}

		if (preg_match('/^[1-9][0-9]*$/D', $id) === 1)
		{
			return $prefix . $id;
		}

		return null;
	}

	private function normalizeDepartmentAccessCode(string $id, string $directPrefix, string $recursivePrefix): ?string
	{
		foreach ([$directPrefix, $recursivePrefix] as $prefix)
		{
			if (preg_match('/^' . preg_quote($prefix, '/') . '([1-9][0-9]*)$/D', $id) === 1)
			{
				return $id;
			}
		}

		$matches = [];
		if (preg_match('/^([1-9][0-9]*):F$/D', $id, $matches) === 1)
		{
			return $directPrefix . $matches[1];
		}

		if (preg_match('/^[1-9][0-9]*$/D', $id) === 1)
		{
			return $recursivePrefix . $id;
		}

		return null;
	}

	private function normalizeProjectAccessCode(string $id, bool $preserveRole = false): ?string
	{
		$matches = [];
		if (preg_match('/^SG([1-9][0-9]*)(_[AEK])?$/D', $id, $matches) === 1)
		{
			return 'SG' . $matches[1] . ($preserveRole ? ($matches[2] ?? '') : '');
		}

		if (preg_match('/^[1-9][0-9]*$/D', $id) === 1)
		{
			return 'SG' . $id;
		}

		return null;
	}

	public function belongsToMode(Scope $scope, Context $context): bool
	{
		return $context->mode === Context::MODE_CREATE
			? $scope->onAdd
			: $scope->onUpdate;
	}

	private function buildScope(int $scopeId, array $scope, array $accessCodes): Result
	{
		$canonicalAccessCodes = $this->normalizePersistedAccessCodes($accessCodes);
		if ($canonicalAccessCodes === null)
		{
			return $this->invalidPersistedAccessCodeFailure();
		}
		$layout = $scope[Scope::FIELD_LAYOUT] ?? null;
		if (!is_array($layout))
		{
			return $this->storageFailure('The stored CRM entity editor custom layout is invalid.');
		}

		return (new Result())->setData([
			'scope' => new Scope(
				id: $scopeId,
				name: (string)($scope[Scope::FIELD_NAME] ?? ''),
				layout: $layout,
				autoApplyScope: $this->readFlag($scope, Scope::FIELD_AUTO_APPLY_SCOPE),
				onAdd: $this->readFlag($scope, Scope::FIELD_ON_ADD),
				onUpdate: $this->readFlag($scope, Scope::FIELD_ON_UPDATE),
				accessCodes: $canonicalAccessCodes,
			),
		]);
	}

	private function readRawScope(int $actorUserId, int $scopeId): Result
	{
		$availabilityResult = $this->ensureAvailable();
		if (!$availabilityResult->isSuccess())
		{
			return $availabilityResult;
		}

		try
		{
			$scope = \Bitrix\Ui\EntityForm\Scope::getInstance($actorUserId)->getById($scopeId);
		}
		catch (Throwable)
		{
			return $this->storageFailure('Could not read the CRM entity editor custom layout.');
		}

		if (!is_array($scope))
		{
			return $this->accessFailure();
		}

		return (new Result())->setData(['scope' => $scope]);
	}

	private function readCanonicalAccessCodes(int $actorUserId, int $scopeId): Result
	{
		try
		{
			$rawAccessCodes = \Bitrix\Ui\EntityForm\Scope::getInstance($actorUserId)->getScopeAccessCodesByScopeId($scopeId);
		}
		catch (Throwable)
		{
			return $this->storageFailure('Could not read CRM entity editor custom layout access codes.');
		}

		$canonical = $this->normalizePersistedAccessCodes($rawAccessCodes);
		if ($canonical === null)
		{
			return $this->invalidPersistedAccessCodeFailure();
		}

		return (new Result())->setData(['accessCodes' => $canonical]);
	}

	/**
	 * @param array<int|string, mixed> $rawAccessCodes
	 * @return string[]|null
	 */
	private function normalizePersistedAccessCodes(array $rawAccessCodes): ?array
	{
		$canonical = [];
		foreach ($rawAccessCodes as $rawAccessCode)
		{
			if (
				!is_string($rawAccessCode)
				|| strlen($rawAccessCode) > self::ACCESS_CODE_MAX_LENGTH
				|| preg_match('/^(?:(?:U|D|DR|SND|SNDR)[1-9][0-9]*|SG[1-9][0-9]*(?:_[AEK])?)$/D', $rawAccessCode) !== 1
			)
			{
				return null;
			}
			$canonical[$rawAccessCode] = $rawAccessCode;
		}

		ksort($canonical, SORT_STRING);

		return array_values($canonical);
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function belongsToContext(array $scope, string $configId): bool
	{
		return ($scope['CATEGORY'] ?? null) === Context::UI_MODULE_CATEGORY
			&& ($scope['ENTITY_TYPE_ID'] ?? null) === $configId;
	}

	/**
	 * @param string[] $canonicalAccessCodes
	 * @return array<int, array{entityId: string, id: int|string}>
	 */
	private function toSelectorAccessCodes(array $canonicalAccessCodes): array
	{
		$result = [];
		foreach ($canonicalAccessCodes as $code)
		{
			[$type, $id] = $this->unpackCanonicalAccessCode($code);
			$result[] = [
				'entityId' => $type,
				'id' => $id,
			];
		}

		return $result;
	}

	/**
	 * @param string[] $canonicalAccessCodes
	 * @return array<string, string>
	 */
	private function toAccessCodeMap(array $canonicalAccessCodes): array
	{
		$result = [];
		foreach ($canonicalAccessCodes as $code)
		{
			[$type] = $this->unpackCanonicalAccessCode($code);
			$result[$code] = $type;
		}

		return $result;
	}

	/**
	 * @return array{string, int|string}
	 */
	private function unpackCanonicalAccessCode(string $code): array
	{
		if (preg_match('/^SG[1-9][0-9]*_[AEK]$/D', $code) === 1)
		{
			return [self::TYPE_PROJECT_ACCESS_CODES, $code];
		}

		foreach (self::PREFIX_BY_TYPE as $type => $prefixes)
		{
			foreach ($prefixes as $prefix)
			{
				if (str_starts_with($code, $prefix))
				{
					$id = (int)substr($code, strlen($prefix));

					return [
						$type,
						in_array($prefix, ['D', 'SND'], true) ? $id . ':F' : $id,
					];
				}
			}
		}

		return ['', 0];
	}

	/**
	 * @param string[] $accessCodes
	 * @param array<int, array<string, mixed>> $layout
	 */
	private function verifyScope(
		int $actorUserId,
		int $scopeId,
		string $configId,
		string $name,
		array $layout,
		array $accessCodes,
		bool $autoApplyScope,
		bool $onAdd,
		bool $onUpdate,
	): Result
	{
		$result = $this->readScope($actorUserId, $scopeId, $configId);
		if (!$result->isSuccess())
		{
			return $result;
		}

		/** @var Scope $scope */
		$scope = $result->getData()['scope'];
		if (
			$scope->name !== $name
			|| $scope->layout !== $layout
			|| $scope->accessCodes !== $accessCodes
			|| $scope->autoApplyScope !== $autoApplyScope
			|| $scope->onAdd !== $onAdd
			|| $scope->onUpdate !== $onUpdate
		)
		{
			return $this->storageFailure('The CRM entity editor custom layout postcondition failed.');
		}

		return new Result();
	}

	/**
	 * @param array<string, mixed> $scope
	 */
	private function readFlag(array $scope, string $field): bool
	{
		return ($scope[$field] ?? false) === true || ($scope[$field] ?? null) === 'Y';
	}

	private function flag(bool $value): string
	{
		return $value ? 'Y' : 'N';
	}

	private function isInvalidPersistedAccessCode(Result $result): bool
	{
		return ($result->getError()?->getCustomData()['reason'] ?? null) === 'invalid_access_code';
	}

	private function validationFailure(): Result
	{
		return (new Result())->addError(
			new Error(
				'The CRM entity editor custom layout input is invalid.',
				ErrorCode::VALIDATION_FAILED,
			),
		);
	}

	private function invalidPersistedAccessCodeFailure(): Result
	{
		return (new Result())->addError(
			new Error(
				'The stored CRM entity editor custom layout access code is invalid.',
				ErrorCode::STORAGE_FAILED,
				['reason' => 'invalid_access_code'],
			),
		);
	}

	private function accessFailure(): Result
	{
		return (new Result())->addError(
			new Error(
				'Access to the CRM entity editor layout is denied.',
				ErrorCode::ACCESS_DENIED,
			),
		);
	}

	private function partialStorageFailure(string $message): Result
	{
		return (new Result())->addError(
			new Error(
				$message,
				ErrorCode::STORAGE_FAILED,
				['partialStatePossible' => true],
			),
		);
	}

	private function storageFailure(string $message): Result
	{
		return (new Result())->addError(
			new Error($message, ErrorCode::STORAGE_FAILED),
		);
	}
}
