<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\SemanticSearch;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\SemanticSearch\Payload\Document;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\SemanticSearch\Payload\DocumentCollection;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

/**
 * Class SemanticSearchService
 * current service based on the request on https://git.bx/zolotukhin/b24-semantic-search-api
 *
 * @package Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\SemanticSearch
 */
class SemanticSearchService implements SemanticSearchInterface
{
	private readonly string $serviceUrl;
	private readonly string $portalId;

	private const INDEX_PATH = '/index';
	private const SEARCH_PATH = '/search';

	private const DEFAULT_LIMIT = 10;

	private const BATCH_SIZE = 100;

	/**
	 * @throws SystemException
	 */
	public function __construct()
	{
		$configuration = \Bitrix\Main\Config\Configuration::getValue('semantic_search');

		if (!isset($configuration['url']))
		{
			throw new SystemException('Semantic search service URL is not configured.');
		}

		$this->serviceUrl = $configuration['url'];
		$this->portalId = Application::getInstance()->getLicense()->getHashLicenseKey();
	}

	/**
	 * @param DocumentCollection $documents
	 * @param Scope $scope
	 *
	 * @return Result
	 * @throws ArgumentException
	 */
	public function add(DocumentCollection $documents, Scope $scope): Result
	{
		$url = $this->serviceUrl . self::INDEX_PATH;
		$body = [
			'portal_id' => $this->portalId,
			'scope' => (string)$scope,
			'documents' => $documents->getAll(),
		];

		return $this->sendRequest($url, $body);
	}

	/**
	 * @param string $text
	 * @param Scope $scope
	 * @param int $limit
	 *
	 * @return Result
	 * @throws ArgumentException
	 */
	public function search(string $text, Scope $scope, int $limit = self::DEFAULT_LIMIT): Result
	{
		if ($limit <= 0)
		{
			$limit = self::DEFAULT_LIMIT;
		}

		$url = $this->serviceUrl . self::SEARCH_PATH;
		$body = [
			'portal_id' => $this->portalId,
			'text' => $text,
			'scope' => (string)$scope,
			'limit' => $limit,
		];

		return $this->sendRequest($url, $body, true);
	}

	/**
	 * @param string $url
	 * @param array $body
	 * @param bool $setDataOnSuccess
	 *
	 * @return Result
	 * @throws ArgumentException
	 */
	private function sendRequest(string $url, array $body, bool $setDataOnSuccess = false): Result
	{
		$httpClient = new HttpClient();
		$httpClient->setHeader('Content-Type', 'application/json');
		$responseJson = $httpClient->post($url, Json::encode($body));
		$result = new Result();
		try
		{
			if ($responseJson === false)
			{
				$errors = $httpClient->getError();
				foreach ($errors as $code => $message)
				{
					$result->addError(new Error($message, $code));
				}

				return $result;
			}

			try
			{
				$response = Json::decode($responseJson);
			}
			catch (ArgumentException $e)
			{
				$result->addError(new Error($e->getMessage(), $e->getCode()));

				return $result;
			}

			if (isset($response['error']))
			{
				$result->addError(new Error($response['error']));

				return $result;
			}

			if ($setDataOnSuccess && isset($response['search_result']))
			{
				$result->setData(
					[
						'documentCollection' => DocumentCollection::fromArray($response['search_result']),
					],
				);
			}

			return $result;

		}
		finally
		{
			if (!$result->isSuccess())
			{
				$errorMessages = [];
				foreach ($result->getErrors() as $error)
				{
					$errorMessages[] = $error->getMessage();
				}

				Container::getDefaultLogger()->error(
					'Error while sending request to Semantic Search service: ' . implode(', ', $errorMessages),
				);
			}
		}
	}

	/**
	 * @param \Traversable $items
	 * @param Scope $scope
	 * @param callable $documentFactory
	 *
	 * @return Result
	 * @throws ArgumentException
	 */
	public function addBatch(\Traversable $items, Scope $scope, callable $documentFactory): Result
	{
		$documentCollection = new DocumentCollection();
		$counter = 0;

		foreach ($items as $item)
		{
			$document = $documentFactory($item);
			if ($document instanceof Document)
			{
				$documentCollection->add($document);
				$counter++;
			}

			if ($counter >= self::BATCH_SIZE)
			{
				if ($documentCollection->count() > 0)
				{
					$result = $this->add($documentCollection, $scope);
				}

				$documentCollection = new DocumentCollection();
				$counter = 0;
			}
		}

		if ($documentCollection->count() > 0)
		{
			$result = $this->add($documentCollection, $scope);
		}

		return $result ?? new Result();
	}
}
