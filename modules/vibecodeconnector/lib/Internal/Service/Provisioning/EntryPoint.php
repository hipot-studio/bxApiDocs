<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Provisioning;

final class EntryPoint
{
	public const VIBECODE = 'vibecode';
	public const BITRIX24 = 'bitrix24';

	private const ATTR_ENTRY_POINT = 'entry_point';
	private const ATTR_SERVER_ISS = 'vibecode_server_iss';

	private function __construct(
		private readonly string $value,
		private readonly ?string $serverIss,
	) {
	}

	public static function vibecode(?string $serverIss): self
	{
		if ($serverIss === null || $serverIss === '')
		{
			throw new \InvalidArgumentException('Vibecode entry point requires a non-empty server iss');
		}

		return new self(self::VIBECODE, $serverIss);
	}

	public static function bitrix24(): self
	{
		return new self(self::BITRIX24, null);
	}

	public function isVibecode(): bool
	{
		return $this->value === self::VIBECODE;
	}

	/**
	 * Attributes written onto the issued entity.
	 *
	 * @return array<string, string>
	 */
	public function toAttributes(): array
	{
		$attributes = [self::ATTR_ENTRY_POINT => $this->value];
		if ($this->serverIss !== null && $this->serverIss !== '')
		{
			$attributes[self::ATTR_SERVER_ISS] = $this->serverIss;
		}

		return $attributes;
	}

	/**
	 * Identifying subset used to look up previously issued entities of the same source.
	 *
	 * @return array<string, string>
	 */
	public function dedupAttributes(): array
	{
		if ($this->serverIss !== null && $this->serverIss !== '')
		{
			return [self::ATTR_SERVER_ISS => $this->serverIss];
		}

		return [self::ATTR_ENTRY_POINT => $this->value];
	}
}
