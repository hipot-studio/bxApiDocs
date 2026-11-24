<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Footer;

/**
 * Gaps a block may keep between its content and its footer. Four steps were picked on a live page
 * 06.10.2026 over every kind of footer the builder draws - chip of both sizes, button, one row and
 * two.
 *
 * The step is named rather than measured: pixels belong to the clients, and a number in the payload
 * would freeze today's design system into messages that outlive it.
 *
 * Sm is what a block with no step of its own is drawn with. It is narrower than the 14 points the
 * web kept until now - that was the choice made on the demo, and it reaches messages sent before the
 * field existed as well: they name no step, so they take the default.
 *
 * Widening the set is always possible; narrowing a released one is not, and neither is giving a
 * released step a different gap - the step is already standing in messages that were sent.
 */
enum Spacing: string
{
	case None = 'none';
	case Sm = 'sm';
	case Md = 'md';
	case Lg = 'lg';
}
