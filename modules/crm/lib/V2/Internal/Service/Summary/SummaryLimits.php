<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Summary;

use Bitrix\Crm\Item;
use Bitrix\Crm\Item\Deal;

/**
 * How much of a history a summary is allowed to carry - of the one of a client and of the one of a
 * single deal, which is read of a single source and is given numbers of its own.
 *
 * The values live in one place because they are tuned by measurement, not by taste: a caller reads
 * them, never its own number.
 *
 * @internal
 */
final class SummaryLimits
{
	/**
	 * Deals of the narrow selection the aggregates are counted over.
	 *
	 * The aggregates are counted by {@see \Bitrix\Crm\Summary\ClientSummary} over the deals that were
	 * loaded, so the ceiling is the price of the section: not a single query, and a cost that grows with
	 * the count. Measured over three cold processes of three runs each, a hundred deals cost 119-148 ms
	 * on a quiet stand and 163-317 ms on a loaded one, five hundred five times that either way. A hundred
	 * is the largest count that keeps the aggregates within a fraction of a second on both.
	 */
	public const AGGREGATE_CAP = 100;

	/**
	 * The one record past a ceiling a probing read asks for.
	 *
	 * It is the whole of the signal that the ceiling was reached and never reaches the summary: counting
	 * what came back answers differently, because a history that ends exactly at the ceiling and one the
	 * ceiling cut look the same from there, and the first of them would be called incomplete for nothing.
	 * The same record says the same thing of a kind of communications ({@see CommunicationsReader}),
	 * and whoever reads the activities for that reader asks for the same one more, or the record costs
	 * a read of its own ({@see LastCallSummaryReader::readActivities()}).
	 */
	public const PROBE_RECORD = 1;

	/** Deals the summary tells the details of. */
	public const DEAL_DETAILS = 5;

	/** Characters of every text field of a deal in the details. */
	public const DEAL_TEXT_LENGTH = 1000;

	/** Characters of the comment on a client the profile carries. */
	public const PROFILE_COMMENT_LENGTH = 2000;

	/**
	 * Characters of a text the queries of the summary read of a `text` column.
	 *
	 * A text is cut to the limit of its place in the summary only after the markup is taken out of it,
	 * so the read has to bring back more than the summary keeps. This is how much more: ten times
	 * {@see self::PROFILE_COMMENT_LENGTH}, the longest of the texts these queries answer. A text that
	 * needs more than this to say its first readable characters is cut earlier than it would have been -
	 * sixty thousand characters of markup around a sentence.
	 */
	public const READ_TEXT_LENGTH = 20000;

	/**
	 * Communications of one kind of a single source: a strategy is asked for no more than this, and
	 * the merged kind carries no more than this of every source it was read of.
	 */
	public const COMMUNICATIONS_PER_KIND = 3;

	/**
	 * The same for a summary of a single deal, where every kind is read of one source only.
	 *
	 * On the comments, the to-do items and the letters it is this number and not the share of the kind
	 * that binds the section: nine texts of a thousand characters is what the share holds, and raising
	 * the count is the only lever that tells more about a deal there. The number is a preliminary one
	 * and stands until a measurement confirms or moves it.
	 */
	public const DEAL_COMMUNICATIONS_PER_KIND = 9;

	/** Characters of a call transcript, on top of the 20000 the source normalizes it to. */
	public const CALL_TEXT_LENGTH = 3000;

	/** Characters of a communication of any other kind, on top of the 5000 of the source. */
	public const TEXT_LENGTH = 1000;

	/**
	 * Characters of the summary of the latest call. The summary comes out of a `longtext` column and is
	 * the one thing of the section nobody else bounds, so the section bounds it here.
	 */
	public const LAST_CALL_SUMMARY_LENGTH = 4000;

	/**
	 * Characters the communications of one kind are allowed of the section.
	 *
	 * A kind is given a share instead of racing the others for a common ceiling: the limits of the
	 * sources add up past that ceiling once a client has deals, and whoever was read first used to
	 * spend all of it and leave the rest of the kinds with nothing.
	 */
	public const KIND_LENGTH = 9000;

	/**
	 * The same for a summary of a single deal: the share of the client, unchanged. On the transcripts it
	 * is the share and not the count that binds - three whole transcripts spend it to the end - so it is
	 * worth moving only after a measurement says three of them are too few.
	 */
	public const DEAL_KIND_LENGTH = 9000;

	/**
	 * Kinds of communications the section carries, every one with a share of its own: the strategies
	 * {@see CommunicationsReader} asks, open lines left out.
	 */
	public const COMMUNICATION_KINDS = 4;

	/**
	 * Characters of the communications section: the share of every kind plus the one the summary of the
	 * latest call is allowed. It is counted here and not written down, so a kind added to the section
	 * moves the ceiling with it instead of silently outgrowing it.
	 */
	public const TOTAL_LENGTH = self::COMMUNICATION_KINDS * self::KIND_LENGTH + self::LAST_CALL_SUMMARY_LENGTH;

	/**
	 * The same ceiling for a summary of a deal, counted the same way out of the numbers of a deal. It
	 * happens to equal the client one today, the share of a kind being unchanged, and is a constant of
	 * its own all the same: the share of a deal is a lever a measurement may move, and the volume of a
	 * deal summary is asserted against the ceiling of a deal and not against the one of a client.
	 */
	public const DEAL_COMMUNICATIONS_TOTAL_LENGTH =
		self::COMMUNICATION_KINDS * self::DEAL_KIND_LENGTH + self::LAST_CALL_SUMMARY_LENGTH
	;

	/**
	 * Product rows a summary of a single deal tells about.
	 *
	 * A row costs up to some three hundred characters - a name of up to two hundred and fifty five plus
	 * the numbers beside it - so twenty of them are about six thousand, which is comparable to the share
	 * of a single kind of communications. The rows beyond the twentieth are left out and the section
	 * says so.
	 */
	public const DEAL_PRODUCT_ROWS = 20;

	/**
	 * Smart invoices of the deal the summary tells about.
	 *
	 * An invoice costs some hundred and fifty characters - a number, an amount with a currency, a
	 * stage with its semantics and a date - so ten of them are about fifteen hundred. Ten is a count a
	 * deal reaches only when it is billed in parts, and the invoices past it are the oldest ones,
	 * which the section says it left out.
	 */
	public const DEAL_INVOICES = 10;

	/**
	 * Fields of a detailed deal. Product rows are left out on purpose: they cost a query per deal
	 * and no block of a summary asks for them.
	 */
	public const DEAL_DETAIL_SELECT = [
		Item::FIELD_NAME_ID,
		Item::FIELD_NAME_TITLE,
		Item::FIELD_NAME_CATEGORY_ID,
		Item::FIELD_NAME_STAGE_ID,
		Item::FIELD_NAME_STAGE_SEMANTIC_ID,
		Item::FIELD_NAME_OPPORTUNITY,
		Item::FIELD_NAME_CURRENCY_ID,
		Item::FIELD_NAME_CREATED_TIME,
		Item::FIELD_NAME_CLOSE_DATE,
		Item::FIELD_NAME_CLOSED,
		Item::FIELD_NAME_TYPE_ID,
		Item::FIELD_NAME_SOURCE_ID,
		Item::FIELD_NAME_SOURCE_DESCRIPTION,
		Item::FIELD_NAME_COMMENTS,
		Deal::FIELD_NAME_ADDITIONAL_INFO,
	];

	/**
	 * Fields a summary of a single deal needs beyond the map of the details, read by one row of the
	 * primary key: the flag the gate of the summary tells a template of the deals to come by, who the
	 * deal is on, when it was last touched, whom it is with and what it is counted in.
	 *
	 * They are kept out of {@see self::DEAL_DETAIL_SELECT} on purpose: that list is read by the summary
	 * of a client as well, and widening it would cost every deal of a client columns nobody there asks
	 * about. The price of keeping them apart is the one row this select is read by.
	 *
	 * The client arrives as two columns and not as one, because that is how a deal keeps it: the
	 * company of the deal and its primary contact, told apart by {@see DealClientReader}.
	 *
	 * The currency is here and not among the fields of the products, because a deal is kept in one
	 * currency and a product row of it carries none of its own - {@see DealProductsReader} names it
	 * once for the whole section.
	 */
	public const DEAL_SUMMARY_EXTRA_SELECT = [
		Item::FIELD_NAME_ID,
		Item::FIELD_NAME_IS_RECURRING,
		Item::FIELD_NAME_ASSIGNED,
		Item::FIELD_NAME_LAST_ACTIVITY_TIME,
		Item::FIELD_NAME_COMPANY_ID,
		Item::FIELD_NAME_CONTACT_ID,
		Item::FIELD_NAME_CURRENCY_ID,
	];

	/**
	 * Fields of a detailed deal that live in a `text` column and are read cut by the query itself.
	 *
	 * A query cannot answer the same field twice, so a cut field arrives under a name of its own; the
	 * one who reads the deal takes it from there. The title of a deal is not among them - a column of
	 * two hundred and fifty five characters needs no cutting.
	 */
	public const DEAL_TEXT_ALIASES = [
		Item::FIELD_NAME_SOURCE_DESCRIPTION => 'SUMMARY_SOURCE_DESCRIPTION',
		Item::FIELD_NAME_COMMENTS => 'SUMMARY_COMMENTS',
		Deal::FIELD_NAME_ADDITIONAL_INFO => 'SUMMARY_ADDITIONAL_INFO',
	];

	/** The same for the comment on a client, the one text of a profile. */
	public const PROFILE_COMMENT_ALIAS = 'SUMMARY_COMMENTS';

	private function __construct()
	{
	}
}
