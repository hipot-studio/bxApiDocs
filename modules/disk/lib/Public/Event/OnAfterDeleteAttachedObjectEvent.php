<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Event;

use Bitrix\Main\Type\DateTime;

class OnAfterDeleteAttachedObjectEvent extends AbstractEvent
{
	public const EVENT_NAME = 'onAfterDeleteAttachedObject';

	/**
	 * @param array{
	 *     ID: string,
	 *     OBJECT_ID: string,
	 *     VERSION_ID: ?string,
	 *     ALLOW_EDIT: string,
	 *     ALLOW_AUTO_COMMENT: string,
	 *     MODULE_ID: string,
	 *     ENTITY_TYPE: string,
	 *     ENTITY_ID: string,
	 *     CREATE_TIME: DateTime,
	 *     CREATED_BY: string
	 * } $attachedObject
	 */
	public function __construct(array $attachedObject)
	{
		parent::__construct(
			event: static::EVENT_NAME,
			parameters: [
				'attachedObject' => $attachedObject,
			],
		);
	}
}
