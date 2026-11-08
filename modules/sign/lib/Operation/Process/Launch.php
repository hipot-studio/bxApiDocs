<?php

namespace Bitrix\Sign\Operation\Process;

use Bitrix\Bizproc\Api\Request\WorkflowTemplateService\PrepareStartParametersRequest;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateService\PrepareStartParametersResponse;
use Bitrix\Bizproc\Api\Service\WorkflowTemplateService;
use Bitrix\Bizproc\Starter\Enum\ManualStartSurface;
use Bitrix\Main;
use Bitrix\Main\UserTable;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Item;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\DateTime;

/**
 * One press of the start button: the selection of employees turns into a launch group whose rows wait for
 * their own process each. Nothing is started here - the group is only queued, so that a launch on a whole
 * department does not have to fit into the request.
 */
final class Launch implements Contract\Operation
{
	public const ERROR_PROCESS_UNAVAILABLE = 'SIGN_PROCESS_LAUNCH_UNAVAILABLE';
	public const ERROR_NO_EMPLOYEES = 'SIGN_PROCESS_LAUNCH_NO_EMPLOYEES';
	public const ERROR_EMPLOYEE_LIMIT_EXCEEDED = 'SIGN_B2E_MEMBER_LIMIT_EXCEEDED';
	public const ERROR_INVALID_EMPLOYEES = 'SIGN_B2E_MEMBER_SELECTION_INVALID';
	public const MAX_EMPLOYEES_PER_LAUNCH = 1000;

	/**
	 * @param int[] $employeeIds employees the process is started for, one process each
	 * @param array $parameters values of the start fields as they came from the form
	 * @param null|\Closure(list<int>): list<int> $eligibleEmployeeIdsResolver
	 */
	public function __construct(
		private readonly int $createdById,
		private readonly int $templateId,
		private readonly array $employeeIds,
		private readonly array $parameters = [],
		private readonly ?WorkflowTemplateService $templateService = null,
		private readonly ?\Closure $eligibleEmployeeIdsResolver = null,
	)
	{
	}

	public function launch(): Main\Result
	{
		$result = new Main\Result();
		$employeeIdsResult = $this->resolveEligibleEmployeeIds();
		if (!$employeeIdsResult->isSuccess())
		{
			return $result->addErrors($employeeIdsResult->getErrors());
		}
		$employeeIds = $employeeIdsResult->getData()['employeeIds'];

		$entityTypeId = $this->getEntityTypeId();
		$documentType = $entityTypeId === null
			? null
			: \CCrmBizProcHelper::ResolveDocumentType($entityTypeId);
		if ($documentType === null)
		{
			return $result->addError(
				new Main\Error('Process type is not available', self::ERROR_PROCESS_UNAVAILABLE),
			);
		}

		// the parameters are validated before anything is written: an unfilled required field is an error
		// of the press and not of a group that would then wait for a process it cannot start
		$parametersResponse = $this->prepareStartParameters($documentType);
		if (!$parametersResponse->isSuccess())
		{
			return $result->addErrors($parametersResponse->getErrors());
		}

		$launch = $this->createLaunch($entityTypeId, $employeeIds, $parametersResponse->getParameters());
		$addResult = $this->addLaunch($launch, $employeeIds);
		if (!$addResult->isSuccess())
		{
			return $result->addErrors($addResult->getErrors());
		}

		return $result->setData([
			'launchId' => $launch->getId(),
			'memberCount' => count($employeeIds),
		]);
	}

	private function createLaunch(int $entityTypeId, array $employeeIds, array $parameters): Item\Process\Launch
	{
		$queuedAt = new DateTime();
		$waitingTerm = Container::instance()->getProcessLaunchService()
			->getWaitingTermSeconds(count($employeeIds))
		;

		return new Item\Process\Launch(
			createdById: $this->createdById,
			templateId: $this->templateId,
			entityTypeId: $entityTypeId,
			memberCount: count($employeeIds),
			queuedAt: $queuedAt,
			expiresAt: $queuedAt->withAddSeconds($waitingTerm),
			parameters: $parameters,
		);
	}

	/**
	 * The group and its rows are written inside one transaction: a group without rows would wait for
	 * processes nobody is going to start, and rows without a group would start processes nobody asked for.
	 * The repository opens no transaction of its own, so this is the only boundary.
	 *
	 * @param int[] $employeeIds
	 */
	private function addLaunch(Item\Process\Launch $launch, array $employeeIds): Main\Result
	{
		$connection = Main\Application::getConnection();
		$connection->startTransaction();

		try
		{
			$addResult = Container::instance()->getProcessLaunchRepository()->add($launch, $employeeIds);
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();

			throw $exception;
		}

		if (!$addResult->isSuccess())
		{
			$connection->rollbackTransaction();

			return $addResult;
		}

		$connection->commitTransaction();

		return $addResult;
	}

	private function prepareStartParameters(array $documentType): PrepareStartParametersResponse
	{
		$templateService = $this->templateService ?? new WorkflowTemplateService();

		// the surface is the one the fields were collected for in the form, so that the version of the
		// template the initiator filled in is the version that starts
		return $templateService->prepareStartParameters(new PrepareStartParametersRequest(
			templateId: $this->templateId,
			complexDocumentType: $documentType,
			requestParameters: $this->parameters,
			targetUserId: $this->createdById,
			eventType: \CBPDocumentEventType::Manual,
			manualStartSurface: ManualStartSurface::StartForm,
		));
	}

	/**
	 * One process per employee, so a selection that names the same employee twice
	 * gives one row and not two. The operation is also a trust boundary for internal callers: only active
	 * intranet employees may reach the queue.
	 */
	private function resolveEligibleEmployeeIds(): Main\Result
	{
		$result = new Main\Result();
		if (count($this->employeeIds) > self::MAX_EMPLOYEES_PER_LAUNCH)
		{
			return $result->addError(new Main\Error(
				'Too many employees to start the process for',
				self::ERROR_EMPLOYEE_LIMIT_EXCEEDED,
				['limit' => self::MAX_EMPLOYEES_PER_LAUNCH],
			));
		}

		$employeeIds = [];
		foreach ($this->employeeIds as $employeeId)
		{
			if (!is_int($employeeId) || $employeeId < 1)
			{
				return $result->addError(new Main\Error(
					'Invalid employee selection',
					self::ERROR_INVALID_EMPLOYEES,
				));
			}

			$employeeIds[$employeeId] = $employeeId;
		}
		$employeeIds = array_values($employeeIds);

		if ($employeeIds === [])
		{
			return $result->addError(new Main\Error(
				'No employees left to start the process for',
				self::ERROR_NO_EMPLOYEES,
			));
		}

		$eligibleEmployeeIds = $this->listEligibleEmployeeIds($employeeIds);
		if ($eligibleEmployeeIds === [])
		{
			return $result->addError(new Main\Error(
				'No employees left to start the process for',
				self::ERROR_NO_EMPLOYEES,
			));
		}

		$eligibleEmployeeIdMap = array_fill_keys(array_map('intval', $eligibleEmployeeIds), true);
		if (count($eligibleEmployeeIdMap) !== count($employeeIds))
		{
			return $result->addError(new Main\Error(
				'Invalid employee selection',
				self::ERROR_INVALID_EMPLOYEES,
			));
		}
		foreach ($employeeIds as $employeeId)
		{
			if (!isset($eligibleEmployeeIdMap[$employeeId]))
			{
				return $result->addError(new Main\Error(
					'Invalid employee selection',
					self::ERROR_INVALID_EMPLOYEES,
				));
			}
		}

		return $result->setData(['employeeIds' => $employeeIds]);
	}

	/**
	 * @param list<int> $employeeIds
	 *
	 * @return list<int>
	 */
	private function listEligibleEmployeeIds(array $employeeIds): array
	{
		if ($this->eligibleEmployeeIdsResolver !== null)
		{
			return ($this->eligibleEmployeeIdsResolver)($employeeIds);
		}

		$rows = UserTable::query()
			->setSelect(['ID'])
			->setDistinct()
			->whereIn('ID', $employeeIds)
			->where('ACTIVE', 'Y')
			->where('IS_REAL_USER', 'Y')
			->where('UF_DEPARTMENT', '!=', false)
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	private function getEntityTypeId(): ?int
	{
		if (!Main\Loader::includeModule('bizproc') || !Main\Loader::includeModule('crm'))
		{
			return null;
		}

		return Container::instance()->getCrmProcessTypeService()->getEntityTypeId();
	}
}
