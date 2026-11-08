<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Embedding;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * An open line chat of the portal seen from a CRM form: whether the form greets the visitors of this
 * chat and which form does so instead.
 *
 * A chat greets its visitors with at most one welcome form, so the state of that form is what makes
 * the chat an option for the requested one. A chat taken by a foreign form is named by the
 * identifier of that form alone - its name belongs to the form, not to the chat.
 */
#[Title('CRM form Open Channel placement')]
final class OpenLineDto extends Dto
{
	/** The CRM form the chat is projected against; the required filter of the method. */
	#[Filterable]
	public int $formId;

	#[Sortable]
	public int $id;

	/** The name of the chat as the portal keeps it; nothing is escaped here. */
	public string $name;

	/** The requested form is the welcome form of this chat. */
	public bool $bound;

	/** The chat greets its visitors with a welcome form, this one or a foreign one. */
	public bool $welcomeFormEnabled;

	/** The welcome form of the chat, or null while the chat has none. */
	public ?int $welcomeFormId;

	/** The welcome form is shown after the first message of the visitor rather than before it. */
	public bool $welcomeFormDelayed;

	/**
	 * The link showing the site widget of this chat as the portal has it set up, or null while no
	 * active site widget shows the chat: a preview stands on such a widget and there is nothing to
	 * open without one.
	 *
	 * The requested form is in the preview while it is the welcome form of the chat, which is what
	 * `bound` says: the preview of legacy stands on the widget alone and has no way to show a form
	 * the chat does not greet with.
	 */
	public ?string $previewUrl;
}
