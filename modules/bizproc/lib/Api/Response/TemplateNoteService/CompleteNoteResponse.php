<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Response\TemplateNoteService;

use Bitrix\Bizproc\Result;

/**
 * Completing a note removes it with its messages, so a success carries nothing - including the repeat
 * of a completion already done (ALG-04).
 */
final class CompleteNoteResponse extends Result
{
}
