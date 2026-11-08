<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Dictionary;

/**
 * A group the form scenarios are offered in.
 *
 * @internal
 */
final readonly class ScenarioCategory
{
	public function __construct(
		public string $id,
		public string $title,
	)
	{
	}

	/**
	 * @param array<mixed> $configuration {@see \Bitrix\Crm\Service\WebForm\WebFormScenarioService::getScenarioCategoryList()}
	 */
	public static function fromConfiguration(array $configuration): self
	{
		return new self(
			id: (string)($configuration['id'] ?? ''),
			title: (string)($configuration['title'] ?? ''),
		);
	}
}
