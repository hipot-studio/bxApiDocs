<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Contract\Relation;

enum RelationType: string
{
	/** The file is attached to the entity itself. */
	case Attachment = 'attachment';

	/** The file is attached to a comment of the entity. */
	case Comment = 'comment';

	/** The file is sent as a message into the entity (chat). */
	case Message = 'message';

	/** The file belongs to an activity owned by the entity (CRM). */
	case Activity = 'activity';

	/** The file is a record of the entity (call). */
	case Record = 'record';

	/** The file is a document of the entity (business process over a file). */
	case Document = 'document';
}
