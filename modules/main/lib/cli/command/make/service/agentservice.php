<?php

namespace Bitrix\Main\Cli\Command\Make\Service;

use Bitrix\Main\Cli\Command\Make\Service\Agent\GenerateDto;
use Bitrix\Main\Cli\Command\Make\Service\Agent\GenerateResult;
use Bitrix\Main\Cli\Command\Make\Templates\AgentTemplate;
use Bitrix\Main\Cli\Helper\PathGenerator;
use Bitrix\Main\Cli\Helper\NamespaceGenerator;
use Bitrix\Main\Cli\Helper\Renderer;

final class AgentService
{
	private Renderer $renderer;
	private NamespaceGenerator $namespaceGenerator;

	public function __construct(
		private PathGenerator $pathGenerator,
	)
	{
		$this->renderer = new Renderer();
		$this->namespaceGenerator = new NamespaceGenerator();
	}

	public function generateFile(GenerateDto $dto): GenerateResult
	{
		$namespace = $this->namespaceGenerator->generateNamespaceForModule(
			$dto->moduleId,
			$dto->getNamespace('Infrastructure\\Agent')
		);
		$className = ucfirst($dto->name) . 'Agent';
		$path = $this->pathGenerator->generatePathToClass($namespace, $className);

		$this->renderer->renderToFile(
			$path,
			new AgentTemplate(
				$className,
				$namespace,
			),
		);

		return new GenerateResult(
			$path,
			"\\{$namespace}\\{$className}::run();",
			$dto->moduleId,
		);
	}
}
