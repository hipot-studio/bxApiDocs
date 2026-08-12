<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Async;

enum QueueId: string
{
	case StructureSync = 'socialnetwork_structure_sync';
	case CollabNoteAcl = 'socialnetwork_collab_note_acl';
}
