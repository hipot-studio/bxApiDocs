<?php

namespace Bitrix\Sign\Type\Document;

/**
 * Keys of the source specific part of the document creation source, stored in the serialized META field.
 * Case values are persistent data: renaming one requires a data migration.
 */
enum CreationSourceMetaKey: string
{
	case BIZPROC_TEMPLATE_ID = 'bizprocTemplateId';
	case BIZPROC_ACTIVITY_NAME = 'bizprocActivityName';
}
