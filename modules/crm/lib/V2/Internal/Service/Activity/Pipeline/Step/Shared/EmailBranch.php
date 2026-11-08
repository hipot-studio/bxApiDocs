<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared;

use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityFieldMap;
use CCrmActivityType;
use CCrmOwnerType;

/**
 * An email as a branch of the pipelines in its own right, rather than a special case scattered over the steps
 * that happen to run into it.
 *
 * WHY A NAMED PLACE. Six steps of two pipelines behave differently for an email, and each of them behaves
 * differently for a reason of its own: the description is compressed and its full text kept elsewhere, the full
 * text is bound to a row of its own, the user fields are written as the author instead of the editor, and the
 * editor is not filled in at all. Spread over six files those rules read as six unrelated oddities, and the one
 * that goes stale is invisible. Asked through this class they are one branch, reviewable in one place, and the
 * name of the legacy provider of an email appears nowhere in the steps.
 *
 * THE READERS, and what each of them asks:
 * - `CompressEmailDescription` (step 7 of an add, step 9 of an update) - {@see self::isEmail()} on an add,
 *   {@see self::isCompressedEmail()} plus {@see self::hasMailBodyChanged()} on an update, and
 *   {@see self::compressDescription()} in both;
 * - `AddMailBodyBinding` (step 11 of an add) - {@see self::isCompressedEmail()} and {@see self::bindMailBody()},
 *   the whole step being silenced on a restoration ({@see RestorationMode::SUPPRESSED_EFFECTS});
 * - `RebindMailBody` (step 14 of an update) - {@see self::isCompressedEmail()} and
 *   {@see self::rebindMailBody()};
 * - `SaveUserFields` (step 31 of an add, step 45 of an update) - {@see self::writesUserFieldsAsAuthor()};
 * - `ValidateAndNormalize` (step 3 of both) - {@see self::suppressesEditorAutofill()}.
 *
 * THE PREDICATES ARE NARROW BECAUSE LEGACY IS NOT UNIFORM, and the difference is reproduced rather than tidied
 * up: the compression of an add is gated by the PROVIDER_ID column alone (crm_activity.php:152), the binding of
 * the body by PROVIDER_ID together with PROVIDER_TYPE_ID (:197-200), and the user fields by the TYPE_ID column
 * (:419) - a different column answering a different question. A single "is this an email" predicate would move
 * at least one of the three.
 *
 * THE ORDER RELATIVE TO THE EVENT BEFORE A WRITE DIFFERS BETWEEN THE TWO PIPELINES, and that is an invariant
 * rather than a defect (LBS-1 section 13 items 1-2, checklist items C4 and D5): on an add the compression
 * happens AFTER `OnBeforeCrmActivityAdd`, so a subscriber sees the full text, and on an update BEFORE
 * `OnBeforeCrmActivityUpdate`, so a subscriber sees the preview. Emoji are encoded after the compression in
 * both, which is why what reaches the column is the encoded preview. The order belongs to the declarations of
 * the pipelines; this class holds no rule about it and may not be used to derive one.
 *
 * NO STATE OF THE RUN LIVES HERE. The full text the body binding needs is the answer of
 * {@see self::compressDescription()}, and carrying it from the step that compresses to the step that binds is
 * the business of the pipeline - the same carrier problem as the enriched description of the calendar. A second
 * copy of it here would be a second source of truth for what gets stored as the body of the letter.
 *
 * WHAT THE BRANCH DOES NOT COVER, although the digest of the LBS names it as part of the email path:
 * - the URN of a letter, written by a second update after the insert (crm_activity.php:9081 and the three other
 *   senders): its writers are outside the pipelines, so no step of a pipeline reads a rule about it;
 * - the contextual user of a record of the activity stream, the first active administrator: it lives inside the
 *   legacy method the bridge of the activity stream calls (LBS-1 section 9.1), and the bridge calls it as it is;
 * - the restoration of an email out of the recycle bin: the provider performs it itself, inside its own hook,
 *   and the pipeline neither repeats nor assists it.
 *
 * @internal
 */
final class EmailBranch
{
	/**
	 * Whether the activity belongs to the provider of an email. The gate of the compression on an add, and the
	 * question the validation asks of both states on an update.
	 */
	public static function isEmail(Activity $activity): bool
	{
		return $activity->getProviderId() === Email::getId();
	}

	/**
	 * Whether the activity is an email whose description is a preview of a body stored apart. The gate of every
	 * step that touches that body: the one binding it on an add and the two comparing and rebinding it on an
	 * update.
	 */
	public static function isCompressedEmail(Activity $activity): bool
	{
		return self::isEmail($activity) && $activity->getProviderTypeId() === Email::TYPE_EMAIL_COMPRESSED;
	}

	/**
	 * Whether the editor of the activity is left exactly as the caller gave it, the absent value included
	 * (crm_activity.php:2101-2113, checklist item B7).
	 *
	 * The data of a letter is completed on the hits of different users, so an email may not have the editor of
	 * whoever happened to write next. The answer is yes when either state of the operation is an email - the
	 * state being written or the state stored before it - which is why the previous provider is a parameter of
	 * its own instead of being derived from the activity: on an add there is no previous state at all, and on an
	 * update the caller is not required to name the provider again.
	 */
	public static function suppressesEditorAutofill(?string $providerId, ?string $previousProviderId): bool
	{
		return $providerId === Email::getId() || $previousProviderId === Email::getId();
	}

	/**
	 * Whether the user fields of the activity are written as its author rather than as its editor
	 * (crm_activity.php:417-425).
	 *
	 * THE COLUMN IS THE ONE LEGACY READS, and it is not the provider: the branch of the user fields is gated by
	 * TYPE_ID. The model does not hold that column at all, so the answer comes off the transit, and an absent
	 * column is an answer rather than a failure - the same guarded read as a deadline
	 * ({@see DeadlineSentinelGuard::isGivenIn()}).
	 *
	 * ON AN UPDATE THIS IS A DEVIATION ON PURPOSE (ADR registry row D13-UF-EMAIL). Legacy has no email branch
	 * there and writes the user fields as an editor an email never has, so the files of a letter end up owned by
	 * nobody. V2 asks the same question in both pipelines.
	 */
	public static function writesUserFieldsAsAuthor(StorageFieldTransit $transit): bool
	{
		if (!$transit->has('TYPE_ID'))
		{
			return false;
		}

		return (int)$transit->get('TYPE_ID') === CCrmActivityType::Email;
	}

	/**
	 * Replaces the description of the activity with the preview stored in the column and answers with the full
	 * text the body of the letter is made of.
	 *
	 * THE RULE OF THE PREVIEW STAYS WITH THE PROVIDER of an email, which owns both the limit and the way a
	 * markup is stripped down to it; this class only turns the typed activity into the field array that rule is
	 * written against and back. A copy of the limit here would be the copy that goes stale.
	 *
	 * THE MARKUP IS HANDED OVER ONLY WHEN THE ACTIVITY HAS ONE: the provider falls back to HTML for an activity
	 * without a markup, and a fallback of the model - which is a plain text - would strip a letter as if it had
	 * no tags.
	 */
	public static function compressDescription(Activity $activity): string
	{
		$uncompressedDescription = (string)$activity->getDescription();

		$fields = ['DESCRIPTION' => $uncompressedDescription];
		$descriptionType = $activity->getDescriptionType();
		if ($descriptionType !== null)
		{
			$fields['DESCRIPTION_TYPE'] = ActivityFieldMap::toColumnValue('DESCRIPTION_TYPE', $descriptionType);
		}

		Email::compressActivityDescription($fields);

		$activity->setProviderId((string)$fields['PROVIDER_ID']);
		$activity->setProviderTypeId((string)$fields['PROVIDER_TYPE_ID']);
		$activity->setDescription((string)$fields['DESCRIPTION']);

		return $uncompressedDescription;
	}

	/**
	 * Whether the body stored for the activity differs from the full text the operation carries - the question
	 * of an update, asked before the description is compressed (crm_activity.php:626-636).
	 *
	 * AN ACTIVITY WITH NO STORED BODY COUNTS AS CHANGED, exactly as legacy compares an empty text with the one
	 * it was given: an email whose body was never bound gets one now.
	 *
	 * The other half of the legacy gate - that the caller gave a description at all - is about the input of the
	 * operation rather than about an email, so it stays with the step.
	 */
	public static function hasMailBodyChanged(int $activityId, string $description): bool
	{
		return self::readStoredMailBody($activityId) !== $description;
	}

	/**
	 * Stores the full text of the letter and binds it to the activity (step 11 of an add).
	 *
	 * A restoration performs no binding at all: the row being restored still owns the body it had. The gate is
	 * the mode of the operation and it belongs to the step, which is why it is not repeated here.
	 */
	public static function bindMailBody(int $activityId, string $description): void
	{
		Email::addMailBodyBinding($activityId, CCrmOwnerType::Activity, $description);
	}

	/**
	 * Replaces the body bound to the activity with the full text the operation carries (step 14 of an update).
	 *
	 * THE PREVIOUS BODY IS DELETED ONLY WHEN THIS ACTIVITY WAS ITS ONLY OWNER: a body shared with another
	 * activity - a letter of the same thread whose row points at the same text - survives the rebinding.
	 *
	 * LEGACY DECIDES THAT ONE STEP EARLIER, while comparing the bodies, and the answer is the same: nothing
	 * between the two steps touches either the bodies or their bindings, the write in between being the write of
	 * the activity itself. Deciding it here is what keeps the identifier of the stored body out of the run.
	 */
	public static function rebindMailBody(int $activityId, string $description): void
	{
		Email::deleteBindingByMailId($activityId, self::deletesStoredMailBody($activityId));

		self::bindMailBody($activityId, $description);
	}

	/**
	 * The full text stored as the body of the letter, or an empty text when the activity has none - the shape
	 * legacy compares in.
	 */
	private static function readStoredMailBody(int $activityId): string
	{
		return (string)(Email::getBodyByMailId($activityId)?->getBody() ?? '');
	}

	private static function deletesStoredMailBody(int $activityId): bool
	{
		$bodyId = (int)(Email::getBodyByMailId($activityId)?->getId() ?? 0);

		return Email::doesBodyHaveExactlyOneBinding($bodyId);
	}
}
