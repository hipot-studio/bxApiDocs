<?php

namespace Bitrix\Main\Cli\Command\Make\Service;

use Bitrix\Main\Cli\Command\Make\Service\Service\GenerateDto;
use Bitrix\Main\Cli\Command\Make\Templates\ServiceTemplate;
use Bitrix\Main\Cli\Helper\GenerateResult;
use Bitrix\Main\Cli\Helper\PathGenerator;
use Bitrix\Main\Cli\Helper\NamespaceGenerator;
use Bitrix\Main\Cli\Helper\Renderer;

final class ServiceService
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
			$dto->getNamespace('Public\\Service'),
		);
		$className = ucfirst($dto->name) . 'Service';
		$path = $this->pathGenerator->generatePathToClass($namespace, $className);

		$this->renderer->renderToFile(
			$path,
			new ServiceTemplate(
				$className,
				$namespace,
			),
		);

		return new GenerateResult($path);
	}
}
