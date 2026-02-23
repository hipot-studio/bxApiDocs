<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Controller;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\Service\Feature\AiAgentsFeature;
use Bitrix\Bizproc\Internal\Service\SetupTemplate\SetupTemplateService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Request;

class SetupTemplate extends Controller
{
	private readonly SetupTemplateService $setupTemplateService;
	private readonly AiAgentsFeature $aiAgentsFeature;

	public function __construct(?Request $request = null)
	{
		parent::__construct($request);

		$this->setupTemplateService = new SetupTemplateService();
		$this->aiAgentsFeature = ServiceLocator::getInstance()->get(AiAgentsFeature::class);
	}

	protected function processBeforeAction(\Bitrix\Main\Engine\Action $action): bool
	{
		parent::processBeforeAction($action);

		return $this->isAgentsFeatureAvailable();
	}

	/**
	 * @param string $instanceId Identifier of workflow instance
	 * @param int $templateId Identifier of workflow template
	 * @param array<string, string> $constantValues [constantId => constantValue, ...]
	 *
	 * @return void
	 */
	public function fillAction(
		int $templateId,
		string $instanceId,
		array $constantValues = [],
	): void
	{
		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return;
		}

		$result = $this->setupTemplateService->fill($userId, $templateId, $instanceId, $constantValues);
		$this->addErrors($result->getErrors());
	}

	private function isAgentsFeatureAvailable(): bool
	{
		$isAiAgentFeatureAvailable = $this->aiAgentsFeature->isAvailable();

		if ($isAiAgentFeatureAvailable)
		{
			return true;
		}

		$error = $this->aiAgentsFeature->makeUnavailableByTariffError();
		$this->addError($error);

		return false;
	}
}
