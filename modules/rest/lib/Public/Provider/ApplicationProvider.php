<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Rest\Application;
use Bitrix\Rest\Exceptions\InvalidOperationException;
use Bitrix\Rest\Exceptions\ObjectNotFoundException;
use Bitrix\Rest\Internal\Access\AppAccessChecker;
use Bitrix\Rest\Internal\Entity\Application\AppCollection;
use Bitrix\Rest\Internal\Entity\Application\App;
use Bitrix\Rest\Internal\Repository\Application\AppFilter;
use Bitrix\Rest\Internal\Repository\Application\AppPager;
use Bitrix\Rest\Internal\Repository\Application\AppSelect;
use Bitrix\Rest\Internal\Repository\Application\AppSort;
use Bitrix\Rest\Public\Contract\Application\OAuthToken;
use Bitrix\Rest\Public\Provider\Dto\OAuthTokenDto;
use Bitrix\Rest\Public\Provider\Params\ApplicationSelect;
use Bitrix\Rest\Internal\Repository;
use Bitrix\Main\DI\ServiceLocator;

class ApplicationProvider
{
	public function getInstalledCount(): int
	{
		return $this->getRepo()->getInstalledAppsCount();
	}

	public function getByClientId(int $userId, string $clientId): ?App
	{
		$app = $this->getRepo()->getByClientId($clientId);
		if ($app === null)
		{
			return null;
		}

		$accessChecker = $this->getAccessChecker($userId);

		if ($accessChecker->canAccessApp($app))
		{
			return $app;
		}

		return null;
	}

	public function getInstalledList(
		int $userId,
		GridParams $gridParams,
		?array $attributes = null,
	): AppCollection
	{
		$accessChecker = $this->getAccessChecker($userId);

		if (!$accessChecker->canViewInstalledList())
		{
			return new AppCollection();
		}

		$applicationSelect = new ApplicationSelect($gridParams->getSelect() ?? []);

		$filter = (new AppFilter())
			->active()
			->installed()
			->attributes($attributes)
			->where($gridParams->getFilter())
		;

		$select = (new AppSelect())
			->attributes($applicationSelect->hasAttributes())
			->langs($applicationSelect->hasLangs())
		;

		$sort = new AppSort($gridParams->getSort() ?? []);
		$pager = new AppPager($gridParams->getOffset(), $gridParams->getLimit());

		return $this->getRepo()->getList(
			filter: $filter,
			select: $select,
			sort: $sort,
			pager: $pager,
		);
	}

	public function getPersonalInstalledList(int $userId, GridParams $gridParams, ?array $attributes = null): AppCollection
	{
		$filter = (new AppFilter())
			->active()
			->installed()
			->ownerUserId($userId)
			->attributes($attributes)
			->where($gridParams->getFilter())
		;

		$sort = new AppSort($gridParams->getSort() ?? []);
		$pager = new AppPager($gridParams->getOffset(), $gridParams->getLimit());

		return $this->getRepo()->getList(
			filter: $filter,
			sort: $sort,
			pager: $pager,
		);
	}

	public function getOAuthToken(
		string $clientId,
		array|string $scope,
		int $userId,
		array $additionalParams = [],
	): OAuthToken
	{
		$app = $this->getRepo()->getByClientId($clientId);
		if (!$app)
		{
			throw new ObjectNotFoundException(
				'Application not found by clientId: ' . $clientId
			);
		}

		$accessChecker = $this->getAccessChecker($userId);
		if (!$accessChecker->canAccessApp($app))
		{
			throw new AccessDeniedException(
				'User does not have access to application'
			);
		}
		$scope = is_array($scope) ? implode(',', $scope) : $scope;

		$authResult = Application::getAuthProvider()->get(
			$clientId,
			$scope,
			$additionalParams,
			$userId,
		);

		if (!is_array($authResult) || empty($authResult['access_token']))
		{
			throw new InvalidOperationException(
				'User has not got access to application'
			);
		}

		return OAuthTokenDto::fromArray($authResult);
	}

	private function getAccessChecker(int $userId): AppAccessChecker
	{
		return new AppAccessChecker($userId);
	}

	private function getRepo(): Repository\Application\AppRepository
	{
		return ServiceLocator::getInstance()->get('rest.repository.app');
	}
}
