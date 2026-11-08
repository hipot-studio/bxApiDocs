<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

/**
 * The shape a block identifier the agent authors may have (BRIEF § 4.3): latin letters, digits and underscores,
 * and never digits alone.
 *
 * The identifier is not a label. It becomes the system name of the activity in the saved template, which is what
 * a connection addresses, what a `{=id:Output}` substitution is resolved by and what the runtime looks a node up
 * with. Anything else costs the block its addressability, and the contour had two ways of paying that price: an
 * identifier of digits alone was silently renamed on the way in - the agent then addressed a node that no longer
 * carried the name it had sent - and an identifier carrying a separator was written into the template verbatim,
 * where the substitution parser stops at the separator and reads a different name than the one meant.
 *
 * One account of the shape, because two consumers ask the same question from opposite sides: the check refuses
 * what does not fit it ({@see AgentBlockValidator::validateId()}), and the forward converter composes a new name
 * for what the check was never asked about
 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAssistantDraftConverterService::makeActivityId()}).
 * A grammar living in one of the two would leave the other free to disagree with it, which is the state this
 * class replaces.
 *
 * The two consumers divide the entries between them rather than both acting on one: the grammar is a check the
 * external contour adds ({@see ValidationProfile::enforcesBlockIdGrammar()}), so there an identifier outside it
 * is refused and nothing is renamed, while on the entry that is not held to it the converter is the only place
 * a name can be made addressable at all. Renaming what the check has already accepted is what the division
 * exists against: the expressions addressing a name are rewritten nowhere, so a rename past the check would
 * cost the graph its round trip silently.
 *
 * The grammar governs a NEW identifier alone. One already carried by the published version at the very same
 * point is accepted as it stands, whatever its shape: the server itself wrote it there, and refusing it would
 * cost a published template its round trip (ALG-02).
 *
 * "New" is what the converter answers too, and on both entries: a name the source graph already carries is
 * written as it arrived, and only a name the sender composed itself is composed anew. The two entries differ in
 * what the source is - the check reads the published version and the graph handed out by `template.get`, the
 * converter the published version and the draft the assistant was given - and not in the question.
 */
final class BlockIdGrammar
{
	/**
	 * Latin letters, digits and underscores. Not a rule of this contour's own: it is the object position of the
	 * substitution the runtime parses ({@see \CBPActivity::ValuePattern}), so a name outside it cannot be read
	 * back out of an expression at all.
	 */
	private const ALLOWED = '/^[A-Za-z0-9_]+$/D';

	/**
	 * Digits alone are excluded separately from the character set rather than by a leading-character rule: a
	 * numeric name is the one shape PHP itself loses, since an array of activities keyed by name casts it to an
	 * integer, and it is also what the internal assistant is told to author, so it stays refused on the entry
	 * that is held to the grammar and renamed on the entry that is not.
	 */
	private const DIGITS_ONLY = '/^[0-9]+$/D';

	public static function isAccepted(mixed $id): bool
	{
		return is_string($id)
			&& preg_match(self::ALLOWED, $id) === 1
			&& preg_match(self::DIGITS_ONLY, $id) !== 1
		;
	}
}
