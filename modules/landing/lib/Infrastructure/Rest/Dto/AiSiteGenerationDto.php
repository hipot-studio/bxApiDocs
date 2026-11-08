<?php

declare(strict_types=1);

namespace Bitrix\Landing\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * The answer of landing.aisite.generate and landing.aisite.status: one shape for every state.
 *
 * draftSiteId / draftPageId are fields of the contract, not internals that leaked into it: a failed
 * generation that got as far as creating a draft site names the draft here, because without the
 * identifiers the client could neither open nor delete it - and advised to "try again" instead, it
 * would breed a new draft per retry. Both are 0 in every other state. The internals the handler
 * also builds - slider codes, the creation journal - stay inside the module (see
 * AiSiteRestService::createGenerationDto()).
 *
 * errorDetails is the one text this module does not write: the AI subsystem tells apart a quota
 * that ran out, a rate limit and a technical one, and error alone cannot say which. It is stripped
 * of the markup it carries - the link inside points at a slider of the portal interface, which a
 * REST client cannot open - and it speaks the language of the moment the generation ran, not the
 * language this answer was asked in.
 */
class AiSiteGenerationDto extends Dto
{
	#[Title('Site generation identifier')]
	public int $id;

	#[Title('Generation status: running, completed or failed')]
	public string $status;

	#[Title('Generated site identifier, 0 until the site is created')]
	public int $siteId;

	#[Title('Generated page identifier, 0 until the page is created')]
	public int $pageId;

	#[Title('Draft site identifier of a failed generation that got as far as creating one, 0 otherwise')]
	public int $draftSiteId;

	#[Title('Draft page identifier of a failed generation that got as far as creating one, 0 otherwise')]
	public int $draftPageId;

	#[Title('Human readable generation state, a text for the user - branch on status, not on it')]
	public string $message;

	#[Title('Human readable failure reason, null unless the status is failed')]
	public ?string $error;

	#[Title('Exact wording of the request limit that stopped the generation, null when something else did')]
	public ?string $errorDetails;
}
