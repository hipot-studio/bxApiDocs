<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder;

use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\User;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Integrator\Sender;
use Bitrix\Main\Result;

final class EntityLookup
{
	private const DATASET_NOT_FOUND_MESSAGE = 'Dataset with id=%d was not found. Call list_datasets to get a valid id.';

	public function chart(int $id, ?User $user = null): Result
	{
		return $this->entity(IntegratorFactory::getInstance()->getChartById($id, $user), $id);
	}

	public function dataset(int $id, ?User $user = null): Result
	{
		return $this->entity(
			IntegratorFactory::getInstance()->getDatasetById($id, $user),
			$id,
			sprintf(self::DATASET_NOT_FOUND_MESSAGE, $id),
		);
	}

	public function classify(IntegratorResponse $response, ?int $id = null, ?string $notFoundMessage = null): Result
	{
		$actAsRefusal = Refusal::actAsFailure($response->getErrors(), ['stage' => 'entity_lookup', 'id' => $id]);
		if ($actAsRefusal !== null)
		{
			return $actAsRefusal;
		}

		$status = $response->getStatus();
		if ($status === IntegratorResponse::STATUS_NOT_FOUND && $id > 0 && !$this->hasTransportFailure($response))
		{
			return Refusal::error(FailureCode::NotFound, $notFoundMessage ?? "Entity with id={$id} was not found.")
				->setData(['id' => $id])
			;
		}

		if ($response->hasErrors() || $status < 200 || $status >= 300)
		{
			return Refusal::unavailableBi($response->getErrors(), ['stage' => 'entity_lookup', 'id' => $id]);
		}

		return new Result();
	}

	private function entity(IntegratorResponse $response, int $id, ?string $notFoundMessage = null): Result
	{
		$result = $this->classify($response, $id, $notFoundMessage);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$entity = $response->getData();
		$entityId = is_array($entity) ? ($entity['id'] ?? null) : null;
		if (
			$id <= 0
			|| !(is_int($entityId) || (is_string($entityId) && preg_match('/^[1-9][0-9]*$/D', $entityId) === 1))
			|| (string)$entityId !== (string)$id
		)
		{
			return Refusal::unavailableBi([], ['stage' => 'entity_lookup.identity', 'id' => $id]);
		}

		return (new Result())->setData(['entity' => $entity]);
	}

	private function hasTransportFailure(IntegratorResponse $response): bool
	{
		$request = $response->getRequestResult();
		$errors = [...$response->getErrors(), ...($request?->getErrors() ?? [])];
		foreach ($errors as $error)
		{
			if (in_array($error->getCode(), [Sender::ERROR_MALFORMED_RESPONSE, Sender::ERROR_HTTPS_REQUIRED], true))
			{
				return true;
			}
		}

		return $request !== null
			&& ($request->getData()['status'] ?? null) !== IntegratorResponse::STATUS_NOT_FOUND
		;
	}
}
