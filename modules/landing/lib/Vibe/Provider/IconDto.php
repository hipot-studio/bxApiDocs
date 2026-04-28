<?php

namespace Bitrix\Landing\Vibe\Provider;

/**
 * Data of icon.
 */
final class IconDto
{
	/**
	 * @var string|null Icon set name (f.e. social). May not exists if use default set (main)
	 */
	public ?string $set;

	/**
	 * @var string - code of icon (f.e. --snowflake)
	 */
	public string $code;

	public function __construct(string $code, ?string $set = null)
	{
		if (!str_starts_with($code, '--'))
		{
			$code = '--' . $code;
		}

		$this->code = $code;
		$this->set = $set;
	}

	public function toArray(): array
	{
		$icon = [
			'code' => $this->code,
		];
		if (isset($this->set))
		{
			$icon['set'] = $this->set;
		}

		return $icon;
	}
}