<?php

namespace Bitrix\Superset\Internal\Api\Security;

use Bitrix\Main;
use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\RequestResult;

class Roles
{
	private const ROLES_API_LINK = '/api/v1/security/roles/';

	private ?SupersetInstance $connector;

	public function __construct(SupersetInstance $connector)
	{
		$this->connector = $connector;
	}

	/**
	 * Gets all roles in superset instance
	 *
	 * @param int|null $page
	 * @param int|null $pageSize
	 * @return RequestResult
	 */
	public function getRoles(?int $page = null, ?int $pageSize = null): RequestResult
	{
		$url = self::ROLES_API_LINK;

		if ($page || $pageSize)
		{
			$query = [];
			if ($page)
			{
				$query['page'] = $page;
			}

			if ($pageSize)
			{
				$query['page_size'] = $pageSize;
			}

			$query = Main\Web\Json::encode($query);
			$url = self::ROLES_API_LINK . '?q=' . $query;
		}

		return $this->connector->get($url);
	}
}
