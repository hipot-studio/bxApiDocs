<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AiAgent\Service;

use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Result\AiAgentStartResult;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Result\TemplateCreatedResult;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\SystemTemplateActivationService;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\TemplateDeleteService;
use Bitrix\Bizproc\Internal\Service\SetupTemplate\SetupTemplateService;
use Bitrix\Main\DI\ServiceLocator;

final class AgentLauncher
{
	public function __construct(
		private readonly SystemTemplateActivationService $activationService,
		private readonly SetupTemplateService $setupTemplateService,
		private readonly TemplateDeleteService $deleteService,
	) {}

	public static function create(): self
	{
		return new self(
			ServiceLocator::getInstance()->get(SystemTemplateActivationService::class),
			new SetupTemplateService(),
			new TemplateDeleteService(),
		);
	}

	public function launch(LaunchAgentRequest $request): AiAgentStartResult
	{
		$copyResult = $this->activationService->copyTemplate(
			$request->systemTemplateId,
			$request->userId,
			$request->source,
		);
		if (!$copyResult instanceof TemplateCreatedResult)
		{
			return (new AiAgentStartResult())->addErrors($copyResult->getErrors());
		}

		$templateId = $copyResult->templateId;

		try
		{
			$startResult = $this->activationService->startTemplate($templateId, $request->userId);
			if (!$startResult->isSuccess())
			{
				$this->rollbackCreatedTemplate($templateId);

				return $startResult;
			}

			$instanceId = $startResult->setupTemplateEvent?->getInstanceId();
			if ($request->constants && $instanceId)
			{
				$fillResult = $this->setupTemplateService->fill(
					$request->userId,
					$templateId,
					$instanceId,
					$request->constants,
					skipAccessValidation: true,
				);
				if (!$fillResult->isSuccess())
				{
					$startResult->addErrors($fillResult->getErrors());
					$this->rollbackCreatedTemplate($templateId);
				}
			}

			return $startResult;
		}
		catch (\Throwable $e)
		{
			$this->rollbackCreatedTemplate($templateId);

			throw $e;
		}
	}

	private function rollbackCreatedTemplate(int $templateId): void
	{
		try
		{
			$this->deleteService->killWorkflow([$templateId]);
			\CBPWorkflowTemplateLoader::delete($templateId);
		}
		catch (\Throwable)
		{
		}
	}
}
