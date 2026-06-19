<?php

namespace Bitrix\StaffTrack\Internal\Model;

use Bitrix\Main\Type\Contract\Arrayable;

class CheckIn extends EO_CheckIn implements Arrayable
{
	public function toArray(): array
	{
		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'dateCreate' => $this->getDateCreate()?->format('Y-m-d H:i:s'),
			'entityType' => $this->getEntityType(),
			'latitude' => (float)$this->getLatitude(),
			'longitude' => (float)$this->getLongitude(),
			'description' => $this->getDescription(),
			'address' => $this->getAddress(),
			'messageIds' => $this->getMessageIds(),
			'userTimezone' => $this->getUserTimezone(),
			'fileIds' => $this->getFileIds(),
		];
	}
}
