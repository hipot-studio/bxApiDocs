<?php
declare(strict_types=1);

namespace Bitrix\Landing\AI\SiteBuilder\Prompt;

final class PromptCodeCatalog
{
	public const CREATE_AI_SITE_INPUT_ENHANCE = 'landing_ai_site_input_enhance';
	public const CREATE_AI_SITE_STRUCTURE = 'landing_ai_site_structure';
	public const CREATE_AI_SITE_BLOCK_HTML = 'landing_ai_site_block_html';
	public const CREATE_AI_SITE_BLOCK_HTML_IMPROVE = 'landing_ai_site_block_html_improve';
	public const CHANGE_AI_SITE_SELECT_BLOCKS = 'landing_ai_change_site_select_blocks';
	public const CHANGE_AI_SITE_BLOCK_HTML = 'landing_ai_change_site_block_html';
	public const CHANGE_AI_SITE_ADD_BLOCK_HTML = 'landing_ai_change_site_add_block_html';
	public const CHANGE_AI_SITE_BLOCK_HTML_IMPROVE = 'landing_ai_change_site_block_html_improve';
	public const CREATE_AI_SITE_MODERATION = 'landing_ai_site_moderation';

	public static function all(): array
	{
		return [
			self::CREATE_AI_SITE_INPUT_ENHANCE,
			self::CREATE_AI_SITE_STRUCTURE,
			self::CREATE_AI_SITE_BLOCK_HTML,
			self::CREATE_AI_SITE_BLOCK_HTML_IMPROVE,
			self::CHANGE_AI_SITE_SELECT_BLOCKS,
			self::CHANGE_AI_SITE_BLOCK_HTML,
			self::CHANGE_AI_SITE_ADD_BLOCK_HTML,
			self::CHANGE_AI_SITE_BLOCK_HTML_IMPROVE,
			self::CREATE_AI_SITE_MODERATION,
		];
	}

	public static function fileMap(): array
	{
		return [
			self::CREATE_AI_SITE_INPUT_ENHANCE => 'ai-input-enhance.txt',
			self::CREATE_AI_SITE_STRUCTURE => 'ai-landing-structure.txt',
			self::CREATE_AI_SITE_BLOCK_HTML => 'ai-block-html.txt',
			self::CREATE_AI_SITE_BLOCK_HTML_IMPROVE => 'ai-block-html-improve.txt',
			self::CHANGE_AI_SITE_SELECT_BLOCKS => 'ai-change-site-select-blocks.txt',
			self::CHANGE_AI_SITE_BLOCK_HTML => 'ai-change-site-block-html.txt',
			self::CHANGE_AI_SITE_ADD_BLOCK_HTML => 'ai-change-site-add-block-html.txt',
			self::CHANGE_AI_SITE_BLOCK_HTML_IMPROVE => 'ai-change-site-block-html-improve.txt',
			self::CREATE_AI_SITE_MODERATION => 'ai-moderate-topic.txt',
		];
	}
}
