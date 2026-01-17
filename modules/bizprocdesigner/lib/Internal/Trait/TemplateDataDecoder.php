<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Trait;

use Bitrix\Main\Web\Json;

trait TemplateDataDecoder
{
	protected function decodeTemplateData(array $templateData): array
	{
		$template = $templateData['arWorkflowTemplate'] ?? $templateData['workflowTemplate'] ?? [];
		$variables = $templateData['arWorkflowVariables'] ?? $templateData['workflowVariables'] ?? [];
		$parameters = $templateData['arWorkflowParameters'] ?? $templateData['workflowParameters'] ?? [];
		$constants = $templateData['arWorkflowConstants'] ?? $templateData['workflowConstants'] ?? [];

		return [
			'template' => $template ? Json::decode($template) : [],
			'variables' => $variables ? Json::decode($variables) : [],
			'parameters' => $parameters ? Json::decode($parameters) : [],
			'constants' => $constants ? Json::decode($constants) : [],
		];
	}
}
