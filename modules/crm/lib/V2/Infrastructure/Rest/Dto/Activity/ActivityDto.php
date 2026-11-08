<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Activity;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\User\EmployeeDto;
use Bitrix\Crm\V2\Internal\Entity\Activity\Enum\DescriptionType;
use Bitrix\Crm\V2\Internal\Entity\Activity\Enum\Direction;
use Bitrix\Crm\V2\Internal\Entity\Activity\Enum\Type;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\RelationToOne;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Realisation\Dto\FileDto;

/**
 * The external contract of an activity of CRM, as the reading methods of the group answer it.
 *
 * Only the fields every activity means the same way are published, whatever provider wrote it. A field
 * that providers fill and read each in their own way - the data of the provider, the rules of completion,
 * the grade of the priority, the reminders, the result of the communication - is left to the methods
 * specialized by the type of the activity, and a `select` naming one is refused as naming an unknown field.
 *
 * Read-only: no field carries {@see \Bitrix\Rest\V3\Attribute\Editable}, so a client sending one is refused
 * by name instead of having the value dropped.
 *
 * `Filterable` and `Sortable` are closed lists kept narrow: widening them later is compatible, narrowing
 * them is not. Publishing a field promises no search by it.
 *
 * The enums are the ones of the model ({@see \Bitrix\Crm\V2\Internal\Entity\Activity\Enum}) and their backed
 * values are the published ones.
 */
final class ActivityDto extends Dto
{
	#[Filterable]
	#[Sortable]
	public int $id;

	/**
	 * What kind of thing the activity is. It classifies the activity on its own: the provider pair below
	 * says who handles it, which is a different question and no replacement for this one.
	 */
	#[Filterable]
	public Type $type;

	/** The provider the activity belongs to, read together with {@see self::$providerTypeId}. */
	#[Filterable]
	public ?string $providerId;

	#[Filterable]
	public ?string $providerTypeId;

	public string $subject;

	public ?string $description;

	public ?DescriptionType $descriptionType;

	#[Filterable]
	public bool $isCompleted;

	/**
	 * The employee responsible for the activity.
	 *
	 * Each of the three users is answered twice: the identifier always, and the employee beside it as the
	 * standard relation of REST V3, loaded only where the `select` names it. The author and the editor keep
	 * the terms of an activity rather than the created-by and updated-by of a CRM item.
	 */
	#[Filterable]
	public ?int $assignedById;

	#[RelationToOne('assignedById', 'id')]
	public ?EmployeeDto $assignedBy;

	public ?int $authorId;

	#[RelationToOne('authorId', 'id')]
	public ?EmployeeDto $author;

	public ?int $editorId;

	#[RelationToOne('editorId', 'id')]
	public ?EmployeeDto $editor;

	/** External system an imported activity came from, read together with {@see self::$originId}. */
	public ?string $originatorId;

	public ?string $originId;

	/** Whether the activity carries the highest grade of its priority; the grade itself is not published. */
	public bool $isImportant;

	/** Which way the communication went. `undefined` is a value of the list, not a missing one. */
	#[Filterable]
	public Direction $direction;

	/**
	 * Whether the activity arrived through a channel of incoming traffic. It is kept in a table of its own
	 * rather than on the row, and it answers a different question than {@see self::$direction}.
	 */
	public bool $isIncomingChannel;

	#[Filterable]
	#[Sortable]
	public DateTime $createdTime;

	#[Filterable]
	#[Sortable]
	public DateTime $updatedTime;

	#[Filterable]
	#[Sortable]
	public ?DateTime $startTime;

	#[Sortable]
	public ?DateTime $endTime;

	/** The sentinel the column keeps for an activity without a deadline is answered as null. */
	#[Filterable]
	#[Sortable]
	public ?DateTime $deadlineTime;

	/**
	 * Every CRM object the activity is bound to. The one owner pair the row keeps is not published:
	 * it does not describe the several bindings an activity may have.
	 *
	 * @var DtoCollection<ActivityBindingDto>
	 */
	#[ElementType(ActivityBindingDto::class)]
	public DtoCollection $bindings;

	/** @var DtoCollection<ActivityCommunicationDto> */
	#[ElementType(ActivityCommunicationDto::class)]
	public DtoCollection $communications;

	/**
	 * The documents kept with the activity, answered in the shape REST V3 publishes a file in.
	 *
	 * @var DtoCollection<FileDto>
	 */
	#[ElementType(FileDto::class)]
	public DtoCollection $files;

	/**
	 * A collection of the contract is answered as a JSON array, and an empty one as `[]`.
	 *
	 * {@see Dto::toArray()} hands a {@see DtoCollection} on as the object it is, so the collections are
	 * unwrapped here. The answered values are walked rather than the declared field names: a field the
	 * `select` left out was never assigned.
	 */
	public function toArray(bool $rawData = false): array
	{
		$values = parent::toArray($rawData);

		foreach ($values as $name => $value)
		{
			if ($value instanceof DtoCollection)
			{
				$values[$name] = $value->toArray();
			}
		}

		return $values;
	}
}
