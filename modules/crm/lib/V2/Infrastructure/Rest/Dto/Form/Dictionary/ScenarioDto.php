<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Dictionary;

use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * A preset an editor offers before a CRM form is created: the sections it opens, the group it is
 * offered in and the way it is entered.
 *
 * Nothing here is filterable or sortable: the dictionary is answered whole, in the order the portal
 * keeps it.
 */
#[Title('CRM form scenario')]
final class ScenarioDto extends Dto
{
	/** The code of the scenario; the identifier an editor addresses it by. */
	public string $id;

	/** The name of the scenario as the portal keeps it; nothing is escaped here. */
	public string $title;

	public ?string $description;

	/** The group of {@see ScenarioCategoryDto} the scenario is offered in. */
	public string $categoryId;

	/** The icon of the scenario as a path on the portal, or null while the scenario has none. */
	public ?string $iconUrl;

	/**
	 * Sections of the editor the scenario opens.
	 *
	 * @var string[]
	 */
	public array $sections;

	/**
	 * Sections the same scenario opens for an experienced user.
	 *
	 * @var string[]
	 */
	public array $expertSections;

	/** The section the editor opens at, or null while the scenario names none. */
	public ?string $defaultSection;

	/** The scenario opens the editor of a form. */
	public bool $openable;

	/** The scenario is announced but not available yet. */
	public bool $soon;

	/**
	 * What the scenario does instead of opening the editor.
	 *
	 * @var string[]
	 */
	public array $actions;
}
