<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Command\Rights;

use Bitrix\Disk\Internal\Enum\ObjectRightsChangeMode;
use Bitrix\Disk\RightsManager;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\PositiveNumber;

/**
 * Grants or revokes an object right of Disk on behalf of an operator. The operator is a real user of the
 * portal whose right to change rights is checked: an operation without a subject to check would be a way
 * around the rights it changes.
 *
 * Data of a successful result: ['applied' => string[], 'skipped' => array<string, string>].
 */
class ChangeObjectRightsCommand extends AbstractCommand
{
	public const ERROR_OBJECT_NOT_FOUND = 'DISK_BP_RIGHTS_OBJECT_NOT_FOUND';
	public const ERROR_ACCESS_DENIED = 'DISK_BP_RIGHTS_ACCESS_DENIED';
	public const ERROR_UNKNOWN_TASK = 'DISK_BP_RIGHTS_UNKNOWN_TASK';
	public const ERROR_OBJECT_IN_TRASH = 'DISK_BP_RIGHTS_OBJECT_IN_TRASH';
	public const ERROR_NO_INTERNAL_RIGHTS = 'DISK_BP_RIGHTS_NO_INTERNAL_RIGHTS';
	public const ERROR_COULD_NOT_CHANGE = 'DISK_BP_RIGHTS_COULD_NOT_CHANGE';

	/**
	 * The access levels the popup "Access" offers, and the only ones the command hands out. A task the
	 * portal holds but a person cannot pick by hand - the one of a sharing, for instance - is not something
	 * a template may reach either: the name of the task comes from a value of the document on the run, so
	 * the list has to be kept here and not in the dialog alone.
	 */
	public const ALLOWED_TASK_NAMES = [
		RightsManager::TASK_READ,
		RightsManager::TASK_ADD,
		RightsManager::TASK_EDIT,
		RightsManager::TASK_FULL,
	];

	public const SKIP_REASON_INHERITED = 'inherited';
	public const SKIP_REASON_PROHIBITED = 'prohibited';
	public const SKIP_REASON_OTHER_DOMAIN = 'other_domain';
	public const SKIP_REASON_NOT_APPLIED = 'not_applied';

	/**
	 * @param string[] $accessCodes
	 */
	public function __construct(
		#[PositiveNumber]
		public readonly int $objectId,
		public readonly ObjectRightsChangeMode $mode,
		public readonly string $taskName,
		public readonly array $accessCodes,
		#[PositiveNumber]
		public readonly int $operatorId,
		public readonly array $workflowContext = [],
	)
	{
	}

	protected function execute(): Result
	{
		$handler = ServiceLocator::getInstance()->get(ChangeObjectRightsCommandHandler::class);

		return $handler($this);
	}
}
