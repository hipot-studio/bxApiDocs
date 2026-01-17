<?php

namespace Bitrix\Main\Cli\Command\Make\Service;

use Bitrix\Main\Cli\Command\Make\Service\Module\GenerateDto;
use Bitrix\Main\Cli\Command\Make\Templates\EmptyTemplate;
use Bitrix\Main\Cli\Command\Make\Templates\LangTemplate;
use Bitrix\Main\Cli\Command\Make\Templates\Module\DefaultOptionTemplate;
use Bitrix\Main\Cli\Command\Make\Templates\Module\InstallTemplate;
use Bitrix\Main\Cli\Command\Make\Templates\Module\VersionTemplate;
use Bitrix\Main\Cli\Helper\Renderer;
use Bitrix\Main\Result;

final class ModuleService
{
	private Renderer $renderer;

	public function __construct(
		private readonly string $pathToModulesFolder,
	)
	{
		$this->renderer = new Renderer();
	}

	public function generate(GenerateDto $dto): Result
	{
		$result = new Result();

		$moduleId = strtolower($dto->id);
		$moduleIdNormalized = str_replace('.', '_', $moduleId);
		$phrasePrefix = strtoupper($moduleIdNormalized);

		$pathToModule = rtrim($this->pathToModulesFolder, '/') . "/{$moduleId}";

		$filesToRender = [
			$pathToModule . '/install/version.php' => new VersionTemplate($dto->version),
			$pathToModule . '/install/index.php' => new InstallTemplate($moduleId, $moduleIdNormalized, $phrasePrefix),
			$pathToModule . '/install/mysql/install.sql' => new EmptyTemplate(),
			$pathToModule . '/install/mysql/uninstall.sql' => new EmptyTemplate(),
			$pathToModule . '/default_option.php' => new DefaultOptionTemplate($moduleIdNormalized),
			$pathToModule . '/lang/ru/install/index.php' => new LangTemplate($this->getLangsPhrases($phrasePrefix, $dto)),
		];
		foreach ($filesToRender as $path => $template)
		{
			$this->renderer->renderToFile($path, $template);
		}

		$result->setData(
			array_keys($filesToRender),
		);

		return $result;
	}

	private function getLangsPhrases(string $prefix, GenerateDto $dto): array
	{
		$result = [
			$prefix . '_NAME' => $dto->name,
			$prefix . '_DESCRIPTION' => $dto->description,
		];

		return array_filter($result);
	}
}
