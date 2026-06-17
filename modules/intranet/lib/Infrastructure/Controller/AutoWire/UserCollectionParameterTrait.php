<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Controller\AutoWire;

use Bitrix\Intranet\Entity\Collection\UserCollection;
use Bitrix\Intranet\Service\ServiceContainer;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

trait UserCollectionParameterTrait
{
	protected function createUserCollectionParameterFromGrid(): ExactParameter
	{
		return new ExactParameter(
			UserCollection::class,
			'userCollection',
			function($className, ?array $fields = null): ?UserCollection {
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
					return $userRepository->findUsersByIds($fields['userIds']);
				}

				if (!empty($fields['filter']) && is_array($fields['filter']))
				{
					return $userRepository->findUsersByFilter($fields['filter']);
				}

				$this->addError(new Error('invalid params'));

				return null;
			},
		);
	}
}
