<?php

namespace Bitrix\VisualEditor\Public\Adapter;

final readonly class EditorCapabilities
{
	private const MAX_OPS = 500;

	public bool $verifyBaseVersion;
	public int $maxOps;

	/**
	 * Creates a validated server policy without scalar coercion.
	 *
	 * @param bool $verifyBaseVersion
	 * @param int $maxOps
	 */
	public function __construct(mixed $verifyBaseVersion = true, mixed $maxOps = 10)
	{
		if (!is_bool($verifyBaseVersion))
		{
			throw new \InvalidArgumentException('verifyBaseVersion must be a boolean.');
		}
		if (!is_int($maxOps) || $maxOps < 1 || $maxOps > self::MAX_OPS)
		{
			throw new \InvalidArgumentException('maxOps must be an integer between 1 and 500.');
		}

		$this->verifyBaseVersion = $verifyBaseVersion;
		$this->maxOps = $maxOps;
	}
}
