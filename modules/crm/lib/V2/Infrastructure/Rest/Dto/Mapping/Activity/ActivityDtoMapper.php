<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Activity;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Activity\ActivityDto;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;

/**
 * Answers the single-valued fields of {@see ActivityDto} out of the read model of an activity.
 *
 * Reads no storage: everything it answers is already in the model the read scenario enriched. The
 * collections are assembled by {@see ActivityCollectionMapper} and the three employees by REST itself.
 *
 * A field the `select` left out is never assigned, and an unassigned property does not reach
 * {@see ActivityDto::toArray()} - that is the whole of the projection.
 */
final class ActivityDtoMapper
{
	/**
	 * The fields the contract declares without a null in them. A model carrying none of one - a historical
	 * row whose column is NULL - is answered without the field, since assigning the null is a TypeError.
	 */
	private const NOT_NULLABLE = [
		'id' => true,
		'type' => true,
		'subject' => true,
		'isCompleted' => true,
		'isImportant' => true,
		'direction' => true,
		'isIncomingChannel' => true,
		'createdTime' => true,
		'updatedTime' => true,
	];

	/**
	 * @param string[] $select names of the published fields the client asked for; an empty one means the
	 *        set the contract answers by default.
	 */
	public function map(Activity $activity, array $select = []): ActivityDto
	{
		$dto = new ActivityDto();

		foreach (self::selectedValues($activity, $select) as $field => $value)
		{
			if ($value === null && isset(self::NOT_NULLABLE[$field]))
			{
				continue;
			}

			$dto->{$field} = $value;
		}

		return $dto;
	}

	/**
	 * @param string[] $select
	 * @return array<string, mixed>
	 */
	private static function selectedValues(Activity $activity, array $select): array
	{
		$values = self::valuesOf($activity);

		if ($select === [])
		{
			return $values;
		}

		// A name this mapper does not answer belongs to a collection or an employee, and an unknown one is
		// refused by REST before the model is read.
		return array_intersect_key($values, array_fill_keys($select, true));
	}

	/**
	 * Every field of the contract this mapper answers, in the order the contract lists them. The three
	 * renames happen here: responsible to `assignedById`, deadline to `deadlineTime` and the grade of the
	 * priority to the flag `isImportant`.
	 *
	 * @return array<string, mixed>
	 */
	private static function valuesOf(Activity $activity): array
	{
		return [
			'id' => $activity->getId(),
			'type' => $activity->getType(),
			'providerId' => $activity->getProviderId(),
			'providerTypeId' => $activity->getProviderTypeId(),
			'subject' => $activity->getSubject(),
			'description' => $activity->getDescription(),
			'descriptionType' => $activity->getDescriptionType(),
			'isCompleted' => $activity->isCompleted(),
			'assignedById' => self::employeeIdOf($activity->getResponsibleId()),
			'authorId' => self::employeeIdOf($activity->getAuthorId()),
			'editorId' => self::employeeIdOf($activity->getEditorId()),
			'originatorId' => $activity->getOriginatorId(),
			'originId' => $activity->getOriginId(),
			'isImportant' => $activity->isImportant(),
			'direction' => $activity->getDirection(),
			'isIncomingChannel' => $activity->isIncomingChannel(),
			'createdTime' => $activity->getCreatedTime(),
			'updatedTime' => $activity->getUpdatedTime(),
			'startTime' => $activity->getStartTime(),
			'endTime' => $activity->getEndTime(),
			'deadlineTime' => $activity->getDeadline(),
		];
	}

	/** Legacy stores "nobody" as a zero and the model keeps the column as it is; a client is answered a null. */
	private static function employeeIdOf(?int $userId): ?int
	{
		return $userId !== null && $userId > 0 ? $userId : null;
	}
}
