<?php

namespace Bitrix\Bizproc\Api\Enum\Template;

enum TemplateChangeElementType: string
{
	case Block = 'block';
	case BlockTitle = 'blockTitle';
	case BlockComment = 'blockComment';
	case BlockActivation = 'blockActivation';
	case BlockSetting = 'blockSetting';
	case BlockSettings = 'blockSettings';
	case Connection = 'connection';
	case TemplateName = 'templateName';
	case TemplateDescription = 'templateDescription';
	case Parameter = 'parameter';
	case Variable = 'variable';
	case Constant = 'constant';

	/**
	 * Lines of one block: they share its id, and the block leaving the template takes all of them with it.
	 */
	public function isBlockLine(): bool
	{
		return match ($this)
		{
			self::Block,
			self::BlockTitle,
			self::BlockComment,
			self::BlockActivation,
			self::BlockSetting,
			self::BlockSettings,
			self::Connection => true,
			default => false,
		};
	}
}
