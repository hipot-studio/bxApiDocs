<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\File\Param;

abstract class BaseParam implements Param
{
	protected int $fileId;
	protected string $value;

	abstract protected static function getParamName(): ParamName;

	protected function __construct(int $fileId, string $value)
	{
		$this->fileId = $fileId;
		$this->value = $value;
	}

	public function getFileId(): int
	{
		return $this->fileId;
	}

	public static function getInstance(int $fileId, ParamName $paramName, string $value): Param
	{
		return match ($paramName)
		{
			ParamName::IsTranscribable => (new Transcribable($fileId, $value)),
			ParamName::IsVideoNote => (new VideoNote($fileId, $value)),
			ParamName::IsVoiceNote => (new VoiceNote($fileId, $value)),
		};
	}
}
