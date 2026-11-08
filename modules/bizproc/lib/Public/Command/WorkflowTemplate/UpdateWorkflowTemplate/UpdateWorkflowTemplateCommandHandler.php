<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\WorkflowTemplate\UpdateWorkflowTemplate;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\WorkflowTemplateRepository;
use Bitrix\Bizproc\Workflow\Template\AgentOwnedMetadataService;
use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\UpdateResult;

class UpdateWorkflowTemplateCommandHandler
{
	private WorkflowTemplateRepository $repository;

	public function __construct()
	{
		$this->repository = Container::getWorkflowTemplateRepository();
	}

	public function __invoke(UpdateWorkflowTemplateCommand $command): UpdateResult
	{
		if ($command->agentOwnedMetadataToRelease === [])
		{
			return $this->updateTemplate($command);
		}

		// Writing the live row and releasing the ownership marker are one change of the metadata, so a
		// half-applied pair is not allowed: the field would stand on the row while the claim of the agent
		// still stood on the draft, and the next reload would put the agent value back over it (AGT-TPL-01).
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$result = $this->updateTemplate($command);
			if ($result->isSuccess())
			{
				$releaseResult = (new AgentOwnedMetadataService())->release(
					$command->templateId,
					$command->agentOwnedMetadataToRelease,
				);

				$result->addErrors($releaseResult->getErrors());
				if ($releaseResult->isSuccess() && $releaseResult->getData() !== [])
				{
					$result->setData(array_merge($result->getData(), $releaseResult->getData()));
				}
			}
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();

			throw $exception;
		}

		if ($result->isSuccess())
		{
			$connection->commitTransaction();
		}
		else
		{
			$connection->rollbackTransaction();
		}

		return $result;
	}

	private function updateTemplate(UpdateWorkflowTemplateCommand $command): UpdateResult
	{
		$result = $this->repository->updateTemplate($command->templateId, $command->data);

		// Updating a missing template yields a successful UpdateResult with 0 affected rows. Zero affected
		// rows can also mean a genuine no-op update on an existing row, so re-read to tell them apart and
		// refuse only when the template is really gone.
		if (
			$command->templateId > 0
			&& $result->isSuccess()
			&& $result->getAffectedRowsCount() === 0
			&& !$this->repository->exists($command->templateId)
		)
		{
			$result->addError(
				ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError(['#ID#' => $command->templateId]),
			);
		}

		return $result;
	}
}
