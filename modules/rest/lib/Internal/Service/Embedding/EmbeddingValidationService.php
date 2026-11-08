<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Embedding;

use Bitrix\Main\ArgumentTypeException as MainArgumentTypeException;
use Bitrix\Main\Loader;
use Bitrix\Rest\Exceptions\ArgumentException;
use Bitrix\Rest\Exceptions\ArgumentNullException;
use Bitrix\Rest\Exceptions\ArgumentTypeException;
use Bitrix\Rest\HandlerHelper;
use Bitrix\Rest\Internal\Repository\EmbeddingRepository;
use Bitrix\Rest\PlacementTable;
use Bitrix\Rest\Public\Exception\Embedding\EmbeddingAlreadyExistsException;
use Bitrix\Rest\Public\Provider\PlacementProvider;
use Bitrix\Rest\RestException;

final class EmbeddingValidationService
{
	public function __construct(
		private EmbeddingRepository $repository = new EmbeddingRepository(),
		private PlacementProvider $placementProvider = new PlacementProvider(),
		private ApplicationOnlyPlacements $applicationOnlyPlacements = new ApplicationOnlyPlacements(),
	)
	{
	}

	/**
	 * @return array{
	 *     placement: string,
	 *     handler: string,
	 *     targetUserId: int,
	 *     options: array,
	 *     languages: list<EmbeddingLanguageDto>,
	 *     placementInfo: array
	 * }
	 * @param list<EmbeddingLanguageDto> $languages
	 */
	public function prepare(
		string $moduleId,
		string $entityType,
		string $placement,
		string $handler,
		int $targetUserId,
		array $options,
		array $languages,
	): array
	{
		$placement = mb_strtoupper($placement);
		if ($placement === '' || $placement === PlacementTable::PLACEMENT_DEFAULT)
		{
			throw new ArgumentException('The placement code has an incorrect value', 'placement');
		}

		$placementInfo = $this->resolvePlacement($placement);

		if (
			!EmbeddingOwnerResolver::isAppOwnerType($moduleId, $entityType)
			&& $this->applicationOnlyPlacements->contains($placement)
		)
		{
			throw new ArgumentException('The placement does not support module embeddings', 'placement');
		}

		if ($handler === '')
		{
			throw new ArgumentNullException('handler');
		}
		HandlerHelper::checkCallback($handler);

		$targetUserId = $targetUserId > 0 ? $targetUserId : PlacementTable::DEFAULT_USER_ID_VALUE;
		$this->validateTargetUser($targetUserId, $placementInfo);
		$options = $this->prepareOptions($options, $placementInfo['options'] ?? []);

		return [
			'placement' => $placement,
			'handler' => $handler,
			'targetUserId' => $targetUserId,
			'options' => $options,
			'languages' => $languages,
			'placementInfo' => $placementInfo,
		];
	}

	/**
	 * @throws EmbeddingAlreadyExistsException
	 */
	public function validateLimitAndDuplicate(
		string $moduleId,
		string $entityType,
		string $entityId,
		string $placement,
		string $handler,
		int $targetUserId,
		array $placementInfo,
	): void
	{
		if ($this->repository->hasDuplicate(
			$moduleId,
			$entityType,
			$entityId,
			$placement,
			$handler,
		))
		{
			throw new EmbeddingAlreadyExistsException();
		}

		$maxCount = (int)($placementInfo['max_count'] ?? 0);
		if ($maxCount <= 0)
		{
			return;
		}

		$count = $this->repository->countForLimit(
			$moduleId,
			$entityType,
			$entityId,
			$placement,
			$targetUserId,
			($placementInfo['user_mode'] ?? false) === true,
		);

		if ($count >= $maxCount)
		{
			throw new RestException(
				'Placement max count: ' . $maxCount,
				PlacementTable::ERROR_PLACEMENT_MAX_COUNT,
			);
		}
	}

	public function validateRegisterCallback(
		string $moduleId,
		string $entityType,
		string $entityId,
		string $placement,
		string $handler,
		int $targetUserId,
		array $options,
		array $placementInfo,
	): void
	{
		$this->invokeRegisterCallback(
			$this->makeRegisterPayload(
				$moduleId,
				$entityType,
				$entityId,
				$placement,
				$handler,
				$targetUserId,
				$options,
			),
			$placementInfo,
		);
	}

	private function resolvePlacement(string $placement): array
	{
		$placementInfo = $this->placementProvider->getAll(true)[$placement] ?? null;
		if (!is_array($placementInfo) || (bool)($placementInfo['private'] ?? false))
		{
			throw new RestException('Placement not found', PlacementTable::ERROR_PLACEMENT_NOT_FOUND);
		}

		return $placementInfo;
	}

	private function validateTargetUser(int $targetUserId, array $placementInfo): void
	{
		if ($targetUserId > 0 && ($placementInfo['user_mode'] ?? false) !== true)
		{
			throw new RestException('User mode is not available.', PlacementTable::ERROR_PLACEMENT_USER_MODE);
		}
	}

	/**
	 * @param array<string, mixed> $declaredOptions
	 */
	private function prepareOptions(array $options, array $declaredOptions): array
	{
		$result = [];
		foreach ($declaredOptions as $optionName => $optionSetting)
		{
			$hasValue = array_key_exists($optionName, $options);
			$hasDefault = is_array($optionSetting) && isset($optionSetting['default']);
			if (
				!$hasValue
				&& is_array($optionSetting)
				&& (bool)($optionSetting['require'] ?? false)
			)
			{
				throw new ArgumentNullException($optionName);
			}

			$value = $options[$optionName] ?? ($hasDefault ? $optionSetting['default'] : null);
			$type = is_array($optionSetting) ? ($optionSetting['type'] ?? null) : $optionSetting;

			switch ($type)
			{
				case 'int':
					$result[$optionName] = (int)$value;

					break;

				case 'string':
					$result[$optionName] = (string)$value;

					break;

				case 'array':
					if (!is_array($value))
					{
						throw new ArgumentTypeException($optionName, 'array');
					}
					$result[$optionName] = $this->prepareCompositeOptions($value, $optionSetting);

					break;
			}
		}

		return $result;
	}

	private function prepareCompositeOptions(array $value, array $setting): array
	{
		$typeValues = $setting['typeValue'] ?? null;
		if (!is_array($typeValues))
		{
			throw new MainArgumentTypeException('typeValue', 'array');
		}

		$typeValues = array_map(
			static fn(mixed $type): mixed => $type === 'int' ? 'integer' : $type,
			$typeValues,
		);
		$typeValues = array_intersect($typeValues, ['integer', 'string', 'array']);

		$result = [];
		foreach ($value as $key => $item)
		{
			$itemType = gettype($item);
			if ($itemType !== 'array' && in_array($itemType, $typeValues, true))
			{
				$result[$key] = $item;
			}
		}

		foreach ($setting as $key => $nestedSetting)
		{
			if (
				!is_array($nestedSetting)
				|| !in_array('array', $typeValues, true)
				|| !isset($nestedSetting['type'], $nestedSetting['typeValue'])
				|| !isset($value[$key])
			)
			{
				continue;
			}

			$result[$key] = $this->prepareCompositeOptions($value[$key], $nestedSetting);
		}

		return $result;
	}

	private function makeRegisterPayload(
		string $moduleId,
		string $entityType,
		string $entityId,
		string $placement,
		string $handler,
		int $targetUserId,
		array $options,
	): array
	{
		$payload = [
			'USER_ID' => $targetUserId,
			'PLACEMENT' => $placement,
			'PLACEMENT_HANDLER' => $handler,
			'OPTIONS' => $options,
			'MODULE_ID' => $moduleId,
			'ENTITY_TYPE' => $entityType,
			'ENTITY_ID' => $entityId,
		];

		$appId = EmbeddingOwnerResolver::resolveAppId($payload);
		if ($appId !== null)
		{
			$payload['APP_ID'] = $appId;
		}

		return $payload;
	}

	private function invokeRegisterCallback(array $payload, array $placementInfo): void
	{
		$callback = $placementInfo['registerCallback']['callback'] ?? null;
		if (empty($callback))
		{
			return;
		}

		$moduleId = $placementInfo['registerCallback']['moduleId'] ?? null;
		if (!is_string($moduleId) || $moduleId === '' || !Loader::includeModule($moduleId) || !is_callable($callback))
		{
			return;
		}

		$result = call_user_func($callback, $payload, $placementInfo);
		if (
			is_array($result)
			&& !empty($result['error'])
			&& !empty($result['error_description'])
		)
		{
			throw new RestException(
				(string)$result['error_description'],
				(string)$result['error'],
			);
		}
	}
}
