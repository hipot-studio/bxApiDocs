<?php

namespace Bitrix\Main\Cli\Command\Make\Service;

use Bitrix\Main\Cli\Command\Make\Service\MessageHandler\GenerateDto;
use Bitrix\Main\Cli\Command\Make\Templates\MessageHandlerTemplate;
use Bitrix\Main\Cli\Helper\GenerateResult;
use Bitrix\Main\Cli\Helper\PathGenerator;
use Bitrix\Main\Cli\Helper\NamespaceGenerator;
use Bitrix\Main\Cli\Helper\Renderer;

final class MessageHandlerService
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
				explode('.', $dto->messageModuleId),
			),
		);

		$namespace = $this->namespaceGenerator->generateNamespaceForModule(
			$dto->handlerModuleId,
			$dto->getNamespace(
				'Internals\\Integration\\' . $moduleNamespacePart . '\\MessageHandler',
			),
		);
		$messageName = rtrim(
			ucfirst($dto->name),
			'Message'
		);
		$messageClassName = $messageName . 'Message';
		$handlerClassName = $messageName . 'MessageHandler';
		$path = $this->pathGenerator->generatePathToClass($namespace, $handlerClassName);

		$this->renderer->renderToFile(
			$path,
			new MessageHandlerTemplate(
				$handlerClassName,
				$messageClassName,
				$namespace,
			),
		);

		return new GenerateResult($path);
	}
}
