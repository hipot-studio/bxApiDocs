<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig;

use Bitrix\Main\IO\File;

final class AgentConfig
{
	public function __construct(
		public readonly string $name,
		public readonly string $title,
		public readonly string $description,
		/** @var array<string, ConstantConfig> */
		public readonly array $constants,
		/** @var array<string, FlowConfig> */
		public readonly array $flows,
		public readonly ?string $wizardTitle = null,
		public readonly ?string $wizardDescription = null,
	) {}

	public static function fromFile(string $path): self
	{
		if (!File::isFileExists($path))
		{
			throw new \InvalidArgumentException("Config file not found: {$path}");
		}

		$content = File::getFileContents($path);
		if (!is_string($content))
		{
			throw new \InvalidArgumentException("Failed to read config: {$path}");
		}

		try
		{
			$data = \Bitrix\Main\Web\Json::decode($content);
		}
		catch (\Bitrix\Main\ArgumentException $e)
		{
			throw new \InvalidArgumentException("Invalid JSON in {$path}: {$e->getMessage()}");
		}

		if (!is_array($data))
		{
			throw new \InvalidArgumentException("Config must be a JSON object: {$path}");
		}

		return self::fromArray($data);
	}

	public static function fromArray(array $data): self
	{
		$constants = [];
		foreach ($data['constants'] ?? [] as $key => $constData)
		{
			$constants[$key] = ConstantConfig::fromArray($key, $constData);
		}

		$flows = [];
		foreach ($data['flows'] ?? [] as $name => $flowData)
		{
			$flows[$name] = FlowConfig::fromArray($name, $flowData);
		}

		return new self(
			name: $data['name'] ?? '',
			title: $data['title'] ?? '',
			description: $data['description'] ?? '',
			constants: $constants,
			flows: $flows,
			wizardTitle: $data['wizard_title'] ?? null,
			wizardDescription: $data['wizard_description'] ?? null,
		);
	}

	public function validate(): array
	{
		$errors = [];

		if (empty($this->name))
		{
			$errors[] = 'name is required';
		}

		if (empty($this->title))
		{
			$errors[] = 'title is required';
		}

		if (empty($this->flows))
		{
			$errors[] = 'at least one flow is required';
		}

		return $errors;
	}
}
