<?php

namespace Bitrix\Main\Cli\Command\Make\Service\Agent;

final class GenerateResult extends \Bitrix\Main\Cli\Helper\GenerateResult
{
	public function __construct(
		?string $path = null,
		public readonly ?string $runCode = null,
		public readonly ?string $moduleId = null,
	)
	{
		parent::__construct($path);
	}

	public function getSuccessMessage(): string
	{
		return <<<TEXT

		A file has been created:
		<info>{$this->path}</info>

		PHP code for register agent:
		<comment>\CAgent::AddAgent(
			'{$this->runCode}',
			'{$this->moduleId}',
			period: 'N',
			interval: 60,
			next_exec: \ConvertTimeStamp(time(), 'FULL')
		);</comment>

		TEXT;
	}
}
