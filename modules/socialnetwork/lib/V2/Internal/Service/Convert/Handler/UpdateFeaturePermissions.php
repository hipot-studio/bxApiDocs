<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler;

use Bitrix\Socialnetwork\Collab\Property\Feature;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\Provider\FeatureProvider;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Exceptions\ProjectConvertFeaturePermissionException;
use CSocNetFeaturesPerms;

class UpdateFeaturePermissions implements HandlerInterface
{
	private const SET_PERMISSION_OPTIONS = [
		'checkFields' => false,
	];

	/**
	 * @throws ProjectConvertFeaturePermissionException
	 */
	public function __invoke(Workgroup $group): void
	{
		$groupId = $group->getId();

		if ($groupId <= 0)
		{
			throw new ProjectConvertFeaturePermissionException(
				sprintf('Group id is invalid: [%s]', $groupId)
			);
		}

		$permissionsOverrides = $this->getPermissionsOverrides();

		$currentFeatures = $this->getCurrentFeatures($groupId);
		$currentPermissions = $this->getCurrentPermissions($groupId);

		foreach ($permissionsOverrides as $featureName => $operations)
		{
			if (!isset($currentFeatures[$featureName]))
			{
				continue;
			}

			$featureId = $currentFeatures[$featureName]->id;
			$existingPermissions = $currentPermissions[$featureName] ?? [];

			foreach ($operations as $operationName => $operationValue)
			{
				if (isset($existingPermissions[$operationName]) && $existingPermissions[$operationName] === $operationValue)
				{
					continue;
				}

				$operationId = $this->setPermission(
					$featureId,
					$operationName,
					$operationValue,
					self::SET_PERMISSION_OPTIONS,
				);

				if ($operationId === false)
				{
					throw new ProjectConvertFeaturePermissionException(sprintf(
						'Error setting permissions for feature %s and operation %s',
						$featureName,
						$operationName,
					));
				}
			}
		}
	}

	/** @return Feature[] */
	protected function getCurrentFeatures(int $groupId): array
	{
		return FeatureProvider::getInstance()->getFeatures($groupId);
	}

	/**
	 * @return array<string, array<string, string>> featureName => [operationId => role]
	 */
	protected function getCurrentPermissions(int $groupId): array
	{
		$permissions = FeatureProvider::getInstance()->getPermissions($groupId);

		$result = [];
		foreach ($permissions as $permission)
		{
			foreach ($permission->toArray() as $featureName => $operations)
			{
				$result[$featureName] = $operations;
			}
		}

		return $result;
	}

	protected function setPermission(
		int $featureId,
		string $operationName,
		string $operationValue,
		array $options = [],
	): int|bool
	{
		return CSocNetFeaturesPerms::SetPerm($featureId, $operationName, $operationValue, $options);
	}

	private function getPermissionsOverrides(): array
	{
		return Container::getInstance()
			->getLegacyProjectFeaturePolicy()
			->getReadonlyPermissions();
	}
}
