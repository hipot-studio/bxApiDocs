<?php
declare(strict_types=1);

namespace Bitrix\AI\Cloud;

use Bitrix\AI\Facade\User;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

/**
 * Class SendQuery
 * Provides methods for sending queries to AI service.
 */
final class SendQuery extends BaseSender
{
	/**
	 * Send query to AI service.
	 * @param array $body Query body.
	 * @return Result
	 * @throws ArgumentException
	 */
	public function queue(array $body): Result
	{
		$cloudRegistrationData = (new Configuration())->getCloudRegistrationData();
		if (!$cloudRegistrationData)
		{
			$result = new Result();
			$result->addError(new Error('There is empty cloud registration data.'));

			return $result;
		}

		$data = [
			'clientId' => $cloudRegistrationData->clientId,
			'queryBody' => $body,
			'userLanguage' => User::getUserLanguage()
		];

		/** @see \Bitrix\AiProxy\Controller\Query::queueAction */
		return $this->performRequest('aiproxy.Query.queue', $data);
	}

	protected function buildResult(HttpClient $httpClient, bool $requestResult): Result
	{
		$result = parent::buildResult($httpClient, $requestResult);
		if (!$requestResult || !empty($result->getData()))
		{
			return $result;
		}

		$response = $httpClient->getResult();
		$responseArray = [];
		if (!empty($response))
		{
			try
			{
				$responseArray = Json::decode($response);
			}
			catch (ArgumentException $exception)
			{
				$this->logMsg('Error in SendQuery::buildResult ' . $exception->getMessage());

				return $result;
			}
		}

		if (!empty($responseArray['data']) && is_array($responseArray['data']))
		{
			$result->setData($responseArray['data']);
		}

		return $result;
	}

	protected function logMsg(string $msg): void
	{
		AddMessage2Log($msg);
	}
}