<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\ProcessData\Enum;

use Bitrix\Bizproc\Workflow\Template\SourceType;
use Bitrix\Main\ArgumentException;

/**
 * The purpose of a data element is not stored: it is the block the element lives in. A template of the new
 * mechanics is told apart by its own marker ({@see \Bitrix\Bizproc\Public\ProcessData\Service\ProcessDataMechanicsResolver}),
 * so no element carries a label of its own.
 */
enum DataElementPurpose: string
{
	case Input = 'input';
	case Internal = 'internal';
	case Constant = 'constant';
	case Shared = 'shared';

	public static function ofSourceType(string $sourceType): self
	{
		return match ($sourceType)
		{
			SourceType::Parameter => self::Input,
			SourceType::Variable => self::Internal,
			SourceType::Constant => self::Constant,
			SourceType::GlobalVariable, SourceType::GlobalConstant => self::Shared,
			default => throw new ArgumentException('Unknown source type ' . $sourceType, 'sourceType'),
		};
	}
}
