<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\LegalRegistry;

use InvalidArgumentException;

final readonly class DraftSettings
{
	/** @var array<string, string> */
	public array $templateFields;

	/**
	 * @param array<string, string> $templateFields
	 */
	public function __construct(
		public string $type,
		public ?string $provider,
		public bool $html,
		array $templateFields,
	)
	{
		if (!in_array($type, ['C', 'S'], true))
		{
			throw new InvalidArgumentException('Invalid draft settings.');
		}

		foreach ($templateFields as $key => $value)
		{
			if (!is_string($key) || $key === '' || !is_string($value))
			{
				throw new InvalidArgumentException('Invalid draft template fields.');
			}
		}

		$this->templateFields = $templateFields;
	}
}
