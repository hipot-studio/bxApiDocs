<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Replication\Template\Repetition\Conversion\Converters;

use Bitrix\Tasks\Internals\Task;
use Bitrix\Tasks\Replication\Template\Repetition\Conversion\ConverterInterface;
use Bitrix\Tasks\Replication\RepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Template;

final class ParameterConverter implements ConverterInterface
{
	public function convert(RepositoryInterface $repository): array
	{
		$taskFields = [];
		$template = $repository->getEntity();

		if ($template === null || $template->getParams()->isEmpty())
		{
			return $taskFields;
		}

		foreach ($template->getParams() as $param)
		{
			if ($param->getCode() === Template\TemplateParameter::RequireResult->value)
			{
				$taskFields['SE_PARAMETER'][] = [
					'CODE' => Task\ParameterTable::PARAM_RESULT_REQUIRED,
					'VALUE' => $param->getValue(),
				];
			}
		}

		return $taskFields;
	}

	public function getTemplateFieldName(): string
	{
		return 'PARAMS';
	}
}
