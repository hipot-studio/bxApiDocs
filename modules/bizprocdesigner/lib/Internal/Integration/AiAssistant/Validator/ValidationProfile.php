<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\RequestSource;

/**
 * How strictly a submitted graph is judged (TPL-02). Chosen once from the request source at the entry and carried
 * by {@see ValidationContext} from there on, so the validators of this catalog ask the profile instead of asking
 * the source: a condition on the source spread across them is what let the two entries drift apart in the first
 * place, and no new one is opened here.
 *
 * The border runs along one rule (BRIEF § 4.4): what follows from the description of an activity - its types, its
 * options, the keys of a map, the names it carries over and the typed check of a value - holds on BOTH entries and
 * is not governed by the profile; what the check ADDS is switched on by it. So the external contour gets the
 * stricter control of an untrusted graph while the internal assistant keeps the contract it has today, and an
 * activity is never described twice.
 *
 * Scope is the check of the graph and nothing else. The composition of the catalog, the frames of a template on
 * the way back, the conversion and the attribution of a change in the journal read the request source directly and
 * legitimately: a frame is a topology one consumer can see, not a relaxed check.
 */
enum ValidationProfile
{
	/** The external REST agent: an untrusted graph, judged by every check the contour adds. */
	case ExternalContour;

	/** Marta, and any context assembled without an explicit source: the internal contract as it stands. */
	case InternalAssistant;

	/**
	 * Fail-closed exactly as every reader of the context is: no context at all is not the external contour, so
	 * the checks of that contour cannot be reached by leaving the context out.
	 */
	public static function of(?ValidationContext $context): self
	{
		return $context?->profile ?? self::InternalAssistant;
	}

	public static function fromSource(RequestSource $source): self
	{
		return $source === RequestSource::Rest ? self::ExternalContour : self::InternalAssistant;
	}

	/**
	 * Whether the overlay nodes of this contour are blocks of the graph at all, or types the catalog does not
	 * have. Two of them are judged by this one trait, because they are the same fact about a profile: the frame
	 * an external agent groups blocks with, and the internal node of a system agent whose system name is empty by
	 * design ({@see NamelessInternalNodeMatcher}). Both exist on the external contour alone, and under the other
	 * profile each falls through to the ordinary type check and is refused there.
	 */
	public function allowsFrameOverlay(): bool
	{
		return $this->addsChecks();
	}

	/** Whether the graph is measured against the bounds of {@see GraphLimitsValidator}. */
	public function enforcesGraphLimits(): bool
	{
		return $this->addsChecks();
	}

	/** Whether a block owes its required settings and the cross-setting rules of its schema (BRIEF § 1.3). */
	public function enforcesSettingRequirements(): bool
	{
		return $this->addsChecks();
	}

	/** Whether a storage has to be created before it is used, and by one creator (BRIEF § 4.1; phase C). */
	public function enforcesStorageOrder(): bool
	{
		return $this->addsChecks();
	}

	/** Whether an expression may only address a block and an output that exist (BRIEF § 4.2; phase C). */
	public function enforcesExpressionReferences(): bool
	{
		return $this->addsChecks();
	}

	/**
	 * Whether a newly authored block identifier is held to the grammar of {@see BlockIdGrammar} (BRIEF § 4.3;
	 * phase C), asked by {@see AgentBlockValidator::validateId()}. An identifier the published version already
	 * carries at the same point is accepted under either profile: that is the baseline exemption of ALG-02 and
	 * not a strictness of its own.
	 */
	public function enforcesBlockIdGrammar(): bool
	{
		return $this->addsChecks();
	}

	/** Whether an accepted graph is answered with warnings at all: the internal assistant receives none. */
	public function emitsWarnings(): bool
	{
		return $this->addsChecks();
	}

	/**
	 * The one question behind every trait above: the external contour switches on everything the check adds, the
	 * internal assistant switches on none of it. They are named one by one all the same - each trait is where a
	 * check asks for its own permission, and a trait is what a later phase plugs into.
	 */
	private function addsChecks(): bool
	{
		return $this === self::ExternalContour;
	}
}
