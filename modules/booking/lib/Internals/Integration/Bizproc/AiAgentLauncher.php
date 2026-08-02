<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Bizproc;

use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Result\TemplateCreatedResult;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\SystemTemplateActivationService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

class AiAgentLauncher
{
	public function __construct(
		private readonly AiAgentTemplateQuery $templateQuery,
	)
	{
	}

	public function launch(): Result
	{
		if (!Loader::includeModule('bizproc'))
		{
			return (new Result())->addError(new Error('Bizproc module is not available'));
		}

		$userTemplateIds = $this->templateQuery->findUserTemplateIds();
		if (count($userTemplateIds) > 1)
		{
			return (new Result())->addError(new Error('Multiple user AI agent templates found'));
		}

		if (count($userTemplateIds) === 1)
		{
			return $this->startExisting($userTemplateIds[0]);
		}

		$systemTemplateId = $this->templateQuery->findSystemTemplateId();
		if ($systemTemplateId === null)
		{
			return (new Result())->addError(new Error('AI agent template is not available'));
		}

		return $this->copyAndStart($systemTemplateId, $this->resolveUserId());
	}

	public function ensureRunning(int|null $userId = null): Result
	{
		if (!Loader::includeModule('bizproc'))
		{
			return new Result();
		}

		if (!empty($this->templateQuery->findUserTemplateIds()))
		{
			return new Result();
		}

		$systemTemplateId = $this->templateQuery->findSystemTemplateId();
		if ($systemTemplateId === null)
		{
			return new Result();
		}

		$userId ??= $this->resolveUserId();

		return $this->copyAndStart($systemTemplateId, $userId);
	}

	private function startExisting(int $templateId): Result
	{
		$startResult = $this->getActivationService()->startTemplate($templateId);

		return (new Result())->addErrors($startResult->getErrors());
	}

	private function copyAndStart(int $systemTemplateId, int $userId): Result
	{
		if ($userId <= 0)
		{
			return (new Result())->addError(new Error('User is not authenticated'));
		}

		$activationService = $this->getActivationService();

		$copyResult = $activationService->copyTemplate($systemTemplateId, $userId);
		if (!$copyResult instanceof TemplateCreatedResult)
		{
			return (new Result())->addErrors($copyResult->getErrors());
		}

		$startResult = $activationService->startTemplate($copyResult->templateId);

		return (new Result())->addErrors($startResult->getErrors());
	}

	private function resolveUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	private function getActivationService(): SystemTemplateActivationService
	{
		return ServiceLocator::getInstance()->get(SystemTemplateActivationService::class);
	}
}
