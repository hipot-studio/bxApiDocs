<?php

namespace Bitrix\Call\Integration\AI;

/**
 * The version of the page of a detailed analysis (`bitrix:call.ai`) as a consumer of another module draws its own
 * page with it.
 *
 * A consumer (syncconnector: the page of a follow-up of a call that is not a call of this portal) inherits the
 * component and includes its template with an `$arResult` of its own, and feeds the classes of an outcome from
 * memory, without rows. So the contract is everything such a consumer names:
 *
 * - the files of `install/components/bitrix/call.ai/templates/.default/`: `template.v3.php`, `script.js`,
 *   `style.css`, `style-v2.css` and `error.php`, by these names;
 * - the keys of `$arResult` `template.v3.php` reads, and what each holds (`OVERVIEW`, `INSIGHTS`, `SUMMARY`,
 *   `EVALUATION`, `TRANSCRIBE` in the form of `toRestFormat(MentionService::FORMAT_HTML)`, their `*_VERSION`, the
 *   header - `CALL_ID`, `CALL_UUID`, `CALL_DATE`, `CALL_START_TIME`, `CALL_END_TIME`, `CALL_DURATION`,
 *   `CALL_USERS`, `USER_COUNT`, `CURRENT_USER_ID`, `CAN_DELETE`, `RECORD` - and the share of speech of a speaker);
 * - the methods of `BX.Call.AI.Tabs` a consumer replaces before `init`: `initUserList`, `deleteFollowUp`,
 *   `clickDisclaimer`, `sendOpenFollowUpTabAnalytics`, and the `copilot` methods of `BX.Call.Lib.Analytics` the
 *   script calls; the start of the script on `DOMContentLoaded` and the `data-call-id` of its root;
 * - `\CallAiComponent` and its protected steps `prepareParams`, `checkAccess`, `prepareResult`, `showError`, and
 *   the formatters `formatDate`, `formatTime`, `formatDuration`, `formatLength`, `formatInterval`;
 * - {@see Outcome} built in memory - `setType`, `setCallId`, `setProperty`, `fillFromJson`, `getSenseContent` -
 *   and the five classes of an outcome ({@see Outcome\Transcription}, {@see Outcome\Overview},
 *   {@see Outcome\Summary}, {@see Outcome\Insights}, {@see Outcome\Evaluation}): their constructors from an
 *   outcome, `hasContent`, `toRestFormat`, and the properties each reads from the donor's JSON;
 * - the protected {@see Outcome\AISenseContent::getMentionService()}, which a consumer overrides;
 * - {@see Outcome\Transcription::prepareSpeakersList()}, {@see MentionService::FORMAT_HTML},
 *   {@see MentionService::getInstance()}, `MentionService::preloadMentionedUsers`, {@see \Bitrix\Call\Util::getUsers()}
 *   and {@see CallAISettings}.
 *
 * It is raised by any change a consumer written against the previous version cannot survive: a key of `$arResult`
 * `template.v3.php` reads renamed or dropped, or a new one it reads without a check; the signature of a protected
 * step of `\CallAiComponent` or of `AISenseContent::getMentionService` changed; the constructor of a class of an
 * outcome changed; a method of `BX.Call.AI.Tabs` the consumer replaces renamed or no longer called by `init`; a file
 * of the template renamed. A page of a consumer that draws a raised version would miss a key or a method and break
 * in front of a person, and that is exactly what this number is for.
 *
 * A consumer compares it with the version it was written for BEFORE it loads a class of its own that inherits or
 * calls anything above, and draws a page of its own that says the analysis is not available when the two differ or
 * the constant is not there (a call module older than the constant).
 *
 * Deliberately a class with nothing but the constant - no methods, no parents, no other class of the module named:
 * loading it is safe in any version.
 */
final class FollowUpPageContract
{
	public const VERSION = 1;
}
