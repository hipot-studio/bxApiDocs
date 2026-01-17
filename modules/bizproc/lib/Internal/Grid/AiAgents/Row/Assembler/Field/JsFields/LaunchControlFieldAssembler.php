<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Row\Assembler\Field\JsFields;

use Bitrix\Main\Type\DateTime;

class LaunchControlFieldAssembler extends JsExtensionFieldAssembler
{
	protected function getExtensionClassName(): string
	{
		return 'LaunchControlField';
	}

	protected function getRenderParams($rawValue): array
	{
		return [
			'agentId' => $this->getAgentId($rawValue),
			'launchedAt' => $this->getLaunchedAt($rawValue),
		];
	}

	protected function prepareColumnForExport($data): string
	{
		return '';
	}

	private function getLaunchedAt($rawValue): ?int
	{
		if ($rawValue['ACTIVATED_AT'] instanceof DateTime)
		{
			return $rawValue['ACTIVATED_AT']->getTimestamp();
		}

		return null;
	}

	private function getAgentId($rawValue): ?int
	{
		if (empty($rawValue['LAUNCHED_BY_USER_DATA'] ?? ''))
		{
			return $rawValue['ID'] ?? null;
		}

		return null;
	}
}