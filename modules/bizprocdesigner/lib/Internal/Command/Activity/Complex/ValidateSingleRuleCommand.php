<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Command\Activity\Complex;

use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\PortRuleDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ActionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\BaseExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConstructionDto;
use Bitrix\BizprocDesigner\Internal\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Main\Validation\ValidationService;

class ValidateSingleRuleCommand extends AbstractCommand
{
	private ValidationService $validationService;
	private readonly Searcher $searcher;

	public function __construct(
		public readonly PortRuleDto $portRuleDto,
		?Searcher $searcher = null,
	)
	{
		Loader::requireModule('bizproc');

		$this->validationService = ServiceLocator::getInstance()->get('main.validation.service');
		$this->searcher = $searcher ?? Container::instance()->getActivitySearcherService();
	}

	protected function execute(): ValidateSingleRuleCommandResult
	{
		foreach ($this->portRuleDto->rules as $rule)
		{
			$result = $this->validateConstructions($rule->constructions);
			if (!$result->isSuccess())
			{
				return new ValidateSingleRuleCommandResult(isFilled: false);
			}
		}

		return new ValidateSingleRuleCommandResult(isFilled: true);
	}

	/**
	 * @param list<ConstructionDto> $constructions
	 * @return Result
	 */
	protected function validateConstructions(array $constructions): Result
	{
		foreach ($constructions as $construction)
		{
			$expression = $construction->expression;
			$result = $this->validateExpression($expression);
			if (!$result->isSuccess())
			{
				return $result;
			}
		}

		return new Result();
	}

	private function validateExpression(BaseExpressionDto $expression): ValidationResult
	{
		$result = $this->validationService->validate($expression);

		if ($expression instanceof ActionExpressionDto && $expression->actionId)
		{
			$this->validateAction($expression, $result);
		}

		return $result;
	}

	private function validateAction(ActionExpressionDto $expression, ValidationResult $result): void
	{
		$description = $this->searcher->searchByCode($expression->actionId);
		if (!$description)
		{
			$message = Loc::getMessage('BIZPROCDESIGNER_COMMAND_VALIDATE_SINGLE_RULE_NO_ACTIVITY', [
				'#NAME#' => $expression->actionId,
			]);
			$result->addError(new Error($message));

			return;
		}

		$handlesDocument = $description->getNodeActionSettings()['HANDLES_DOCUMENT'] ?? false;
		if ($handlesDocument && empty($expression->document))
		{
			$message = Loc::getMessage('BIZPROCDESIGNER_COMMAND_VALIDATE_SINGLE_RULE_NO_HANDLE_DOCUMENT', [
				'#NAME#' => $description->getName(),
			]);

			$result->addError(new Error($message));
		}
	}
}
