<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Command\Activity\Complex;

use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\PortRuleDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConstructionDto;
use Bitrix\BizprocDesigner\Internal\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\ValidationService;

class ValidateSingleRuleCommand extends AbstractCommand
{
	private ValidationService $validationService;

	public function __construct(
		public readonly PortRuleDto $portRuleDto,
	)
	{
		$this->validationService = ServiceLocator::getInstance()->get('main.validation.service');
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
			$result = $this->validationService->validate($expression);
			if (!$result->isSuccess())
			{
				return $result;
			}
		}

		return new Result();
	}
}
