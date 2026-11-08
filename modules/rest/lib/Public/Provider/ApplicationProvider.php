<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Rest\Application;
use Bitrix\Rest\Exceptions\InvalidOperationException;
use Bitrix\Rest\Exceptions\ObjectNotFoundException;
use Bitrix\Rest\Marketplace\Immune;
use Bitrix\Rest\Internal\Access\AppAccessChecker;
use Bitrix\Rest\Internal\Entity\Application\AppCollection;
use Bitrix\Rest\Internal\Entity\Application\App;
use Bitrix\Rest\Internal\Entity\Application\AppOrigin;
use Bitrix\Rest\Internal\Repository\Application\AppFilter;
use Bitrix\Rest\Internal\Repository\Application\AppPager;
use Bitrix\Rest\Internal\Repository\Application\AppSelect;
use Bitrix\Rest\Internal\Repository\Application\AppSort;
use Bitrix\Rest\Public\Contract\Application\OAuthToken;
use Bitrix\Rest\Public\Provider\Dto\ApplicationData;
use Bitrix\Rest\Public\Provider\Dto\OAuthTokenDto;
use Bitrix\Rest\Public\Provider\Mapper\ApplicationDataMapper;
use Bitrix\Rest\Public\Provider\Params\ApplicationFilter;
use Bitrix\Rest\Public\Provider\Params\ApplicationOrigin;
use Bitrix\Rest\Public\Provider\Params\ApplicationSelect;
use Bitrix\Rest\Internal\Repository;
use Bitrix\Main\DI\ServiceLocator;

class ApplicationProvider
{
	private const CODE_BATCH_SIZE = 500;

	public function __construct(
		private readonly ?Repository\Application\AppRepository $repository = null,
		private readonly ApplicationDataMapper $mapper = new ApplicationDataMapper(),
	)
	{
	}

	public function getInstalledCount(): int
	{
		return $this->getRepo()->getInstalledAppsCount();
	}

	public function getCount(ApplicationFilter $filter, int $cacheTtl = 0): int
	{
		return $this->getRepo()->getCount($this->toAppFilter($filter), $cacheTtl);
	}

	public function getCodes(ApplicationFilter $filter): array
	{
		$appFilter = $this->toAppFilter($filter);
		$codes = [];
		$afterId = 0;

		do
		{
			$rows = $this->getRepo()->getCodePage($appFilter, $afterId, self::CODE_BATCH_SIZE);

			foreach ($rows as $row)
			{
				$afterId = max($afterId, (int)$row['ID']);
				$code = $row['CODE'];
				if (is_string($code) && $code !== '')
				{
					$codes[] = $code;
				}
			}
		}
		while (count($rows) === self::CODE_BATCH_SIZE);

		return array_values(array_unique($codes));
	}

	public function hasAny(ApplicationFilter $filter): bool
	{
		return $this->getRepo()->exists($this->toAppFilter($filter));
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

	public function findById(int $id, bool $withLanguages = false): ?ApplicationData
	{
		return $this->findProjection(
			fn(): ?array => $this->getRepo()->findRowById($id),
			$withLanguages,
		);
	}

	/**
	 * Costs more than findById() and findByClientId(): b_rest_app has no index on CODE.
	 */
	public function findByCode(string $code, bool $withLanguages = false): ?ApplicationData
	{
		return $this->findProjection(
			fn(): ?array => $this->getRepo()->findRowByCode($code),
			$withLanguages,
		);
	}

	public function findByClientId(string $clientId, bool $withLanguages = false): ?ApplicationData
	{
		return $this->findProjection(
			fn(): ?array => $this->getRepo()->findRowByClientId($clientId),
			$withLanguages,
		);
	}

	/**
	 * @param \Closure(): ?array<string, mixed> $readRow
	 */
	private function findProjection(\Closure $readRow, bool $withLanguages): ?ApplicationData
	{
		$row = $readRow();
		if ($row === null)
		{
			return null;
		}

		$languageRows = $withLanguages
			? $this->getRepo()->findLangRowsByAppId((int)$row[ApplicationDataMapper::ORM_FIELD_ID])
			: null
		;

		return $this->mapper->convertFromRow($row, $languageRows);
	}

	private function toAppFilter(ApplicationFilter $filter): AppFilter
	{
		$appFilter = (new AppFilter())
			->installed($filter->installed ? true : null)
			->attributes($filter->attributes === [] ? null : $filter->attributes)
			->origin($this->toAppOrigin($filter->origin))
		;

		if ($filter->excludeImmune)
		{
			$immuneCodes = array_values(array_unique(array_filter(
				$this->getImmuneAppCodes(),
				static fn(mixed $code): bool => is_string($code) && $code !== '',
			)));
			if ($immuneCodes !== [])
			{
				$appFilter->where(Query::filter()->whereNotIn('CODE', $immuneCodes));
			}
		}

		return $appFilter;
	}

	private function toAppOrigin(?ApplicationOrigin $origin): ?AppOrigin
	{
		return match ($origin)
		{
			ApplicationOrigin::Local => AppOrigin::Local,
			ApplicationOrigin::Marketplace => AppOrigin::Marketplace,
			null => null,
		};
	}

	protected function getImmuneAppCodes(): array
	{
		return Immune::getList();
	}

	private function getAccessChecker(int $userId): AppAccessChecker
	{
		return new AppAccessChecker($userId);
	}

	private function getRepo(): Repository\Application\AppRepository
	{
		return $this->repository ?? ServiceLocator::getInstance()->get('rest.repository.app');
	}
}
