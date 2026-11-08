<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template\Fragment\Enum;

/**
 * Why a node of a saved fragment never reached the scheme it was inserted into (DTO-03).
 *
 * The value is the wire form and the whole of the contract: the phrase a user reads belongs to the editor,
 * which owns every phrase of the library, so no label is resolved here.
 *
 * Insertion never extends the rights of a user, and that invariant is held by two checks which do not cover
 * each other: the applicability filter answers what kind of node it is, and the barrier of the settings
 * answers what lies inside it. Both refuse by a case of this list.
 */
enum SkipReason: string
{
	/** The descriptor is gone, the node is excluded, or it is no longer supported. */
	case Unsupported = 'unsupported';

	/** The filter of the descriptor does not admit the document type of the recipient. */
	case DocumentType = 'document_type';

	/** The node is locked by the tariff, and the statement of the task shows it on a line of its own. */
	case Locked = 'locked';

	/** Every group of the node is hidden on the portal by the very service the palette hides them with. */
	case HiddenGroup = 'hidden_group';

	/**
	 * The settings of the node did not pass the barrier: a restricted setting carrying a payload, a storage
	 * reference the recipient never published, or a value the inserting user has no access to.
	 */
	case RestrictedSettings = 'restricted_settings';
}
