<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Pull;

use Bitrix\Im\V2\Chat\Type;
use Bitrix\Im\V2\Pull\EventType;

class ReadAllByType extends ReadAll
{
	private Type $type;

	public function __construct(int $userId, Type $type)
	{
		$this->type = $type;
		parent::__construct($userId);
	}

	protected function getType(): EventType
	{
		return EventType::ReadAllByType;
	}

	protected function getBasePullParamsInternal(): array
	{
		return ['type' => $this->type->getExtendedType()];
	}
}
