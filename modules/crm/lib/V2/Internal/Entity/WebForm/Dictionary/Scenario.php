<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Dictionary;

/**
 * A form scenario - the preset an editor offers before a form is created: the fields it fills in,
 * the sections it opens and the way it is entered.
 *
 * @internal
 */
final readonly class Scenario
{
	/**
	 * @param string[] $sections sections of the editor the scenario opens
	 * @param string[] $expertSections sections the same scenario opens for an experienced user
	 * @param string[] $actions what the scenario does instead of opening the editor
	 */
	public function __construct(
		public string $id,
		public string $title,
		public ?string $description,
		public string $categoryId,
		public ?string $iconUrl,
		public array $sections,
		public array $expertSections,
		public ?string $defaultSection,
		public bool $openable,
		public bool $soon,
		public array $actions,
	)
	{
	}

	/**
	 * Builds a scenario out of the configuration the portal keeps for its own editor.
	 *
	 * Two keys of that configuration never reach the model: `disabled`, written only while it is
	 * false, and `payment`, a duplicate of `actions` under a foreign name. An action keeps its
	 * identifier alone - the help link and the steps of a tour behind it drive the internal editor
	 * and nothing else.
	 *
	 * @param array<mixed> $configuration {@see \Bitrix\Crm\Service\WebForm\Scenario\BaseScenario::getConfiguration()}
	 */
	public static function fromConfiguration(array $configuration): self
	{
		return new self(
			id: (string)($configuration['id'] ?? ''),
			title: (string)($configuration['title'] ?? ''),
			description: self::text($configuration, 'description'),
			categoryId: (string)($configuration['category'] ?? ''),
			iconUrl: self::text($configuration, 'icon'),
			sections: self::sections($configuration, 'items'),
			expertSections: self::sections($configuration, 'expertModeItems'),
			defaultSection: self::text($configuration, 'defaultSection'),
			openable: (bool)($configuration['openable'] ?? false),
			soon: (bool)($configuration['soon'] ?? false),
			actions: self::actionIds($configuration),
		);
	}

	/**
	 * A section list keeps a hole where a section does not belong to the region of the portal, so
	 * only the sections that are really there survive.
	 *
	 * @param array<mixed> $configuration
	 * @return string[]
	 */
	private static function sections(array $configuration, string $key): array
	{
		$sections = $configuration[$key] ?? null;
		if (!is_array($sections))
		{
			return [];
		}

		$named = [];
		foreach ($sections as $section)
		{
			if (is_string($section) && $section !== '')
			{
				$named[] = $section;
			}
		}

		return $named;
	}

	/**
	 * @param array<mixed> $configuration
	 * @return string[]
	 */
	private static function actionIds(array $configuration): array
	{
		$actions = $configuration['actions'] ?? null;
		if (!is_array($actions))
		{
			return [];
		}

		$ids = [];
		foreach ($actions as $action)
		{
			$id = is_array($action) ? ($action['id'] ?? null) : null;
			if (is_string($id) && $id !== '')
			{
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * @param array<mixed> $configuration
	 */
	private static function text(array $configuration, string $key): ?string
	{
		$value = $configuration[$key] ?? null;

		return is_string($value) && $value !== '' ? $value : null;
	}
}
