<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Settings;

use Bitrix\Main\Grid\Settings;

/**
 * Grid settings of the "Regular tasks" section.
 *
 * GRID_ID is agreed with the frontend (TPL-F1) - a single value on the backend and the client
 * (unlike the System/Template History precedent, where the values diverged).
 */
class ReplicationGridSettings extends Settings
{
	public const GRID_ID = 'tasks-replication-grid';

	public function __construct(array $params = [])
	{
		parent::__construct(array_merge(['ID' => self::GRID_ID], $params));
	}
}
