<?php

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ORM\Data\UpdateResult;

use Bitrix\Ai\Integration\Bizproc\Event\Enum\ProcessedEvent;
use Bitrix\Ai\Integration\Bizproc\Event\Payload\AiListenerParameters;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Result\TemplateCreatedResult;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\AiAgentRepository;
use Bitrix\Bizproc\Starter\Dto\DocumentDto;
use Bitrix\Bizproc\Starter\Enum\Scenario;
use Bitrix\Bizproc\Starter\Result\StartResult;
use Bitrix\Bizproc\Starter\Starter;

class SystemTemplateActivationService
{
	private const AI_AGENT_START_TRIGGER = 'AiAgentStartTrigger';

	public function __construct(
		private readonly AiAgentRepository $aiAgentRepository,
	) {}

	public function includeModuleAi(Result $result = new Result()): Result
	{
		if (!Loader::includeModule('ai'))
		{
			$result->addError(
				\Bitrix\Bizproc\Error::fromCode(
					\Bitrix\Bizproc\Error::MODULE_NOT_INSTALLED,
					['moduleName' => 'ai'],
				)
			);
		}

		return $result;
	}

	public function copyTemplate(int $templateId, int $userId): Result|TemplateCreatedResult
	{
		$copier = new \Bitrix\Bizproc\Copy\Implement\WorkflowTemplate();
		$fields = $copier->getFields($templateId);
		if (empty($fields))
		{
			return (new Result())->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getError(['#ID#' => $templateId]));
		}

		$fields = $copier->prepareFieldsToCopy($fields);

		$fields['USER_ID'] = $userId;
		$fields['IS_SYSTEM'] = 'N';
		$fields['ACTIVE'] = 'Y';
		$fields['SYSTEM_CODE'] = null;
		$fields['ACTIVATED_BY'] = $userId;
		$fields['ACTIVATED_AT'] = new DateTime();

		$newTemplateId = (int)$copier->add($fields);
		if ($newTemplateId <= 0)
		{
			return (new Result())->addError(ErrorMessage::CREATE_WORKFLOW->getError());
		}

		$result = new TemplateCreatedResult($newTemplateId);

		$fields['ID'] = $newTemplateId;

		$resultData = [
			'rawTemplateFields' => $fields,
		];

		$result->setData($resultData);

		return $result;
	}

	public function startTemplate(int $templateId): Result
	{
		$includeResult = $this->includeModuleAi();

		if (
			!$includeResult->isSuccess()
			|| !class_exists(AiListenerParameters::class)
		)
		{
			return $includeResult;
		}

		$startResult = $this->handleOnAiAgentStart(
			new AiListenerParameters(
				new \Bitrix\Bizproc\Starter\Event(
					name: ProcessedEvent::OnAiAgentStart->name,
				),
				$templateId,
				(int)CurrentUser::get()->getId(),
			),
		);

		if ($startResult->isSuccess())
		{
			$this->markAsActivatedNow($templateId);
		}

		return $startResult;
	}

	/***
	 * @param AiListenerParameters $parameters
	 *
	 * @return StartResult
	 */
	private function handleOnAiAgentStart(AiListenerParameters $parameters): StartResult
	{
		$document = \Bitrix\Bizproc\Public\Entity\Document\Workflow::getComplexId((string)$parameters->templateId);
		$documentType = \Bitrix\Bizproc\Public\Entity\Document\Workflow::getComplexType();
		$documentDto = new DocumentDto($document, $documentType);

		return Starter::getByScenario(Scenario::onEvent)
			->addEvent(self::AI_AGENT_START_TRIGGER, [$documentDto], $parameters->toArray())
			->setTemplateIds([$parameters->templateId])
			->start()
		;
	}


	private function markAsActivatedNow(int $templateId): UpdateResult
	{
		$nowDateTime = new DateTime();
		return $this->aiAgentRepository->updateActivationTimestamp($templateId, $nowDateTime);
	}
}