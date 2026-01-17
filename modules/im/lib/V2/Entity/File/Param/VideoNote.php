<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\File\Param;

class VideoNote extends BaseParam
{
	public function getValue(): bool
	{
		return $this->value === 'Y';
	}

	protected static function getParamName(): ParamName
	{
		return ParamName::IsVideoNote;
	}

	public function toArray(): array
	{
		return [
			'DISK_FILE_ID' => $this->fileId,
			'PARAM_NAME' => ParamName::IsVideoNote->value,
			'PARAM_VALUE' => $this->value === 'Y' ? 'Y' : 'N',
		];
	}
}

