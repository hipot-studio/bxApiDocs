<?php

namespace Bitrix\Main\Cli\Command\Make\Service;

use Bitrix\Main\Cli\Command\Make\Service\Controller\GenerateDto;
use Bitrix\Main\Cli\Helper\NamespaceGenerator;
use Bitrix\Main\Cli\Helper\Renderer;
use Bitrix\Main\Cli\Command\Make\Templates\ControllerTemplate;
use Bitrix\Main\Cli\Helper\GenerateResult;
use Bitrix\Main\Cli\Helper\PathGenerator;
use InvalidArgumentException;

final class ControllerService
{
	private Renderer $renderer;
	private NamespaceGenerator $namespaceGenerator;

	public function __construct(
		private readonly PathGenerator $pathGenerator,
	)
	{
		$this->renderer = new Renderer();
		$this->namespaceGenerator = new NamespaceGenerator();
	}

	public function generateFile(GenerateDto $dto): GenerateResult
	{
		$namespace = $this->generateNamespace($dto);
		$className = $this->normalizeControllerName($dto->name);
		$path = $this->pathGenerator->generatePathToClass($namespace, $className);

		$this->renderer->renderToFile(
			$path,
			new ControllerTemplate(
				$className,
				$namespace,
				$dto->moduleId,
				$dto->alias,
				$dto->actions,
			),
		);

		return new GenerateResult($path);
	}

	#region internal

	private function generateNamespace(GenerateDto $dto): string
	{
		$moduleId = $dto->moduleId;
		if (empty($moduleId))
		{
			throw new InvalidArgumentException('If namespace option is not set, module argument MUST BE set!');
		}

		return $this->namespaceGenerator->generateNamespaceForModule(
			$moduleId,
			$dto->getNamespace('Infrastructure\Controller'),
		);
	}

	private function normalizeControllerName(string $name): string
	{
		$name = preg_replace('/Controller$/i', '', $name);
		if (empty($name))
		{
			throw new InvalidArgumentException('Invalid controller name');
		}

		return ucfirst($name);
	}

	#endregion internal
}
