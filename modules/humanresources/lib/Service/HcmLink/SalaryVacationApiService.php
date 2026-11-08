<?php

namespace Bitrix\HumanResources\Service\HcmLink;

use Bitrix\HumanResources\Exception\CreationFailedException;
use Bitrix\HumanResources\Item\HcmLink\Employee;
use Bitrix\HumanResources\Item\HcmLink\Field;
use Bitrix\HumanResources\Item\HcmLink\FieldValue;
use Bitrix\HumanResources\Item\HcmLink\Job;
use Bitrix\HumanResources\Item\HcmLink\Job\SettingsData;
use Bitrix\HumanResources\Item\HcmLink\Person;
use Bitrix\HumanResources\Result\Service\HcmLink\GetFieldValueResult;
use Bitrix\HumanResources\Result\Service\HcmLink\JobServiceResult;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\HcmLink\JobStatus;
use Bitrix\HumanResources\Type\HcmLink\JobType;
use Bitrix\HumanResources\Type\HcmLink\PayrollType;
use Bitrix\Main\Data\Storage\Exception\StorageException;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Direct API for requesting salary and vacation data from 1C through the asynchronous
 * HCM Link job mechanism.
 *
 * Supported flows:
 *  - getCompanies() returns companies, their fields, and mapped employees;
 *  - requestPin() asks 1C to issue a PIN through a PIN_REQUEST job;
 *  - request() sends the payroll type, optional period, and PIN to 1C;
 *  - getResult() returns the job status and received field values.
 *
 * REST event payloads contain jobId followed by outputData. company carries the portal CRM company
 * ID, while companyUid carries the 1C organization UID.
 */
class SalaryVacationApiService
{
	/** Reuses the same PIN job for repeated requests within this interval. */
	private const PIN_THROTTLE_SECONDS = 60;

	/** Maximum number of recent PIN jobs inspected when applying the throttle. */
	private const PIN_THROTTLE_SCAN_LIMIT = 20;

	private const DOCUMENT_REQUEST_THROTTLE_SECONDS = 60;

	private readonly StorageInterface $storage;
	private readonly Connection $connection;

	public function __construct(?StorageInterface $storage = null, ?Connection $connection = null)
	{
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
		$this->connection = $connection ?? Application::getConnection();
	}

	/**
	 * Returns selectable companies with their fields and employees mapped to the user.
	 *
	 * @return array{companies: list<array>}
	 */
	public function getCompanies(int $userId): array
	{
		$companyRepository = Container::getHcmLinkCompanyRepository();
		$fieldRepository = Container::getHcmLinkFieldRepository();
		$personRepository = Container::getHcmLinkPersonRepository();
		$employeeRepository = Container::getHcmLinkEmployeeRepository();

		/** @var array<int, Person> $personByCompanyId */
		$personByCompanyId = $personRepository->getByUserIdsAndGroupByCompanyId($userId);

		$companies = [];
		foreach ($companyRepository->getList() as $company)
		{
			$fields = [];
			/** @var Field $field */
			foreach ($fieldRepository->getByCompany($company->id) as $field)
			{
				$fields[] = [
					'id' => $field->id,
					'code' => $field->field,
					'title' => $field->title,
					'type' => $field->type->name,
					'entityType' => $field->entityType->name,
				];
			}

			$employees = [];
			$person = $personByCompanyId[$company->id] ?? null;
			if ($person !== null && $person->id !== null)
			{
				/** @var Employee $employee */
				foreach ($employeeRepository->getByPersonId($person->id) as $employee)
				{
					$employees[] = [
						'id' => $employee->id,
						'code' => $employee->code,
					];
				}
			}

			$companies[] = [
				'company' => [
					'id' => $company->id,
					'code' => $company->code,
					'title' => $company->title,
				],
				'fields' => $fields,
				'mappedEmployees' => $employees,
			];
		}

		return [
			'companies' => $companies,
		];
	}

	/**
	 * Returns only companies that contain an employee mapped to the user.
	 *
	 * @return array{companies: list<array{companyId: int, title: string, employees: list<array{id: int, code: string}>}>}
	 */
	public function getOwnedCompanies(int $userId): array
	{
		$personByCompanyId = Container::getHcmLinkPersonRepository()->getByUserIdsAndGroupByCompanyId($userId);
		if (empty($personByCompanyId))
		{
			return ['companies' => []];
		}

		$personIds = [];
		foreach ($personByCompanyId as $person)
		{
			if ($person->id !== null)
			{
				$personIds[] = $person->id;
			}
		}

		$employeesByPersonId = [];
		foreach (Container::getHcmLinkEmployeeRepository()->getByPersonIds($personIds) as $employee)
		{
			$employeesByPersonId[$employee->personId][] = $employee;
		}

		$companyById = [];
		foreach (
			Container::getHcmLinkCompanyRepository()->getListByIds(array_keys($personByCompanyId))
			as $company
		)
		{
			$companyById[$company->id] = $company;
		}

		$companies = [];
		foreach ($personByCompanyId as $companyId => $person)
		{
			$company = $companyById[$companyId] ?? null;
			$employees = $person->id !== null ? ($employeesByPersonId[$person->id] ?? []) : [];
			if ($company === null || empty($employees))
			{
				continue;
			}

			$companies[] = [
				'companyId' => $company->id,
				'title' => $company->title,
				'employees' => array_map(
					static fn(Employee $employee): array => [
						'id' => $employee->id,
						'code' => $employee->code,
					],
					array_values($employees),
				),
			];
		}

		return ['companies' => $companies];
	}

	/**
	 * Checks that the employee is mapped to the user's person in the company.
	 */
	public function isOwnEmployee(int $userId, int $companyId, int $employeeId): bool
	{
		return $this->getOwnEmployee($userId, $companyId, $employeeId) !== null;
	}

	public function getOwnEmployee(int $userId, int $companyId, int $employeeId): ?Employee
	{
		$personByCompanyId = Container::getHcmLinkPersonRepository()->getByUserIdsAndGroupByCompanyId($userId);

		$person = $personByCompanyId[$companyId] ?? null;
		if ($person === null || $person->id === null)
		{
			return null;
		}

		foreach (Container::getHcmLinkEmployeeRepository()->getByPersonId($person->id) as $employee)
		{
			if ($employee->id === $employeeId)
			{
				return $employee;
			}
		}

		return null;
	}

	/**
	 * Requests a PIN from 1C for the employee.
	 *
	 * @param PayrollType $type payroll data type for which the PIN is requested
	 */
	public function requestPin(
		int $userId,
		int $companyId,
		int $employeeId,
		PayrollType $type,
	): Result|JobServiceResult
	{
		$company = Container::getHcmLinkCompanyRepository()->getById($companyId);
		if ($company === null || $company->id === null)
		{
			return (new Result())->addError(new Error('Company not found'));
		}

		$employee = $this->resolveEmployee($employeeId);
		if ($employee === null)
		{
			return (new Result())->addError(new Error('Employee not found'));
		}

		$employeeUid = $employee->code;
		$personGuid = Container::getHcmLinkPersonRepository()->getById($employee->personId)?->code;

		$recentPinJob = $this->findRecentPinJob($company->id, $employeeId, $employeeUid, $type);
		if ($recentPinJob !== null)
		{
			return new JobServiceResult($recentPinJob);
		}

		if (!$this->acquireRequestSlot(
			$this->buildRequestThrottleKey('pin_request', $userId, $companyId, $employeeId, $type),
			self::PIN_THROTTLE_SECONDS,
		))
		{
			return (new Result())->addError(new Error('Too many PIN requests', 'TOO_MANY_REQUESTS'));
		}

		$job = new Job(
			companyId: $company->id,
			type: JobType::PIN_REQUEST,
			outputData: [
				'company' => $company->myCompanyId,
				'companyUid' => $company->code,
				'employees' => [$employeeUid],
				'persons' => $personGuid !== null ? [$personGuid] : [],
				'date' => (new DateTime())->format(\DateTimeInterface::ATOM),
				'type' => mb_strtolower($type->name),
			],
			settingsData: new SettingsData(documentIdByEmployeeId: [$employeeId => 0]),
		);

		return $this->dispatch($job);
	}

	/**
	 * Requests salary or vacation data from 1C.
	 *
	 * @param PayrollType $type requested payroll data type
	 * @param string $pin employee PIN verified by 1C
	 * @param int|null $month period month, required for SALARY
	 * @param int|null $year period year, required for SALARY
	 */
	public function request(
		int $userId,
		int $companyId,
		int $employeeId,
		PayrollType $type,
		string $pin,
		?int $month = null,
		?int $year = null,
	): Result|JobServiceResult
	{
		if ($pin === '')
		{
			return (new Result())->addError(new Error('PIN is required'));
		}

		if ($type->isPeriodRequired())
		{
			if ($month === null || $month < 1 || $month > 12)
			{
				return (new Result())->addError(new Error('Month (1-12) is required for salary'));
			}
			if ($year === null || $year < 2000)
			{
				return (new Result())->addError(new Error('Year is required for salary'));
			}
		}

		$company = Container::getHcmLinkCompanyRepository()->getById($companyId);
		if ($company === null || $company->id === null)
		{
			return (new Result())->addError(new Error('Company not found'));
		}

		$employee = $this->resolveEmployee($employeeId);
		if ($employee === null)
		{
			return (new Result())->addError(new Error('Employee not found'));
		}

		$employeeUid = $employee->code;
		$personGuid = Container::getHcmLinkPersonRepository()->getById($employee->personId)?->code;

		$fieldUids = [];
		$documentFieldCode = $type->getDocumentFieldCode();
		if ($documentFieldCode !== null)
		{
			$fieldUids[] = $documentFieldCode;
		}
		else
		{
			$fields = Container::getHcmLinkFieldRepository()
				->getByCompanyIdAndEntityType($company->id, $type->getFieldEntityType())
			;
			/** @var Field $field */
			foreach ($fields as $field)
			{
				if ($field->field === PinService::FIELD_CODE)
				{
					continue;
				}

				$fieldUids[] = $field->field;
			}
		}

		if (empty($fieldUids))
		{
			return (new Result())->addError(
				new Error('No fields configured for the requested type in this company'),
			);
		}

		if (!$this->acquireRequestSlot(
			$this->buildRequestThrottleKey('document_request', $userId, $companyId, $employeeId, $type),
			self::DOCUMENT_REQUEST_THROTTLE_SECONDS,
		))
		{
			return (new Result())->addError(new Error('Too many document requests', 'TOO_MANY_REQUESTS'));
		}

		$outputData = [
			'company' => $company->myCompanyId,
			'companyUid' => $company->code,
			'employees' => [$employeeUid],
			'persons' => $personGuid !== null ? [$personGuid] : [],
			'fields' => $fieldUids,
			'date' => (new DateTime())->format(\DateTimeInterface::ATOM),
			'type' => mb_strtolower($type->name),
			'pin' => $pin,
		];
		if ($type->isPeriodRequired())
		{
			$outputData['period'] = [
				'month' => $month,
				'year' => $year,
			];
		}

		$job = new Job(
			companyId: $company->id,
			type: JobType::SALARY_VACATION_REQUEST,
			outputData: $outputData,
			settingsData: new SettingsData(documentIdByEmployeeId: [$employeeId => $employeeId]),
		);

		return $this->dispatch($job);
	}

	/**
	 * Returns the job status and field values received from 1C.
	 *
	 * @return array{job: ?array, isActual: bool, values: list<array>}
	 */
	public function getResult(int $jobId, int $entityId): array
	{
		$job = Container::getHcmLinkJobRepository()->getById($jobId);

		return $this->getResultByJob($job, $entityId);
	}

	private function getResultByJob(?Job $job, int $entityId): array
	{
		$resultEntityId = $job?->type === JobType::FIELD_VALUES
			? $entityId
			: ($job?->settingsData?->documentIdByEmployeeId[$entityId] ?? $entityId);

		$fieldIds = [];
		if ($job !== null && in_array($job->type, [JobType::FIELD_VALUES, JobType::SALARY_VACATION_REQUEST], true))
		{
			$requestedUids = (array)($job->outputData['fields'] ?? []);
			/** @var Field $field */
			foreach (Container::getHcmLinkFieldRepository()->getByCompany($job->companyId) as $field)
			{
				if (in_array($field->field, $requestedUids, true))
				{
					$fieldIds[] = $field->id;
				}
			}
		}

		$values = [];
		$isActual = false;
		if (!empty($fieldIds))
		{
			$fieldValueResult = Container::getHcmLinkFieldValueService()
				->getFieldValue([$resultEntityId], $fieldIds)
			;

			if ($fieldValueResult instanceof GetFieldValueResult)
			{
				// Derive freshness from each value because the shared isActual flag has different semantics.
				$isActual = $fieldValueResult->collection->count() === count($fieldIds);
				foreach ($fieldValueResult->collection as $fieldValue)
				{
					if ($fieldValue->expiredAt === null || $fieldValue->expiredAt->getTimestamp() < time())
					{
						$isActual = false;
					}
					$values[] = $fieldValue->toArray();
				}
			}
		}

		$jobArray = $job?->toArray();
		// Do not expose the one-time PIN stored in outputData for transport to 1C.
		if (isset($jobArray['outputData']['pin']))
		{
			unset($jobArray['outputData']['pin']);
		}

		return [
			'job' => $jobArray,
			'isActual' => $isActual,
			'values' => $values,
		];
	}

	/**
	 * Returns the current PIN field value for development diagnostics.
	 */
	public function getPin(int $companyId, int $employeeId, ?Job $job = null): ?string
	{
		$pinField = Container::getHcmLinkFieldRepository()->getByUnique($companyId, PinService::FIELD_CODE);
		if ($pinField === null)
		{
			return null;
		}

		$resultEntityId = $job?->settingsData?->documentIdByEmployeeId[$employeeId] ?? $employeeId;
		$result = Container::getHcmLinkFieldValueService()->getFieldValue([$resultEntityId], [$pinField->id]);
		if (!$result instanceof GetFieldValueResult)
		{
			return null;
		}

		$value = $result->collection->getFirst();
		if (
			$value === null
			|| $value->expiredAt === null
			|| $value->expiredAt->getTimestamp() < time()
		)
		{
			return null;
		}

		return (string)$value->value;
	}

	/**
	 * Marks the PIN used by a completed document request as consumed and releases
	 * the completed PIN and document request slots.
	 */
	private function consumePin(Job $documentJob, Employee $employee): void
	{
		if ($documentJob->type !== JobType::SALARY_VACATION_REQUEST || $documentJob->status !== JobStatus::DONE)
		{
			return;
		}

		$pin = (string)($documentJob->outputData['pin'] ?? '');
		$type = $this->resolvePayrollType((string)($documentJob->outputData['type'] ?? ''));
		$userId = Container::getHcmLinkPersonRepository()->getById($employee->personId)?->userId;
		if ($pin === '' || $type === null || $userId === null || $userId <= 0 || $employee->id === null)
		{
			return;
		}

		$pinJob = $this->findRecentPinJob(
			$documentJob->companyId,
			$employee->id,
			$employee->code,
			$type,
			$pin,
		);
		if ($pinJob === null)
		{
			return;
		}

		$pinRequestKey = $this->buildRequestThrottleKey(
			'pin_request',
			$userId,
			$documentJob->companyId,
			$employee->id,
			$type,
		);
		$documentRequestKey = $this->buildRequestThrottleKey(
			'document_request',
			$userId,
			$documentJob->companyId,
			$employee->id,
			$type,
		);
		if (!$this->connection->lock($pinRequestKey))
		{
			return;
		}

		try
		{
			$pinFieldValue = $this->getPinFieldValue($documentJob->companyId, $employee->id, $pinJob);
			if (
				$pinFieldValue?->id === null
				|| !hash_equals($pinFieldValue->value, $pin)
			)
			{
				return;
			}

			Container::getHcmLinkFieldValueRepository()->removeByIds([$pinFieldValue->id]);
			$this->storage->deleteMultiple([$pinRequestKey, $documentRequestKey]);
		}
		catch (StorageException)
		{
			// The PIN is already consumed; the request slot will expire on its own.
		}
		finally
		{
			$this->connection->unlock($pinRequestKey);
		}
	}

	/**
	 * Returns the controller status response and completed result data.
	 * Unknown jobs and jobs owned by another employee are represented as EXPIRED.
	 *
	 * @return array{status: string, jobId: int, finishedAt?: ?string, pin?: ?string, documentHtml?: string}
	 */
	public function getStatus(int $taskId, int $companyId, Employee $employee): array
	{
		$job = Container::getHcmLinkJobRepository()->getById($taskId);

		if (
			$job === null
			|| $job->companyId !== $companyId
			|| !$this->isJobForEmployee($job, $employee)
		)
		{
			return [
				'status' => JobStatus::EXPIRED->name,
				'jobId' => $taskId,
			];
		}

		$response = [
			'status' => $job->status->name,
			'jobId' => $job->id,
			'finishedAt' => $job->finishedAt?->format(\DateTimeInterface::ATOM),
		];

		if ($job->status !== JobStatus::DONE)
		{
			return $response;
		}

		if ($job->type === JobType::PIN_REQUEST)
		{
			$response['pin'] = $this->getPin($companyId, (int)$employee->id, $job);
		}
		elseif (in_array($job->type, [JobType::FIELD_VALUES, JobType::SALARY_VACATION_REQUEST], true))
		{
			$result = $this->getResultByJob($job, (int)$employee->id);
			if (!empty($result['isActual']))
			{
				// VACATION has no dedicated field contract yet, so the first value may contain unrelated employee data.
				$value = $result['values'][0]['value'] ?? null;
				if ($value !== null)
				{
					$response['documentHtml'] = (string)$value;
				}
			}

			if ($job->type === JobType::SALARY_VACATION_REQUEST)
			{
				$this->consumePin($job, $employee);
			}
		}

		return $response;
	}

	private function isJobForEmployee(Job $job, Employee $employee): bool
	{
		if (!in_array($job->type, [JobType::PIN_REQUEST, JobType::FIELD_VALUES, JobType::SALARY_VACATION_REQUEST], true))
		{
			return false;
		}

		return in_array($employee->code, (array)($job->outputData['employees'] ?? []), true);
	}

	/**
	 * Persists the job, schedules cleanup, and sends the REST event.
	 */
	private function dispatch(Job $job): Result|JobServiceResult
	{
		try
		{
			$jobRepository = Container::getHcmLinkJobRepository();
			$job = $jobRepository->add($job);
			if (
				$job->type === JobType::PIN_REQUEST
				&& !empty($job->settingsData?->documentIdByEmployeeId)
			)
			{
				$job->settingsData->documentIdByEmployeeId = array_fill_keys(
					array_keys($job->settingsData->documentIdByEmployeeId),
					$job->id,
				);
				$job = $jobRepository->update($job);
			}
		}
		catch (CreationFailedException)
		{
			return (new Result())->addError(new Error('Failed to create job'));
		}

		Container::getHcmLinkJobKillerService()->plan();

		$sendResult = Container::getHcmLinkJobService()->sendJob($job);
		if (!$sendResult->isSuccess())
		{
			return $sendResult;
		}

		return new JobServiceResult($job);
	}

	/**
	 * Returns a recent PIN_REQUEST job for the employee within the throttle interval.
	 */
	private function findRecentPinJob(
		int $companyId,
		int $employeeId,
		string $employeeUid,
		PayrollType $type,
		?string $pin = null,
	): ?Job
	{
		$since = (new DateTime())->add('-' . self::PIN_THROTTLE_SECONDS . ' seconds');
		$jobs = Container::getHcmLinkJobRepository()->getLastByTypeAndDate(
			JobType::PIN_REQUEST,
			$since,
			$companyId,
			[JobStatus::STARTED->value, JobStatus::IN_PROGRESS->value, JobStatus::DONE->value],
			self::PIN_THROTTLE_SCAN_LIMIT,
		);

		foreach ($jobs as $job)
		{
			if (
				!in_array($employeeUid, (array)($job->outputData['employees'] ?? []), true)
				|| ($job->outputData['type'] ?? null) !== mb_strtolower($type->name)
			)
			{
				continue;
			}

			if ($job->status !== JobStatus::DONE)
			{
				if ($pin === null)
				{
					return $job;
				}

				continue;
			}

			$currentPin = $this->getPin($companyId, $employeeId, $job);
			if ($currentPin !== null && ($pin === null || hash_equals($currentPin, $pin)))
			{
				return $job;
			}
		}

		return null;
	}

	private function getPinFieldValue(int $companyId, int $employeeId, ?Job $job = null): ?FieldValue
	{
		$pinField = Container::getHcmLinkFieldRepository()->getByUnique($companyId, PinService::FIELD_CODE);
		if ($pinField === null)
		{
			return null;
		}

		$resultEntityId = $job?->settingsData?->documentIdByEmployeeId[$employeeId] ?? $employeeId;
		$value = Container::getHcmLinkFieldValueRepository()->getByUnique($resultEntityId, $pinField->id);
		if (
			$value === null
			|| $value->expiredAt === null
			|| $value->expiredAt->getTimestamp() < time()
		)
		{
			return null;
		}

		return $value;
	}

	private function resolvePayrollType(string $type): ?PayrollType
	{
		$type = mb_strtoupper($type);
		foreach (PayrollType::cases() as $payrollType)
		{
			if ($payrollType->name === $type)
			{
				return $payrollType;
			}
		}

		return null;
	}

	private function buildRequestThrottleKey(
		string $requestType,
		int $userId,
		int $companyId,
		int $employeeId,
		PayrollType $type,
	): string
	{
		return implode('.', [
			'humanresources',
			'hcmlink',
			'salary_vacation',
			$requestType,
			$userId,
			$companyId,
			$employeeId,
			$type->value,
		]);
	}

	private function acquireRequestSlot(string $key, int $ttl): bool
	{
		if (!$this->connection->lock($key))
		{
			return false;
		}

		try
		{
			if ($this->storage->has($key))
			{
				return false;
			}

			return $this->storage->set($key, true, $ttl);
		}
		catch (StorageException)
		{
			return false;
		}
		finally
		{
			$this->connection->unlock($key);
		}
	}

	private function resolveEmployee(int $employeeId): ?Employee
	{
		if ($employeeId <= 0)
		{
			return null;
		}

		return Container::getHcmLinkEmployeeRepository()->getByIds([$employeeId])->getFirst();
	}
}
