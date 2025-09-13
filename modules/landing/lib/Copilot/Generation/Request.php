<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation;

use Bitrix\Landing\Copilot\Connector\AI\IConnector;
use Bitrix\Landing\Copilot\Connector\AI\Prompt;
use Bitrix\Landing\Copilot\Connector\AI\RequestLimiter;
use Bitrix\Landing\Copilot\Converter;
use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Type\Errors;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Copilot\Model\EO_Requests;
use Bitrix\Landing\Copilot\Model\RequestsTable;
use Bitrix\Landing\Copilot\Model\RequestToStepTable;
use Bitrix\Main;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\ORM\Query\Filter;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web;
use Exception;

class Request
{
	// todo: get individual time from step
	private const MAX_EXPECTED_TIME = 75;

	private int $generationId;
	private int $stepId;
	private ?int $id;
	private ?string $hash = null;
	private ?array $result = null;
	private ?Generation\Error $error = null;
	private bool $isDeleted = false;
	private ?int $stepRelationId;
	private DateTime $dateCreate;
	private DateTime $dateReceive;

	private Type\RequestStatus $status = Type\RequestStatus::New;
	private RequestLimiter $requestLimiter;

	public function __construct(int $generationId, int $stepId)
	{
		$this->generationId = $generationId;
		$this->stepId = $stepId;
	}

	/**
	 * Send request to AI provider
	 * @param Prompt $prompt
	 * @param IConnector $connector
	 * @return bool
	 * @throws GenerationException
	 */
	public function send(Prompt $prompt, IConnector $connector): bool
	{
		if (!Generation::checkExists($this->generationId))
		{
			return false;
		}

		if ($this->status->value >= Type\RequestStatus::Sent->value)
		{
			return false;
		}

		$result = $connector->request($prompt);
		if (!$result->isSuccess())
		{
			$this->processError($result->getError());
		}

		$this->status = Type\RequestStatus::Sent;
		$data = $result->getData();

		// is queued
		if (isset($data['hash']) && $data['hash'])
		{
			$this->hash = $data['hash'];

			return $this->save();
		}

		// is realtime answer
		if (isset($data['result']) && $data['result'])
		{
			$result = null;
			if (is_string($data['result']))
			{
				try
				{
					$result = Converter\Json::expandJsonString($data['result']);
					$result = Web\Json::decode($result);
				}
				catch (Exception)
				{
					$error = Generation\Error::createError(Errors::requestError);

					return $this->saveError($error);
				}
			}

			return $this->saveResult($result);
		}

		if (isset($data['error']) && $data['error'])
		{
			$error = Generation\Error::createError(Errors::requestError);
			$error->message .= ': ' . $data['error'];

			return $this->saveError($error);
		}

		return false;
	}

	/**
	 * @param Main\Error|null $error
	 * @return void
	 * @throws GenerationException
	 */
	private function processError(?Main\Error $error): void
	{
		if ($error === null)
		{
			throw new GenerationException(GenerationErrors::notSendRequest);
		}

		$errorText = $this->getRequestLimiter()->getTextFromError($error);

		if ($errorText)
		{
			$params = [
				'errorText' => $errorText,
			];

			throw new GenerationException(GenerationErrors::requestQuotaExceeded, $error->getMessage(), $params);
		}

		throw new GenerationException(GenerationErrors::errorInRequest, $error->getMessage(), null);
	}

	/**
	 * Retrieves the RequestLimiter instance, initializing it if not already set.
	 *
	 * @return RequestLimiter The RequestLimiter instance.
	 */
	private function getRequestLimiter(): RequestLimiter
	{
		if (empty($this->requestLimiter))
		{
			$this->requestLimiter = new RequestLimiter();
		}

		return $this->requestLimiter;
	}

	public function setApplied(): bool
	{
		if ($this->status->value < Type\RequestStatus::Received->value)
		{
			return false;
		}

		if (!isset($this->stepRelationId))
		{
			return false;
		}

		if ($this->status === Type\RequestStatus::Applied)
		{
			return true;
		}

		$res = RequestToStepTable::update($this->stepRelationId, [
			'APPLIED' => true,
		]);
		if (!$res->isSuccess())
		{
			return false;
		}

		$this->status = Type\RequestStatus::Applied;

		return $this->save();
	}

	/**
	 * Save result of AI request
	 * @param array $result - data array
	 * @return bool
	 */
	public function saveResult(array $result): bool
	{
		if ($this->status !== Type\RequestStatus::Sent)
		{
			return false;
		}

		$this->result = $result;
		$this->status = Type\RequestStatus::Received;
		$this->dateReceive = new DateTime();

		return $this->save();
	}

	/**
	 * Save error code and message
	 * @param Generation\Error $error
	 * @return bool
	 */
	public function saveError(Generation\Error $error): bool
	{
		if ($this->status > Type\RequestStatus::Sent)
		{
			return false;
		}

		$this->error = $error;
		$this->status = Type\RequestStatus::Received;
		$this->dateReceive = new DateTime();

		return $this->save();
	}

	public function setDeleted(): void
	{
		$this->isDeleted = true;
		$this->save();
	}

	private function save(): bool
	{
		if ($this->status->value < Type\RequestStatus::Sent->value)
		{
			return false;
		}

		$fields = [
			'GENERATION_ID' => $this->generationId,
			'HASH' => $this->hash,
			'RESULT' => $this->result,
			'ERROR' => $this->error?->toArray(),
			'DELETED' => $this->isDeleted,
		];

		if (isset($this->dateReceive))
		{
			$fields['DATE_RECEIVE'] = $this->dateReceive;
		}

		if (isset($this->id) && $this->id)
		{
			$res = RequestsTable::update($this->id, $fields);
			if (!$res->isSuccess())
			{
				return false;
			}
		}
		else
		{
			$res = RequestsTable::add($fields);
			if (!$res->isSuccess())
			{
				return false;
			}
			$this->id = $res->getId();
		}

		if (!isset($this->stepRelationId))
		{
			$res = RequestToStepTable::add([
				'REQUEST_ID' => $this->id,
				'GENERATION_ID' => $this->generationId,
				'STEP' => $this->stepId,
			]);
			if (!$res->isSuccess())
			{
				return false;
			}

			$this->stepRelationId = $res->getId();
		}

		return true;
	}

	/**
	 * Return ID of current generation
	 * @return int
	 */
	public function getGenerationId(): int
	{
		return $this->generationId;
	}

	/**
	 * If request received - return result data. Else - return null
	 * @return array|null
	 */
	public function getResult(): ?array
	{
		return $this->result;
	}

	/**
	 * If request finish with error - get error DTO
	 * @return ?Generation\Error
	 */
	public function getError(): ?Generation\Error
	{
		return $this->error;
	}

	/**
	 * If request receive answer
	 * @return bool
	 */
	public function isReceived(): bool
	{
		return $this->status === Type\RequestStatus::Received;
	}

	/**
	 * If request answer was applied to step
	 * @return bool
	 */
	public function isApplied(): bool
	{
		return $this->status === Type\RequestStatus::Applied;
	}

	/**
	 * ID in DB
	 * @return int|null
	 */
	public function getId(): ?int
	{
		return $this->id;
	}

	/**
	 * @param int $generationId
	 * @param int $stepId
	 * @return array<Request> - array of exists requests
	 */
	public static function getByGeneration(int $generationId, int $stepId): array
	{
		$filter =
			Query::filter()
				->where('GENERATION_ID', '=', $generationId)
				->where('STEP_REF.STEP', '=', $stepId)
				->where('DELETED', '=', 'N')
		;

		return self::getExists($filter);
	}

	public static function getByHash(string $hash): ?self
	{
		$filter =
			Query::filter()
				->where('HASH', '=', $hash)
				->where('DELETED', '=', 'N')
		;
		$exists = self::getExists($filter);

		return array_shift($exists);
	}

	public static function getById(int $id): ?self
	{
		$filter =
			Query::filter()
				->where('ID', '=', $id)
				->where('DELETED', '=', 'N')
		;
		$exists = self::getExists($filter);

		return array_shift($exists);
	}

	/**
	 * @param Filter\ConditionTree $filter - ORM filter object
	 * @return Request[]
	 */
	private static function getExists(Filter\ConditionTree $filter): array
	{
		$exists = [];
		$res = RequestsTable::query()
			->setSelect([
				'ID',
				'GENERATION_ID',
				'HASH',
				'RESULT',
				'ERROR',
				'DELETED',
				'DATE_CREATE',
				'DATE_RECEIVE',
				'STEP' => 'STEP_REF.STEP',
			])
			->where($filter)
			->exec()
		;
		while ($entity = $res->fetchObject())
		{
			if (
				!$entity->getGenerationId()
				|| !$entity->getStepRef()->getStep()
			)
			{
				continue;
			}

			$request = new Request(
				$entity->getGenerationId(),
				$entity->getStepRef()->getStep(),
			);
			$request->initByEntity($entity);

			if ($request->isTimeIsOver())
			{
				$request->setDeleted();

				continue;
			}

			$exists[$entity->getId()] = $request;
		}

		return $exists;
	}

	private function initByEntity(EO_Requests $request): self
	{
		$this->id = $request->getId();
		$this->isDeleted = $request->getDeleted();

		$hash = $request->getHash();
		if ($hash)
		{
			$this->hash = $hash;
			$this->status = Type\RequestStatus::Sent;
		}

		$result = $request->getResult();
		$error = $request->getError();
		if (!empty($result))
		{
			$this->result = $result;
			$this->status = Type\RequestStatus::Received;
		}
		elseif (!empty($error))
		{
			$this->error = Generation\Error::fromArray($error);
			$this->status = Type\RequestStatus::Received;
		}

		$this->dateCreate = $request->getDateCreate();
		$dateReceive = $request->getDateReceive();
		if ($dateReceive)
		{
			$this->dateReceive = $dateReceive;
		}

		$step = $request->getStepRef();
		if ($step)
		{
			$step->fillApplied();
			$this->stepRelationId = $step->getId();
			if (!empty($this->result) && $step->getApplied())
			{
				$this->status = Generation\Type\RequestStatus::Applied;
			}
		}

		return $this;
	}

	private function isTimeIsOver(): bool
	{
		if (
			isset($this->dateReceive)
			|| isset($this->result)
			|| isset($this->error)
		)
		{
			return false;
		}

		return ((new \DateTime())->getTimestamp() - $this->dateCreate->getTimestamp()) > self::MAX_EXPECTED_TIME;
	}
}