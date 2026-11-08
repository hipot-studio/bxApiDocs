<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\Pull\Enum;

/**
 * EVENT-01. What has happened to the discussions of a template, as the signal of a live update tells it.
 *
 * A note that is gone is told apart from a conversation that has changed: NoteRemoved means the label of
 * the note leaves the scheme, while MessageRemoved leaves it where it is. Without that difference the
 * deletion of one message of many would take the label of the note away from every other editor.
 */
enum TemplateNoteAction: string
{
	case NoteCreated = 'note_created';

	/** The note is no longer there: it has been completed, or its last message has been deleted. */
	case NoteRemoved = 'note_removed';

	case MessageCreated = 'message_created';

	case MessageUpdated = 'message_updated';

	/** The note stays, its conversation has changed. */
	case MessageRemoved = 'message_removed';
}
