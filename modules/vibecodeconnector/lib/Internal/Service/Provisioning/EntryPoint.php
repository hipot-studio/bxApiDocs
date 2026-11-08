<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Provisioning;

final class EntryPoint
{
	private const VIBECODE = 'vibecode';
	private const BITRIX24 = 'bitrix24';

	private const ATTR_ENTRY_POINT = 'entry_point';
	private const ATTR_SERVER_ISS = 'vibecode_server_iss';
	private const ATTR_ORIGIN = 'vibecode_origin';
	private const ORIGIN_COWORK = 'cowork';
	private const TRUSTED_ISS_PREFIX = 'vibecode';

	private function __construct(
		private readonly string $value,
		private readonly ?string $serverIss,
		private readonly ?string $origin = null,
	) {
	}

	public static function vibecode(?string $serverIss, ?string $origin = null): self
	{
		if ($serverIss === null || $serverIss === '')
		{
			throw new \InvalidArgumentException('Vibecode entry point requires a non-empty server iss');
		}

		return new self(self::VIBECODE, $serverIss, $origin);
	}

	public static function bitrix24(): self
	{
		return new self(self::BITRIX24, null);
	}

	public function isVibecode(): bool
	{
		return $this->value === self::VIBECODE;
	}

	public function isCowork(): bool
	{
		return $this->origin === self::ORIGIN_COWORK
			&& str_starts_with((string)$this->serverIss, self::TRUSTED_ISS_PREFIX)
		;
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

		if ($this->origin !== null && $this->origin !== '')
		{
			$attributes[self::ATTR_ORIGIN] = $this->origin;
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
