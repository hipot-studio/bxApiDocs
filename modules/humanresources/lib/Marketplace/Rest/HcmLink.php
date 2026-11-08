<?php

namespace Bitrix\HumanResources\Marketplace\Rest;

use Bitrix\HumanResources\Item\HcmLink\Company;
use Bitrix\HumanResources\Item\HcmLink\Employee;
use Bitrix\HumanResources\Item\HcmLink\Field;
use Bitrix\HumanResources\Item\HcmLink\FieldValue;
use Bitrix\HumanResources\Item\HcmLink\Job;
use Bitrix\HumanResources\Item\HcmLink\Person;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Service\HcmLink\CompanyDisconnectService;
use Bitrix\HumanResources\Service\HcmLink\ExclusionService;
use Bitrix\HumanResources\Service\HcmLink\PinService;
use Bitrix\HumanResources\Type\HcmLink\FieldEntityType;
use Bitrix\HumanResources\Type\HcmLink\FieldType;
use Bitrix\HumanResources\Type\HcmLink\JobStatus;
use Bitrix\HumanResources\Type\HcmLink\JobType;
use Bitrix\HumanResources\Type\HcmLink\PlacementType;
use Bitrix\HumanResources\Type\HcmLink\RestEventType;
use Bitrix\Main\Application;
use Bitrix\Rest\Exceptions\ArgumentException;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\AccessException;
use Bitrix\Rest\AuthTypeException;
use Bitrix\Rest\Exceptions\ObjectNotFoundException;
use Bitrix\Rest\OAuth\Auth as OauthAuth;
use Bitrix\Rest\RestException;
use CRestServer;
use CRestUtil;
use Exception;
use IRestService;
use function ExecuteModuleEventEx;

Loader::includeModule('rest');

class HcmLink extends IRestService
{
	public const MODULE_ID = 'humanresources';
	public const SCOPE = 'humanresources.hcmlink';
	private const FIELD_CODE_ALIASES = [
		'РасчетныйЛисток' => 'РасчётныйЛисток',
	];

	public const LIMIT = 100;
	public const LIMIT_MAX = 1000;

	public static function onRestServiceBuildDescription(): array
	{
		return [
			self::SCOPE => [
				self::SCOPE . '.company.add' => [
					'callback' => [
						self::class,
						'companyAdd',
					],
					'options' => [],
				],
				self::SCOPE . '.company.update' => [
					'callback' => [
						self::class,
						'companyAdd',
					],
					'options' => [],
				],
				self::SCOPE . '.company.delete' => [
					'callback' => [
						self::class,
						'companyDelete',
					],
					'options' => [],
				],
				self::SCOPE . '.company.list' => [
					'callback' => [
						self::class,
						'companyList',
					],
					'options' => [],
				],
				self::SCOPE . '.company.user.list' => [
					'callback' => [
						self::class,
						'companyUserList',
					],
					'options' => [],
				],
				self::SCOPE . '.employee.set' => [
					'callback' => [
						self::class,
						'receiveEmployeeList',
					],
					'options' => [],
				],
				self::SCOPE . '.employee.list' => [
					'callback' => [
						self::class,
						'mappedEmployeeList',
					],
					'options' => [],
				],
				self::SCOPE . '.field.value.set' => [
					'callback' => [
						self::class,
						'receiveFieldValue',
					],
					'options' => [],
				],
				self::SCOPE . '.job.update' => [
					'callback' => [
						self::class,
						'jobUpdate',
					],
					'options' => [],
				],
				self::SCOPE . '.job.status.get' => [
					'callback' => [
						self::class,
						'getJobStatus',
					],
					'options' => [],
				],

				CRestUtil::EVENTS => self::getEvents(),
				CRestUtil::PLACEMENTS => self::getPlacements(),
			],
		];
	}

	/**
	 * @param array $query Input parameters ($_GET, $_POST).
	 * @param int $start (int)$query['start']
	 * @param CRestServer $restServer REST server.
	 *
	 * @return int|bool|array
	 * @throws RestException
	 */
	public static function companyAdd(array $query, int $start, CRestServer $restServer): int|bool|array
	{
		self::checkAuth($restServer);

		$companyRepository = Container::getHcmLinkCompanyRepository();
		$fieldRepository = Container::getHcmLinkFieldRepository();

		$id = (int)($query['id'] ?? 0);
		$request =
			is_array($query['fields'])
				? $query['fields']
				: [];

		$companyUuid = (string)$request['company'];
		$crmCompanyId = $request['crmCompanyId'] ?? null;
		$title = (string)$request['title'];
		$data = (is_array($request['data']) ?? null)
				? $request['data']
				: []
		;
		$fields = (is_array($request['fields']) ?? null)
				? $request['fields']
				: []
		;

		try
		{
			if (is_null($crmCompanyId))
			{
				throw new ArgumentException('Missed crmCompanyId');
			}

			if (empty($companyUuid))
			{
				throw new ArgumentException('Property company is incorrect');
			}

			if (empty($title))
			{
				throw new ArgumentException('Property title is incorrect');
			}

			if (empty($fields))
			{
				throw new ArgumentException('Property fields is incorrect');
			}

			$crmCompanyService = \Bitrix\Crm\Service\Container::getInstance()->getCompanyBroker();
			$crmCompany = $crmCompanyService->getById((int)$crmCompanyId);

			if (!$crmCompany)
			{
				throw new ObjectNotFoundException("Crm company {$crmCompanyId} not found");
			}

			$company = new Company(
				code: $companyUuid,
				myCompanyId: $crmCompanyId,
				title: $title,
				data: $data,
			);

			$savedCompany = $companyRepository->getById($id);
			if ($savedCompany !== null)
			{
				$company->id = $savedCompany->id;
			}
			$company = $companyRepository->save($company);

			$fieldIdsForDelete = array_map(
				fn() => true,
				$fieldRepository->getByCompany($company->id)->getItemMap(),
			);

			foreach ($fields as $field)
			{
				$type = FieldType::fromName(strtoupper($field['type'] ?? '')) ?? FieldType::UNKNOWN;
				$fieldUuid = (string)$field['field'];
				$title = (string)$field['title'];
				$ttl = (is_numeric($field['ttl'] ?? null)) ? (int)$field['ttl'] : 86400;
				$entityType = FieldEntityType::fromName(strtoupper($field['entityType'] ?? ''))
					?? FieldEntityType::UNKNOWN
				;

				$item = $fieldRepository->save(
					new Field(
						companyId: $company->id,
						field: $fieldUuid,
						title: $title,
						type: $type,
						entityType: $entityType,
						ttl: $ttl,
					),
				);
				$fieldIdsForDelete[$item->id] = false;
			}

			foreach ($fieldIdsForDelete as $fieldId => $flagDelete)
			{
				if ($flagDelete === true)
				{
					$fieldRepository->delete($fieldId);
				}
			}
		}
		catch (Exception $e)
		{
			return self::formatException($e);
		}

		return $id ? true : $company->id;
	}

	/**
	 * @param array $query Input parameters ($_GET, $_POST).
	 * @param int $start (int)$query['start']
	 * @param CRestServer $restServer REST server.
	 *
	 * @return bool|array
	 * @throws RestException
	 */
	public static function companyDelete(array $query, int $start, CRestServer $restServer): bool|array
	{
		self::checkAuth($restServer);

		try
		{
			$id = (int)$query['id'] ?? 0;
			$result = Container::getHcmLinkCompanyDisconnectService()->disconnect($id);

			if (!$result->isSuccess())
			{
				// Only the missing company keeps its historical answer. Any other failure of the shared
				// service must not be dressed up as "not found": the set of codes it returns grows with
				// the cascade, and 1C would be told the company is absent while it is not.
				$error = $result->getErrors()[0];
				if ($error->getCode() === CompanyDisconnectService::ERROR_COMPANY_NOT_FOUND)
				{
					throw new ObjectNotFoundException("Company {$id} not found");
				}

				throw new RestException($error->getMessage(), $error->getCode());
			}

			return true;
		}
		catch (Exception $e)
		{
			return self::formatException($e);
		}
	}

	/**
	 * @param array $query Input parameters ($_GET, $_POST).
	 * @param int $start (int)$query['start']
	 * @param CRestServer $restServer REST server.
	 *
	 * @return array
	 * @throws RestException
	 */
	public static function companyList(array $query, int $start, CRestServer $restServer): array
	{
		self::checkAuth($restServer);

		$limit = filter_var($query['limit'] ?? self::LIMIT, FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 1,
				'max_range' => self::LIMIT_MAX,
				'default' => self::LIMIT,
			],
		]);
		$offset = filter_var($query['offset'] ?? 0, FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 0,
				'default' => 0,
			],
		]);

		$userId = filter_var($query['userId'] ?? 0, FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 0,
				'default' => 0,
			],
		]);

		$companyRepository = Container::getHcmLinkCompanyRepository();
		$personRepository = Container::getHcmLinkPersonRepository();
		$fieldRepository = Container::getHcmLinkFieldRepository();
		try
		{
			$companies = [];
			if ($userId > 0)
			{
				$personByCompanyIdMap = $personRepository->getByUserIdsAndGroupByCompanyId($userId);
				$companyIds = array_keys($personByCompanyIdMap);

				$companies = $companyRepository->getListByIds($companyIds, $limit, $offset);
			}
			else
			{
				$companies = $companyRepository->getList($limit, $offset);
			}

			$result = [];
			foreach ($companies as $company)
			{
				$fields = [];
				foreach ($fieldRepository->getByCompany($company->id) as $field)
				{
					$fields[] =
						array_intersect_key(
							$field->toArray(),
							array_flip(
								[
									'field',
									'title',
									'type',
									'ttl',
								],
							),
						);
				}

				$companyData = [
					'id' => $company->id,
					'company' => $company->code,
					'crmCompanyId' => $company->myCompanyId,
					'title' => $company->title,
					'data' => $company->data,
					'createdAt' => ($company->createdAt ?? new DateTime())->format(\DateTimeInterface::ATOM),
					'fields' => $fields,
				];

				if ($userId > 0)
				{
					$companyData['person'] = ($personByCompanyIdMap[$company->id] ?? null)?->code;
				}

				$result[] = $companyData;
			}
		}
		catch (Exception $e)
		{
			return self::formatException($e);
		}

		return $result;
	}

	/**
	 * @param array $query Input parameters ($_GET, $_POST).
	 * @param int $start (int)$query['start']
	 * @param CRestServer $restServer REST server.
	 *
	 * @return array
	 * @throws RestException
	 */
	public static function companyUserList(array $query, int $start, CRestServer $restServer): array
	{
		$userId = self::checkAuthAndGetUserId($restServer);

		$limit = filter_var($query['limit'] ?? self::LIMIT, FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 1,
				'max_range' => self::LIMIT_MAX,
				'default' => self::LIMIT,
			],
		]);
		$offset = filter_var($query['offset'] ?? 0, FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 0,
				'default' => 0,
			],
		]);

		$companyRepository = Container::getHcmLinkCompanyRepository();
		$personRepository = Container::getHcmLinkPersonRepository();
		try
		{
			$personByCompanyIdMap = $personRepository->getByUserIdsAndGroupByCompanyId($userId);
			if (empty($personByCompanyIdMap))
			{
				return [];
			}

			$companyIds = array_keys($personByCompanyIdMap);
			$companies = $companyRepository->getListByIds($companyIds, $limit, $offset);

			$result = [];
			foreach ($companies as $company)
			{
				$companyData = [
					'id' => $company->id,
					'company' => $company->code,
					'crmCompanyId' => $company->myCompanyId,
					'title' => $company->title,
					'data' => $company->data,
					'person' => ($personByCompanyIdMap[$company->id] ?? null)?->code,
				];

				$result[] = $companyData;
			}
		}
		catch (Exception $e)
		{
			return self::formatException($e);
		}

		return $result;
	}

	/**
	 * @param array $query Input parameters ($_GET, $_POST).
	 * @param int $start (int)$query['start']
	 * @param CRestServer $restServer REST server.
	 *
	 * @return array
	 * @throws RestException
	 */
	public static function mappedEmployeeList(array $query, int $start, CRestServer $restServer): array
	{
		self::checkAuth($restServer);

		$limit = filter_var($query['limit'] ?? self::LIMIT, FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 1,
				'max_range' => self::LIMIT_MAX,
				'default' => self::LIMIT,
			],
		]);
		$offset = filter_var($query['offset'] ?? 0, FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 0,
				'default' => 0,
			],
		]);
		$companyUuid = (string)($query['company'] ?? '');

		$updatedAt =
			($ts = strtotime($query['updatedAt']))
				? DateTime::createFromTimestamp($ts)
				: null;

		$companyRepository = Container::getHcmLinkCompanyRepository();
		$personRepository = Container::getHcmLinkPersonRepository();
		$employeeRepository = Container::getHcmLinkEmployeeRepository();
		try
		{
			$company = $companyRepository->getByUnique($companyUuid);
			if ($company === null)
			{
				throw new ObjectNotFoundException("Company {$companyUuid} not found");
			}

			$mapped = $personRepository->getList(
				$company->id,
				$limit,
				$offset,
				$updatedAt,
			);

			$personIds = $mapped->map(
				static fn(Person $person) => $person->id,
			);

			$employeeCollection = $employeeRepository->getByPersonIds($personIds);
			$employeesByPersonIdMap = [];

			foreach ($employeeCollection as $employee)
			{
				$employeesByPersonIdMap[$employee->personId][] = $employee;
			}

			$result = [];
			foreach ($mapped as $item)
			{
				$employees = $employeesByPersonIdMap[$item->id] ?? [];
				$employeesResult = [];

				foreach ($employees as $employee)
				{
					/* @var Employee $employee */
					$employeesResult[] = [
						'id' => $employee->id,
						'employee' => $employee->code,
						'data' => $employee->data,
						'createdAt' => $employee->createdAt?->format(\DateTimeInterface::ATOM),
					];
				}

				$result[] = [
					'id' => $item->id,
					'company' => $companyRepository->getById($item->companyId)?->code,
					'person' => $item->code,
					'employees' => $employeesResult,
					'userId' => $item->userId,
					'title' => $item->title,
					'createdAt' => $item->createdAt?->format(\DateTimeInterface::ATOM),
					'updatedAt' => $item->updatedAt?->format(\DateTimeInterface::ATOM),
				];
			}
		}
		catch (Exception $e)
		{
			return self::formatException($e);
		}

		return $result;
	}

	/**
	 * @param array $query Input parameters ($_GET, $_POST).
	 * @param int $start (int)$query['start']
	 * @param CRestServer $restServer REST server.
	 *
	 * @return array|bool
	 * @throws RestException
	 */
	public static function receiveEmployeeList(array $query, int $start, CRestServer $restServer): array|bool
	{
		self::checkAuth($restServer);
		$errors = [];

		$employeeRepository = Container::getHcmLinkEmployeeRepository();
		$companyRepository = Container::getHcmLinkCompanyRepository();
		$personRepository = Container::getHcmLinkPersonRepository();

		$companyUuid = (string)($query['company'] ?? '');
		$employeeList =
			is_array($query['data'])
				? $query['data']
				: [];

		try
		{
			if (is_array($query['job'] ?? null))
			{
				$jobResult = self::jobUpdate($query['job'], $start, $restServer);
				if ($jobResult !== true)
				{
					return $jobResult;
				}
			}

			$company = $companyRepository->getByUnique($companyUuid);
			if ($company === null)
			{
				throw new ObjectNotFoundException("Company {$companyUuid} not found");
			}

			if (is_array($employeeList) && count($employeeList))
			{
				$employeeList = array_values($employeeList);
			}
			else
			{
				$errors[] = "Parameter 'data' must be a non empty array ";
				$employeeList = [];
			}

			$matchedUserIds = [];

			foreach ($employeeList as $i => $employee)
			{
				$employeeUuid = (string)$employee['employee'] ?? '';
				$personUuid = (string)$employee['person'] ?? '';
				$data = array_filter(array_map('strval', $employee['data']));

				if (($employeeUuid && $personUuid && $data) !== true)
				{
					$errors[] = "Item #{$i} should have not empty 'employee', 'person', 'data'";

					continue;
				}

				$title = self::getFormattedName(
					$data['firstName'] ?? '',
					$data['lastName'] ?? '',
					$data['patronymicName'] ?? '',
				);

				$userId =
					array_key_exists('userId', $employee)
						? self::normalizeUserId($employee['userId'])
						: null;

				$person = $personRepository->getByUnique($company->id, $personUuid);
				if ($person === null)
				{
					$person = $personRepository->add(
						new Person(
							companyId: $company->id,
							code: $personUuid,
							title: $title,
							userId: $userId,
						),
					);
				}
				else
				{
					$person->title = $title;
					// only a number relinks the person: a missing key, an explicit null and a value that
					// is not a number all mean "not changed" and keep the link the person already has
					if (is_numeric($employee['userId'] ?? null))
					{
						$person->userId = (int)$employee['userId'];
					}

					$personRepository->update($person);
				}

				if ($userId !== null && $userId > 0)
				{
					$matchedUserIds[$userId] = $userId;
				}

				if ($person->id)
				{
					$employeeRepository->save(
						new Employee(
							personId: $person->id,
							code: $employeeUuid,
							data: $data,
						),
					);
				}
			}

			self::dropExclusionsOfMatchedUsers($company->id, array_values($matchedUserIds));
		}

		catch (Exception $e)
		{
			$errors[] = $e->getMessage();
		}

		Container::getHcmLinkCompanyCounterService()->update();

		return [
			'status' => $errors
				? 'error'
				: 'ok',
			'errors' => $errors,
		];
	}

	/**
	 * @param array $query Input parameters ($_GET, $_POST).
	 * @param int $start (int)$query['start']
	 * @param CRestServer $restServer REST server.
	 *
	 * @return array
	 * @throws RestException
	 */
	public static function receiveFieldValue(array $query, int $start, CRestServer $restServer): array
	{
		self::checkAuth($restServer);

		$fieldValueRepository = Container::getHcmLinkFieldValueRepository();
		$companyRepository = Container::getHcmLinkCompanyRepository();
		$fieldRepository = Container::getHcmLinkFieldRepository();
		$employeeRepository = Container::getHcmLinkEmployeeRepository();
		$jobRepository = Container::getHcmLinkJobRepository();

		$companyUuid = (string)($query['company'] ?? '');
		$data = $query['data'] ?? null;

		$job = null;
		try
		{
			if (is_numeric($query['job']['id'] ?? null))
			{
				$job = $jobRepository->getById((int)$query['job']['id']);
			}

			$shouldDeferJobUpdate
				= is_array($query['job'] ?? null)
				&& in_array(
					$job?->type,
					[JobType::PIN_REQUEST, JobType::SALARY_VACATION_REQUEST],
					true,
				);

			if (is_array($query['job'] ?? null) && !$shouldDeferJobUpdate)
			{
				if ($job === null)
				{
					throw new ArgumentException("Job not found");
				}

				$jobResult = self::updateLoadedJob($job, $query['job']);
				if ($jobResult !== true)
				{
					return $jobResult;
				}
			}

			// Company is resolved by its 1C code (GUID) or, for the direct salary/vacation
			// flow, by the CRM company id; as a last resort, from the job that initiated the
			// exchange, so a missing/foreign "company" never reaches the repository as null.
			$company = $job !== null
				? $companyRepository->getById($job->companyId)
				: null;
			if ($company === null && $companyUuid !== '')
			{
				$company = $companyRepository->getByUnique($companyUuid);
				if ($company === null && is_numeric($companyUuid))
				{
					$company = $companyRepository->getByCompanyId((int)$companyUuid)->getFirst();
				}
			}
			if ($company === null && $job !== null)
			{
				$company = $companyRepository->getById($job->companyId);
			}
			if (is_null($company))
			{
				throw new ObjectNotFoundException("Company not found");
			}
			if (
				$job !== null
				&& $companyUuid !== ''
				&& !in_array($companyUuid, [$company->code, (string)$company->myCompanyId], true)
			)
			{
				throw new ArgumentException("Job does not belong to the specified company");
			}
			if ($job !== null && $job->companyId !== $company->id)
			{
				throw new ArgumentException("Job does not belong to the specified company");
			}

			$errors = [];

			if (is_array($data) && count($data))
			{
				$data = array_values($data);
			}
			else
			{
				$errors[] = "Parameter 'data' must be a non empty array ";
				$data = [];
			}

			$salaryVacationEmployee = null;
			foreach ($data as $i => $fieldValue)
			{
				$fieldCode = $fieldValue['field'] ?? '';
				$fieldCode = self::FIELD_CODE_ALIASES[$fieldCode] ?? $fieldCode;
				$field = $fieldRepository->getByUnique($company->id, $fieldCode);
				if (is_null($field))
				{
					$errors[] = "Item #{$i} field '{$fieldCode}' not found for company '{$company->id}'";
					continue;
				}
				$employeeCode = $fieldValue['employee'] ?? '';
				$employee = $employeeRepository->getByUnique($company->id, $employeeCode);
				if (is_null($employee))
				{
					$errors[] = "Item #{$i} employee '{$employeeCode}' not found for company '{$company->id}'";
					continue;
				}

				$isRequestedEmployee =
					$job === null
					|| in_array($employee->code, (array)($job->outputData['employees'] ?? []), true);
				$isRequestedField =
					$job === null
					|| in_array($field->field, (array)($job->outputData['fields'] ?? []), true);
				$isPinRequest = $job?->type === JobType::PIN_REQUEST;
				$isSalaryVacationRequest = $job?->type === JobType::SALARY_VACATION_REQUEST;
				if (
					$isPinRequest
					&& (
						!$isRequestedEmployee
						|| $field->field !== PinService::FIELD_CODE
					)
				)
				{
					$errors[] = "Item #{$i} does not match PIN request job '{$job->id}'";
					continue;
				}
				if (
					$isSalaryVacationRequest
					&& (!$isRequestedEmployee || !$isRequestedField)
				)
				{
					$errors[] = "Item #{$i} does not match salary/vacation request job '{$job->id}'";
					continue;
				}

				$uniqueId = $employee->id;
				$jobSlotId = $job?->settingsData?->documentIdByEmployeeId[$employee->id] ?? null;
				if ($isPinRequest || $isSalaryVacationRequest)
				{
					// Results of these jobs are isolated per job and are accepted only into its slot.
					$uniqueId = $jobSlotId;
				}
				elseif ($field->entityType === FieldEntityType::DOCUMENT && $jobSlotId !== null)
				{
					// A DOCUMENT field belongs to the requesting document, not to the employee:
					// sign puts the signer id into the slot and reads the value back by it.
					$uniqueId = $jobSlotId;
				}

				if (!$uniqueId)
				{
					continue;
				}

				$savedFieldValue = $fieldValueRepository->getByUnique($uniqueId, $field->id);
				if ($savedFieldValue === null)
				{
					$fieldValueRepository->add(
						new FieldValue(
							entityId: $uniqueId,
							fieldId: $field->id,
							value: $fieldValue['value'] ?? '',
							createdAt: new DateTime(),
							expiredAt: DateTime::createFromTimestamp(time() + $field->ttl),
						),
					);
				}
				else
				{
					$savedFieldValue->value = $fieldValue['value'] ?? '';
					$savedFieldValue->expiredAt = DateTime::createFromTimestamp(time() + $field->ttl);
					$fieldValueRepository->update($savedFieldValue);
				}
				// 1C returns the PIN as a field value with the PinService::FIELD_CODE code.
				// Deliver it immediately instead of exposing it in the common field values response.
				if (
					$field->field === PinService::FIELD_CODE
					&& $isPinRequest
				)
				{
					Container::getHcmLinkPinService()->deliver(
						$employee,
						(string)($fieldValue['value'] ?? ''),
						$job?->id,
					);
				}
				elseif ($isSalaryVacationRequest)
				{
					// Notify the owner once after all field values in the request are processed.
					$salaryVacationEmployee = $employee;
				}

			}

			if ($shouldDeferJobUpdate && empty($errors))
			{
				$requestedStatus = JobStatus::fromName(
					strtoupper((string)($query['job']['fields']['status'] ?? '')),
				);
				if (
					$requestedStatus === JobStatus::DONE
					&& !self::isCompleteJobResponse($job)
				)
				{
					$errors[] = "Job '{$job->id}' result is incomplete";
				}
			}

			if ($shouldDeferJobUpdate && empty($errors))
			{
				$jobResult = self::updateLoadedJob($job, $query['job']);
				if ($jobResult !== true)
				{
					return $jobResult;
				}
			}

			if (
				$salaryVacationEmployee !== null
				&& $job?->status === JobStatus::DONE
			)
			{
				Container::getHcmLinkPinService()->deliverDocument($salaryVacationEmployee, (int)$job->id);
			}
		}
		catch (Exception $e)
		{
			$errors[] = $e->getMessage();
		}

		return [
			'status' => $errors
				? 'error'
				: 'ok',
			'errors' => $errors,
		];
	}

	/**
	 * @param array $query Input parameters ($_GET, $_POST).
	 * @param int $start (int)$query['start']
	 * @param CRestServer $restServer REST server.
	 *
	 * @return bool|array
	 * @throws RestException
	 */
	public static function jobUpdate(array $query, int $start, CRestServer $restServer): bool|array
	{
		self::checkAuth($restServer);

		$jobId = (int)($query['id'] ?? 0);
		try
		{
			$job = Container::getHcmLinkJobRepository()->getById($jobId);
			if ($job === null)
			{
				throw new ArgumentException("Job not found");
			}
		}
		catch (Exception $e)
		{
			return self::formatException($e);
		}

		return self::updateLoadedJob($job, $query);
	}

	private static function updateLoadedJob(Job $job, array $query): bool|array
	{
		$request
			= is_array($query['fields'])
				? $query['fields']
				: [];
		$total
			= isset($request['total'])
				? (int)$request['total']
				: null;
		$done
			= isset($request['sent'])
				? (int)$request['sent']
				: null;
		$status
			= isset($request['status'])
				? JobStatus::fromName(strtoupper($request['status']))
				: null;
		$inputData
			= isset($request['data']) && is_array($request['data'])
				? $request['data']
				: [];

		$jobService = Container::getHcmLinkJobService();

		try
		{
			if (!$status?->isActual())
			{
				throw new ArgumentException("Invalid job status");
			}

			$job->done = $done ?? $job->done;
			$job->total = $total ?? $job->total;
			$job->status = $status;
			$job->inputData = $inputData;

			$jobService->update($job);
		}
		catch (Exception $e)
		{
			return self::formatException($e);
		}

		return true;
	}

	private static function isCompleteJobResponse(Job $job): bool
	{
		$employeeCodes = (array)($job->outputData['employees'] ?? []);
		$fieldCodes = $job->type === JobType::PIN_REQUEST
			? [PinService::FIELD_CODE]
			: (array)($job->outputData['fields'] ?? []);

		if (empty($employeeCodes) || empty($fieldCodes))
		{
			return false;
		}

		foreach ($employeeCodes as $employeeCode)
		{
			$employee = Container::getHcmLinkEmployeeRepository()->getByUnique($job->companyId, $employeeCode);
			$entityId = $employee !== null
				? ($job->settingsData?->documentIdByEmployeeId[$employee->id] ?? null)
				: null;
			if ($entityId === null)
			{
				return false;
			}

			foreach ($fieldCodes as $fieldCode)
			{
				$field = Container::getHcmLinkFieldRepository()->getByUnique($job->companyId, $fieldCode);
				if (
					$field === null
					|| Container::getHcmLinkFieldValueRepository()->getByUnique($entityId, $field->id) === null
				)
				{
					return false;
				}
			}
		}

		return true;
	}

	public static function getJobStatus(array $query, int $start, CRestServer $restServer)
	{
		self::checkAuth($restServer);

		$jobId = (int)($query['id'] ?? 0);

		try
		{
			$job = Container::getHcmLinkJobRepository()->getById($jobId);
			if ($job === null)
			{
				throw new ArgumentException("Job not found");
			}
		}
		catch (Exception $e)
		{
			return self::formatException($e);
		}

		return [
			'alive' => !$job->status->isFinished(),
		];
	}

	public static function onEvent(array $query): array
	{
		//event data needs no processing - returned unchanged
		return $query;
	}

	/**
	 * @throws AccessException
	 */
	private static function checkAuth(CRestServer $restServer): void
	{
		global $USER;

		if (!$USER->isAuthorized())
		{
			throw new AccessException("User authorization required");
		}

		if ($restServer->getAuthType() !== OauthAuth::AUTH_TYPE)
		{
			throw new AuthTypeException("Application context required");
		}

		if (!\CRestUtil::isAdmin())
		{
			throw new AccessException("Access denied.");
		}
	}

	private static function checkAuthAndGetUserId(CRestServer $restServer): int
	{
		/**
		 * @throws AccessException
		 */

		global $USER;

		if (!$USER->isAuthorized() || !$USER->GetID())
		{
			throw new AccessException("User authorization required");
		}

		if ($restServer->getAuthType() !== OauthAuth::AUTH_TYPE)
		{
			throw new AuthTypeException("Application context required");
		}

		return $USER->GetID();
	}

	private static function formatException(Exception $e): array
	{
		if (\Bitrix\Main\Config\Option::get('humanresources', 'rest_detailed_exceptions', 'N') === 'Y')
		{
			return [
				'error' => $e->getCode(),
				'error_class' => get_class($e),
				'error_description' => $e->getMessage(),
			];
		}

		return [
			'error' => $e->getCode(),
			'error_description' => 'Operation failed',
		];
	}

	/**
	 * The value arrives from the external answer as it is, and the person keeps it in a typed
	 * property: anything but a number would take the whole upload down with a TypeError.
	 */
	private static function normalizeUserId(mixed $userId): int
	{
		return is_numeric($userId) ? (int)$userId : 0;
	}

	/**
	 * The data of 1C wins over the exclusion: a user the upload has matched is no longer excluded.
	 * The removal covers the whole received list at once and is split by the limit of a single call,
	 * because one upload brings up to self::LIMIT_MAX records.
	 *
	 * @param array<int> $userIds
	 */
	private static function dropExclusionsOfMatchedUsers(int $companyId, array $userIds): void
	{
		if ($userIds === [])
		{
			return;
		}

		$exclusionService = Container::getHcmLinkExclusionService();

		foreach (array_chunk($userIds, ExclusionService::USER_IDS_LIMIT) as $userIdsChunk)
		{
			// no actor: the removal comes from the external system, not from a portal user
			$exclusionService->removeExclusions($companyId, $userIdsChunk, null);
		}
	}

	protected static function getFormattedName(?string $firstName, ?string $lastName, ?string $secondName): string
	{
		$culture = Application::getInstance()->getContext()?->getCulture();
		$nameFormat = $culture?->getNameFormat() ?? '#NAME# #LAST_NAME# #SECOND_NAME#';

		return str_replace(
			[
				'#NAME#',
				'#LAST_NAME#',
				'#SECOND_NAME#',
				'#NAME_SHORT#',
				'#LAST_NAME_SHORT#',
				'#SECOND_NAME_SHORT#',
			],
			[
				$firstName,
				$lastName,
				$secondName,
				mb_substr($firstName, 0, 1) . ".",
				mb_substr($lastName, 0, 1) . ".",
				mb_substr($secondName, 0, 1) . ".",
			],
			$nameFormat,
		);
	}

	private static function getEvents(): array
	{
		return [
			RestEventType::onEmployeeListRequested->value => [
				self::MODULE_ID,
				RestEventType::onEmployeeListRequested->name,
				[
					self::class,
					'onEvent',
				],
			],
			RestEventType::onFieldValueRequested->value => [
				self::MODULE_ID,
				RestEventType::onFieldValueRequested->name,
				[
					self::class,
					'onEvent',
				],
			],
			RestEventType::onEmployeeListMapped->value => [
				self::MODULE_ID,
				RestEventType::onEmployeeListMapped->name,
				[
					self::class,
					'onEvent',
				],
			],
			RestEventType::onPinRequested->value => [
				self::MODULE_ID,
				RestEventType::onPinRequested->name,
				[
					self::class,
					'onEvent',
				],
			],
			RestEventType::onSalaryVacationRequested->value => [
				self::MODULE_ID,
				RestEventType::onSalaryVacationRequested->name,
				[
					self::class,
					'onEvent',
				],
			],
		];
	}

	private static function getPlacements(): array
	{
		return [
			PlacementType::SALARY_VACATION->value => [
				'options' => [
					'width' => [
						'type' => 'int',
						'default' => 900,
						'require' => false,
					],
				],
			],
		];
	}
}
