<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Embedding;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * A site widget of the portal seen from a CRM form: whether the form is placed into this widget and
 * which forms the widget already holds.
 *
 * The widget has no entity of its own in this family, so the form the projection is built against
 * travels with every element and is the only property a request may filter by. The link between a
 * form and a widget is computed rather than stored side by side, and is therefore not filterable.
 */
#[Title('CRM form website widget placement')]
final class SiteButtonDto extends Dto
{
	/** The CRM form the widget is projected against; the required filter of the method. */
	#[Filterable]
	public int $formId;

	#[Sortable]
	public int $id;

	/** The name of the widget as the portal keeps it; nothing is escaped here. */
	public string $name;

	/** A widget the portal created for itself. Such a widget stays in the list. */
	public bool $system;

	/** The requested form is placed into this widget. */
	public bool $bound;

	/**
	 * Identifiers of every CRM form the widget holds, over all of its slots at once.
	 *
	 * @var int[]
	 */
	public array $formsId;

	/**
	 * The link showing this widget as the portal has it set up. The requested form is in the preview
	 * while the widget holds it in an active slot, which is what `bound` says: the preview of legacy
	 * stands on the widget alone and has no way to show a form the widget does not hold.
	 */
	public string $previewUrl;
}
