<?php

namespace Bitrix\Tasks\Flow\Control\Command;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Flow\Attribute\AccessCodes;
use Bitrix\Tasks\Flow\Attribute\Instantiable;
use Bitrix\Tasks\Flow\Control\AbstractCommand;
use Bitrix\Tasks\Flow\Control\Exception\InvalidCommandException;
use Bitrix\Tasks\Flow\Flow;
use Bitrix\Tasks\Internals\Attribute\Department;
use Bitrix\Tasks\Internals\Attribute\ExpectedNumeric;
use Bitrix\Tasks\Internals\Attribute\InArray;
use Bitrix\Tasks\Internals\Attribute\Length;
use Bitrix\Tasks\Internals\Attribute\Min;
use Bitrix\Tasks\Internals\Attribute\Max;
use Bitrix\Tasks\Internals\Attribute\Nullable;
use Bitrix\Tasks\Internals\Attribute\Parse;
use Bitrix\Tasks\Internals\Attribute\Parse\UserFromAccess;
use Bitrix\Tasks\Internals\Attribute\Primary;
use Bitrix\Tasks\Internals\Attribute\Required;
use Bitrix\Tasks\Internals\Attribute\User;

/**
 * @method self setId(int $id)
 * @method self setCreatorId(int $creatorId)
 * @method self setOwnerId(int $ownerId)
 * @method self setGroupId(int $groupId)
 * @method self setTemplateId(int $templateId)
 * @method self setEfficiency(int $efficiency)
 * @method self setActive(bool $active)
 * @method self setDemo(bool $demo)
 * @method self setPlannedCompletionTime(int $plannedCompletionTime)
 * @method self setActivity(DateTime $activity = new DateTime())
 * @method self setName(string $name)
 * @method self setDescription(string $description)
 * @method self setDistributionType(string $distributionType)
 * @method self setManualDistributorId(int $manualDistributorId)
 * @method self setResponsibleQueue(array $responsibleQueue)
 * @method self setTaskCreators(array $taskCreators)
 * @method bool hasId()
 */
final class UpdateCommand extends AbstractCommand
{
	#[Required]
	#[Primary]
	#[Min(1)]
	public int $id;

	#[Nullable]
	#[Min(1)]
	#[User]
	public ?int $creatorId = null;

	#[Nullable]
	#[Min(1)]
	#[User]
	public ?int $ownerId = null;

	#[Nullable]
	#[Min(1)]
	public ?int $groupId = null;

	#[Nullable]
	#[Min(0)]
	public ?int $templateId = null;

	#[Nullable]
	#[Min(0)]
	#[Max(100)]
	public ?int $efficiency = null;

	#[Nullable]
	public ?bool $active = null;
	public ?bool $demo = null;

	#[Nullable]
	#[Min(0)]
	#[Max(2145398400)]
	public ?int $plannedCompletionTime = null;

	#[Nullable]
	#[Instantiable]
	public ?DateTime $activity = null;

	#[Nullable]
	#[Length(1, 255)]
	public ?string $name = null;

	#[Nullable]
	public ?string $description = null;

	#[Nullable]
	#[InArray([Flow::DISTRIBUTION_TYPE_QUEUE, Flow::DISTRIBUTION_TYPE_MANUALLY])]
	public ?string $distributionType = null;

	#[Nullable]
	public ?int $manualDistributorId = null;

	#[Nullable]
	#[ExpectedNumeric]
	#[User]
	public ?array $responsibleQueue = null;

	#[Nullable]
	public ?bool $responsibleCanChangeDeadline = null;

	#[Nullable]
	public ?bool $matchWorkTime = null;

	#[Nullable]
	public ?bool $notifyAtHalfTime = null;

	#[Nullable]
	public ?bool $taskControl = null;

	#[Nullable]
	#[Min(0)]
	#[Max(99999)]
	public ?int $notifyOnQueueOverflow = null;

	#[Nullable]
	#[Min(0)]
	#[Max(99999)]
	public ?int $notifyOnTasksInProgressOverflow = null;

	#[Nullable]
	#[Min(0)]
	#[Max(100)]
	public ?int $notifyWhenEfficiencyDecreases = null;

	#[Nullable]
	#[AccessCodes]
	public ?array $taskCreators = null;

	#[Nullable]
	#[User]
	#[Parse(new UserFromAccess(), 'taskCreators')]
	public ?array $userTaskCreators = null;

	#[Nullable]
	#[Department]
	#[Parse(new Parse\DepartmentFromAccess(), 'taskCreators')]
	public ?array $departmentTaskCreators = null;

	public function hasValidGroupId(): bool
	{
		try
		{
			$this->validateProperty('groupId');
			return true;
		}
		catch (InvalidCommandException)
		{
			return false;
		}
	}
}
