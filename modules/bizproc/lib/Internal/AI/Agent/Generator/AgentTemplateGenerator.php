<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator;

use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\AgentConfig;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\ActivityNodeBuilder;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\ActivityRegistry;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\TemplateBuilder;
use Bitrix\Main\IO\File;

final class AgentTemplateGenerator
{
	private string $nodesBasePath;
	private array $propertyWarnings = [];

	public function __construct(string $nodesBasePath)
	{
		$this->nodesBasePath = rtrim($nodesBasePath, '/');
	}

	public function getPropertyWarnings(): array
	{
		return $this->propertyWarnings;
	}

	/**
	 * @return string[] List of changed file paths
	 */
	public function generate(string $agentName): array
	{
		if (!preg_match('/^[a-z0-9_]+$/', $agentName))
		{
			throw new \InvalidArgumentException("Invalid agent name: '$agentName'. Only lowercase letters, digits and underscores allowed.");
		}

		$agentDir = $this->nodesBasePath . '/' . $agentName;
		$configPath = $agentDir . '/template.source.json';

		$config = AgentConfig::fromFile($configPath);

		$errors = $config->validate();
		if (!empty($errors))
		{
			throw new \RuntimeException('Config validation failed: ' . implode('; ', $errors));
		}

		$registry = new ActivityRegistry();

		$validator = new ConfigValidator($registry);
		$this->propertyWarnings = $validator->validate($config);
		$nodeBuilder = new ActivityNodeBuilder($registry);
		$templateBuilder = new TemplateBuilder($registry, $nodeBuilder);
		$template = $templateBuilder->build($config);
		$templateJson = \Bitrix\Main\Web\Json::encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		$templatePath = $agentDir . '/template.json';
		File::putFileContents($templatePath, $templateJson . PHP_EOL);

		$installerPath = $agentDir . '/installer.php';
		$this->updateInstaller($installerPath, $agentName);

		return [
			$templatePath,
			$installerPath,
		];
	}

	private function updateInstaller(string $path, string $agentName): void
	{
		if (File::isFileExists($path))
		{
			$contents = File::getFileContents($path);
			if (!is_string($contents))
			{
				throw new \RuntimeException("Failed to read file: {$path}");
			}

			$mtime = time();
			$updated = preg_replace(
				'|/\*mtime\*/\d+/\*mtime\*/|',
				"/*mtime*/{$mtime}/*mtime*/",
				$contents,
				1,
				$count,
			);
			if ($count === 0)
			{
				throw new \RuntimeException("installer.php does not contain /*mtime*/.../*mtime*/ marker: {$path}");
			}
			File::putFileContents($path, $updated);
		}
		else
		{
			throw new \RuntimeException("installer.php not found: {$path}. Create it before running the generator.");
		}
	}
}

