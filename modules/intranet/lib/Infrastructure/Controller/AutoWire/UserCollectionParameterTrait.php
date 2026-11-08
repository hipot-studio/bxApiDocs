<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Controller\AutoWire;

use Bitrix\Intranet\Contract\Repository\UserRepository;
use Bitrix\Intranet\Entity\Collection\UserCollection;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Service\ServiceContainer;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

trait UserCollectionParameterTrait
{
	protected function createUserCollectionParameterFromIdList(): ExactParameter
	{
		return new ExactParameter(
			UserCollection::class,
			'userCollection',
			function($className, ?array $userIds = null): ?UserCollection {
				if (empty($userIds))
				{
					$this->addError(
						new Error(
							Loc::getMessage('INTRANET_CONTROLLER_AUTOWIRE_USER_COLLECTION_ERROR_NO_SELECTED_USERS'),
							'NO_SELECTED_USERS',
						),
					);

					return null;
				}

				return ServiceContainer::getInstance()->userRepository()->findUsersByIds($userIds);
			},
		);
	}

	protected function createUserCollectionParameterFromGrid(): ExactParameter
	{
		return new ExactParameter(
			UserCollection::class,
			'userCollection',
			function($className, ?array $fields = null): ?UserCollection {
				$isSystemUserPreset = ($fields['filter']['=EXTERNAL_AUTH_ID'] ?? null) === User::EXTERNAL_AUTH_ID_SYSTEM;

				return $this->makeUserCollectionFromGrid(
					$fields,
					static fn(UserRepository $repository, array $ids): UserCollection => $isSystemUserPreset
						? $repository->findSystemUsersByIds($ids)
						: $repository->findRealUsersByIds($ids),
					static fn(UserRepository $repository, array $filter): UserCollection => $isSystemUserPreset
						? $repository->findSystemUsersByFilter($filter)
						: $repository->findRealUsersByFilter($filter),
				);
			},
		);
	}

	protected function createSystemUserCollectionParameterFromGrid(): ExactParameter
	{
		return new ExactParameter(
			UserCollection::class,
			'systemUserCollection',
			function($className, ?array $fields = null): ?UserCollection {
				$isSystemUserPreset = ($fields['filter']['=EXTERNAL_AUTH_ID'] ?? null) === User::EXTERNAL_AUTH_ID_SYSTEM;

				return $this->makeUserCollectionFromGrid(
					$fields,
					static fn(UserRepository $repository, array $ids): UserCollection => $repository->findSystemUsersByIds($ids),
					static fn(UserRepository $repository, array $filter): UserCollection => $isSystemUserPreset
						? $repository->findSystemUsersByFilter($filter)
						: new UserCollection(),
				);
			},
		);
	}

	private function makeUserCollectionFromGrid(
		?array $fields,
		\Closure $findByIds,
		\Closure $findByFilter,
	): ?UserCollection
	{
		if (empty($fields) || empty($fields['userIds']))
		{
			$this->addError(
				new Error(
					Loc::getMessage('INTRANET_CONTROLLER_AUTOWIRE_USER_COLLECTION_ERROR_NO_SELECTED_USERS'),
					'NO_SELECTED_USERS',
				),
			);

			return null;
		}

		$userRepository = ServiceContainer::getInstance()->userRepository();

		if (($fields['isSelectedAllRows'] ?? null) === 'N')
		{
			return $findByIds($userRepository, $fields['userIds']);
		}

		if (!empty($fields['filter']) && is_array($fields['filter']))
		{
			return $findByFilter($userRepository, $fields['filter']);
		}

		$this->addError(new Error('invalid params'));

		return null;
	}
}
