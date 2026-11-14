<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Access;

use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\Internal\Enum\ExternalLinkScenario;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkLiveness;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkOpenPolicy;
use Bitrix\Main\Application;
use Bitrix\Main\DB\MysqlCommonConnection;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;

/**
 * The single rule of which external links make an object public for synchronisation: a manual link
 * outside of the service scenario of mail attachments.
 *
 * Automatic links and service links are created by the system rather than by a person sharing the
 * object, so they do not count. The portal policy of opening links is deliberately not read: the
 * client applies it itself by the capabilities ({@see ExternalLinkOpenPolicy}). Liveness is a separate
 * rule ({@see ExternalLinkLiveness}).
 */
final class PublicLinkRule
{
	public static function isPublicKind(int $type, ?string $scenario): bool
	{
		return
			$type === ExternalLink::TYPE_MANUAL
			&& $scenario !== ExternalLinkScenario::MailAttachments->value
		;
	}

	/**
	 * The same rule as {@see isPublicKind()} as a condition of a query, so that the database leaves out
	 * the links of no public kind before the rows are checked.
	 *
	 * A link without a scenario is public, and NULL never passes a comparison, so it is kept explicitly.
	 *
	 * @param string $prefix prepended to the columns as is: '' over the link table itself,
	 *        'REFERENCE_NAME.' over a reference to it
	 */
	public static function publicKindFilter(string $prefix): ConditionTree
	{
		return Query::filter()
			->where($prefix . 'TYPE', ExternalLink::TYPE_MANUAL)
			->where(
				Query::filter()
					->logic('or')
					->whereNull($prefix . 'SCENARIO')
					->where(self::scenarioIsNotMailFilter($prefix . 'SCENARIO'))
				,
			)
		;
	}

	/**
	 * MySQL compares strings by the collation of the column, which may ignore case, accents and trailing
	 * spaces, while {@see isPublicKind()} compares bytes. The bytes are compared in SQL as well, so that the
	 * condition never leaves out a link the rule accepts; PostgreSQL compares them so by default.
	 */
	private static function scenarioIsNotMailFilter(string $column): ConditionTree
	{
		$connection = Application::getConnection();
		$mailScenario = ExternalLinkScenario::MailAttachments->value;

		if ($connection instanceof MysqlCommonConnection)
		{
			return Query::filter()->whereExpr(
				'CAST(%s AS BINARY) <> ' . $connection->getSqlHelper()->convertToDbString($mailScenario),
				[$column],
			);
		}

		return Query::filter()->where($column, '<>', $mailScenario);
	}
}
