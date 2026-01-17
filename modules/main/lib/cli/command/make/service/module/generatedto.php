<?php

namespace Bitrix\Main\Cli\Command\Make\Service\Module;

use InvalidArgumentException;

final class GenerateDto
{
	public function __construct(
		public readonly string $id,
		public readonly string $version = '1.0.0',
		public ?string $name = null,
		public ?string $description = null,
	)
	{
		$this->validateId();
		$this->validateVersion();
	}

	private function validateId(): void
	{
		if (empty($this->id))
		{
			throw new InvalidArgumentException('Empty module id');
		}
		elseif (substr_count($this->id, '.') !== 1)
		{
			throw new InvalidArgumentException('Invalid module name. MUST be dot inside name');
		}
	}

	private function validateVersion(): void
	{
		if (preg_match('/^\d+\.\d+\.\d+$/', $this->version) !== 1)
		{
			throw new InvalidArgumentException('Verion MUST be in format "N.M.Z"');
		}
	}
}
