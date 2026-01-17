<?php

namespace Bitrix\Main\Cli\Command\Make\Service;

use Bitrix\Main\Cli\Command\Make\Service\EventHandler\GenerateDto;
use Bitrix\Main\Cli\Command\Make\Templates\EventHandlerTemplate;
use Bitrix\Main\Cli\Helper\GenerateResult;
use Bitrix\Main\Cli\Helper\PathGenerator;
use Bitrix\Main\Cli\Helper\NamespaceGenerator;
use Bitrix\Main\Cli\Helper\Renderer;

final class EventHandlerService
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
		$moduleNamespacePart = join(
			'\\',
			array_map(
				static fn($part) => ucfirst($part),
				explode('.', $dto->eventModuleId),
			),
		);

		$namespace = $this->namespaceGenerator->generateNamespaceForModule(
			$dto->handlerModuleId,
			$dto->getNamespace(
				'Internals\\Integration\\' . $moduleNamespacePart . '\\EventHandler',
			),
		);
		$eventClassName = ucfirst($dto->name) . 'Event';
		$handlerClassName = ucfirst($dto->name) . 'EventHandler';
		$path = $this->pathGenerator->generatePathToClass($namespace, $handlerClassName);

		$this->renderer->renderToFile(
			$path,
			new EventHandlerTemplate(
				$handlerClassName,
				$eventClassName,
				$namespace,
			),
		);

		return new GenerateResult($path);
	}
}
