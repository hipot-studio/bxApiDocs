<?php

namespace Bitrix\Superset\Internal\Api\Security;

use Bitrix\Main;
use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\RequestResult;

class Users
{
	private const USERS_API_LINK = '/api/v1/security/users/';

	private ?SupersetInstance $connector;

	public function __construct(SupersetInstance $connector)
	{
		$this->connector = $connector;
	}

	public function getUsers(?int $page = null, ?int $pageSize = null): RequestResult
	{
		$url = self::USERS_API_LINK;

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
			$url .= '?q=' . $query;
		}

		return $this->connector->get($url);
	}

	/**
	 * Gets user by id
	 *
	 * @param int $id
	 * @return RequestResult
	 */
	public function getUserById(int $id): RequestResult
	{
		$url = self::USERS_API_LINK . $id;
		return $this->connector->get($url);
	}

	/**
	 * Gets user by name
	 *
	 * @param string $name
	 * @return RequestResult
	 */
	public function getUserByName(string $name): RequestResult
	{
		$query = [
			'filters' => [
				[
					'col' => 'username',
					'opr' => 'eq',
					'value' => $name,
				],
			],
		];
		$query = Main\Web\Json::encode($query);
		$url = self::USERS_API_LINK . '?q=' . $query;

		return $this->connector->get($url);
	}

	/**
	 * Updates user by id
	 *
	 * Fields for update:
	 * 	"active": true,
	 * 	"email": "string",
	 * 	"first_name": "string",
	 * 	"last_name": "string",
	 * 	"password": "string",
	 * 	"roles": [
	 * 		0
	 * 	],
	 * 	"username": "string"
	 *
	 * @param int $id
	 * @param array $payload
	 * @return RequestResult
	 */
	public function updateUser(int $id, array $payload = []): RequestResult
	{
		$url = self::USERS_API_LINK . $id;
		return $this->connector->put($url, $payload);
	}

	/**
	 * Creates new user
	 *
	 * Fields for create:
	 * "active": true,
	 * "email": "string",
	 * "first_name": "string",
	 * "last_name": "string",
	 * "password": "string",
	 * "roles": [
	 * 		0
	 * ],
	 * "username": "string"
	 *
	 * @param array $payload
	 * @return RequestResult
	 */
	public function createUser(array $payload): RequestResult
	{
		return $this->connector->post(self::USERS_API_LINK, $payload);
	}
}
