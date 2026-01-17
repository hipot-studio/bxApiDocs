<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Trait;

use Bitrix\Bizproc\Automation\Helper;
use Bitrix\Main\Loader;

trait ActivitySettingsDecoder
{
	use TemplateDataDecoder;

	protected function decodeActivitySettings(array $request, array $documentType): array
	{
		Loader::requireModule('bizproc');

		[
			'template' => $template,
			'variables' => $variables,
			'parameters' => $parameters,
			'constants' => $constants,
		] = $this->decodeTemplateData($request);

		$properties = $request;
		unset(
			$properties['arWorkflowTemplate'],
			$properties['workflowTemplate'],
			$properties['arWorkflowParameters'],
			$properties['workflowParameters'],
			$properties['arWorkflowVariables'],
			$properties['workflowVariables'],
			$properties['arWorkflowConstants'],
			$properties['workflowConstants'],
		);
		$properties = Helper::unConvertProperties($properties, $documentType);

		return [
			'template' => $template,
			'parameters' => $parameters,
			'variables' => $variables,
			'constants' => $constants,
			'properties' => $properties,
		];
	}

	protected function extractDocumentType(array $request): ?array
	{
		return $request['documentType'] ?? null;
	}
}
