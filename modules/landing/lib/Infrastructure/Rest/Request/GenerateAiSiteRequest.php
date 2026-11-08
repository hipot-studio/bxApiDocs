<?php

declare(strict_types=1);

namespace Bitrix\Landing\Infrastructure\Rest\Request;

use Bitrix\Main\Validation\Rule;
use Bitrix\Rest\V3\Interaction\Request\Request;

class GenerateAiSiteRequest extends Request
{
	/**
	 * The ceiling the brief already has on the other way into the same scenario: the initial message
	 * of the AI site chat (AiSiteChatContextService::INITIAL_MESSAGE_MAX_LENGTH).
	 */
	private const DESCRIPTION_MAX_LENGTH = 4000;

	/**
	 * RegExp does not repeat NotEmpty: NotEmpty trims by the PHP character list, which has no form
	 * feed, while CreateAiSiteState::normalizeInputLines() trims by the PCRE one, which has. Without
	 * it a description of whitespace only reaches the domain as an empty brief and answers 500.
	 *
	 * allowZero: "0" is a description of one meaningful character, which is what the contract asks
	 * for, while NotEmpty reads it as empty the way empty() does and would refuse it.
	 *
	 * No rule states a text of its own - the stock phrases of the core speak the language of the
	 * answer, a text written here would reach every client in English.
	 */
	#[Rule\NotEmpty(allowZero: true)]
	#[Rule\RegExp(pattern: '/[^\s\x00]/u')]
	#[Rule\Length(max: self::DESCRIPTION_MAX_LENGTH)]
	public string $description;
}
