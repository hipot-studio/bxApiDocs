<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;

use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Trait\CastTrait;

class ElapsedTimeMapper
{
	use CastTrait;

	public function __construct(
		private readonly ElapsedTimeSourceMapper $elapsedTimeSourceMapper,
	)
	{

	}

	public function mapToEntity(array $elapsedTime): Entity\Task\ElapsedTime
	{
		$data = [];

		if (isset($elapsedTime['ID']))
		{
			$data['id'] = (int)$elapsedTime['ID'];
		}

		if (isset($elapsedTime['TASK_ID']))
		{
			$data['taskId'] = (int)$elapsedTime['TASK_ID'];
		}

		if (isset($elapsedTime['USER_ID']))
		{
			$data['userId'] = (int)$elapsedTime['USER_ID'];
		}

		if (isset($elapsedTime['SECONDS']))
		{
			$data['seconds'] = (int)$elapsedTime['SECONDS'];
			$data['minutes'] = (int)round($elapsedTime['SECONDS'] / 60);
		}
		elseif (isset($elapsedTime['MINUTES']))
		{
			$data['seconds'] = 60 * (int)$elapsedTime['MINUTES'];
			$data['minutes'] = (int)$elapsedTime['MINUTES'];
		}

		if (isset($elapsedTime['SOURCE']))
		{
			$data['source'] = $this->elapsedTimeSourceMapper->mapToEnum((int)$elapsedTime['SOURCE'])->value;
		}

		if (isset($elapsedTime['COMMENT_TEXT']))
		{
			$data['text'] = Emoji::decode((string)$elapsedTime['COMMENT_TEXT']);
		}

		if (isset($elapsedTime['CREATED_DATE']))
		{
			$data['createdAtTs'] = $this->castDateTime($elapsedTime['CREATED_DATE']);
		}

		if (isset($elapsedTime['DATE_START']))
		{
			$data['startTs'] = $this->castDateTime($elapsedTime['DATE_START']);
		}
		else
		{
			$data['startTs'] = DateTime::createFromUserTime(null)->getTimestamp();
		}

		if (isset($elapsedTime['DATE_STOP']))
		{
			$data['stopTs'] = $this->castDateTime($elapsedTime['DATE_STOP']);
		}
		else
		{
			$data['stopTs'] = DateTime::createFromUserTime(null)->getTimestamp();
		}

		return Entity\Task\ElapsedTime::mapFromArray($data);
	}

	public function mapFromEntity(Entity\Task\ElapsedTime $elapsedTime): array
	{
		$data = [];

		if ($elapsedTime->id !== null)
		{
			$data['ID'] = $elapsedTime->id;
		}

		if ($elapsedTime->taskId !== null)
		{
			$data['TASK_ID'] = $elapsedTime->taskId;
		}

		if ($elapsedTime->userId !== null)
		{
			$data['USER_ID'] = $elapsedTime->userId;
		}

		if ($elapsedTime->seconds !== null)
		{
			$data['SECONDS'] = $elapsedTime->seconds;
			$data['MINUTES'] = (int)round($elapsedTime->seconds / 60);
		}
		elseif ($elapsedTime->minutes !== null)
		{
			$data['SECONDS'] = 60 * $elapsedTime->minutes;
			$data['MINUTES'] = $elapsedTime->minutes;
		}

		if ($elapsedTime->source !== null)
		{
			$data['SOURCE'] = $this->elapsedTimeSourceMapper->mapFromEnum($elapsedTime->source);
		}

		if ($elapsedTime->text !== null)
		{
			$data['COMMENT_TEXT'] = Emoji::encode($elapsedTime->text);
		}

		if ($elapsedTime->createdAtTs !== null)
		{
			$data['CREATED_DATE'] = $elapsedTime->createdAtTs
				? DateTime::createFromTimestamp($elapsedTime->createdAtTs)
				: new DateTime();
		}

		if ($elapsedTime->startTs !== null)
		{
			$data['DATE_START'] = DateTime::createFromTimestamp($elapsedTime->startTs);
		}
		else
		{
			$data['DATE_START'] = DateTime::createFromUserTime($elapsedTime->startTs);
		}

		if ($elapsedTime->stopTs !== null)
		{
			$data['DATE_STOP'] = DateTime::createFromTimestamp($elapsedTime->stopTs);
		}
		else
		{
			$data['DATE_STOP'] = DateTime::createFromUserTime($elapsedTime->stopTs);
		}

		return $data;
	}

	public function mapToCollection(array $elapsedTimes): Entity\Task\ElapsedTimeCollection
	{
		$entities = [];
		foreach ($elapsedTimes as $elapsedTime)
		{
			$entities[] = $this->mapToEntity($elapsedTime);
		}

		return new Entity\Task\ElapsedTimeCollection(...$entities);
	}
}