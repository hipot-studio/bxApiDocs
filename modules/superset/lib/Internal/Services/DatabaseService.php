<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main;
use Bitrix\Main\Web\Uri;
use Bitrix\Superset\Internal\Api;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Entities\TrinoConnection;
use Bitrix\Superset\Internal\Repositories\LocalServerRepository;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;

final class DatabaseService extends AbstractSupersetContext
{
	public function changeToken(string $token): Main\Result
	{
		return $this->updateConnectionProperty('bi.secret_key', $token);
	}

	public function refreshDomain(string $portalUrl): Main\Result
	{
		$result = $this->updateConnectionProperty('bi.server_url', urlencode($portalUrl));
		if (!$result->isSuccess())
		{
			return $result;
		}

		$this->server->setPortalUrl($portalUrl);
		$saveResult = (new LocalServerRepository())->save($this->server);
		if (!$saveResult->isSuccess())
		{
			$failedResult = new Main\Result();
			$failedResult->addErrors($saveResult->getErrors());

			return $failedResult;
		}

		$data = $result->getData();
		$data['portal_url'] = $portalUrl;
		$result->setData($data);

		return $result;
	}

	public function getTrinoDatabaseId(): Main\Result
	{
		$requestResult = $this->getDatabaseApi()->getDatabaseByName(Api\Database::TRINO_DATABASE_NAME, ['id']);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Getting database connection info');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		$databaseIds = $decoded['ids'] ?? [];
		$databaseId = (int)array_shift($databaseIds);
		if ($databaseId <= 0)
		{
			return $this->createErrorResult(
				'Trino database was not found',
				$requestResult,
				HttpStatus::INTERNAL_SERVER_ERROR
			);
		}

		$result = new Main\Result();
		$result->setData([
			'id' => $databaseId,
		]);

		return $result;
	}

	public function getTrinoConnection(): Main\Result
	{
		$connectionEntityResult = $this->getTrinoConnectionEntity();
		if (!$connectionEntityResult->isSuccess())
		{
			return $connectionEntityResult;
		}

		/** @var TrinoConnection $connectionEntity */
		$connectionEntity = $connectionEntityResult->getData()['entity'];

		$result = new Main\Result();
		$result->setData([
			'databaseId' => $connectionEntity->getDatabaseId(),
			'connection' => $connectionEntity->getConnection(),
		]);

		return $result;
	}

	public function updateConnectionProperty(string $propertyName, string $value): Main\Result
	{
		$connectionEntityResult = $this->getTrinoConnectionEntity();
		if (!$connectionEntityResult->isSuccess())
		{
			return $connectionEntityResult;
		}

		/** @var TrinoConnection $connectionEntity */
		$connectionEntity = $connectionEntityResult->getData()['entity'];
		$databaseId = $connectionEntity->getDatabaseId();
		$connection = $connectionEntity->getConnection();
		$loadedUri = (string)($connection['sqlalchemy_uri'] ?? '');
		if ($loadedUri === '')
		{
			return $this->createErrorResult('Empty sqlalchemy uri');
		}

		$uri = Uri::urnDecode($loadedUri);
		$uriObject = new Uri($uri);
		mb_parse_str($uriObject->getQuery(), $params);
		$properties = $this->decode((string)($params['session_properties'] ?? ''));
		if (!is_array($properties))
		{
			return $this->createErrorResult(
				'Empty session_properties in sqlalchemy uri. Portal url and bi token should be contained in sqlalchemy url'
			);
		}

		if (!array_key_exists($propertyName, $properties))
		{
			return $this->createErrorResult("Property '{$propertyName}' was not found in session_properties");
		}

		$oldValue = urlencode((string)$properties[$propertyName]);
		$newUri = str_replace($oldValue, $value, $loadedUri);

		$requestResult = $this->getDatabaseApi()->updateDatabaseConnection(
			$databaseId,
			[
				'sqlalchemy_uri' => $newUri,
			]
		);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, "Change {$propertyName} issue");
		}

		$result = new Main\Result();
		$result->setData([
			'databaseId' => $databaseId,
			'propertyName' => $propertyName,
			'value' => $value,
			'sqlalchemyUri' => $newUri,
		]);

		return $result;
	}

	private function getTrinoConnectionEntity(): Main\Result
	{
		$databaseIdResult = $this->getTrinoDatabaseId();
		if (!$databaseIdResult->isSuccess())
		{
			return $databaseIdResult;
		}

		$databaseId = (int)$databaseIdResult->getData()['id'];
		$requestResult = $this->getDatabaseApi()->getDatabaseConnection($databaseId);
		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createRequestErrorResult($requestResult, 'Getting database connection info');
		}

		$decoded = $this->decode($requestResult->getAnswer());
		if (!is_array($decoded) || !is_array($decoded['result'] ?? null))
		{
			return $this->createErrorResult('Invalid database connection response');
		}

		$result = new Main\Result();
		$result->setData([
			'entity' => new TrinoConnection($databaseId, $decoded['result']),
		]);

		return $result;
	}

	private function getDatabaseApi(): Api\Database
	{
		return new Api\Database($this->connector);
	}
}
