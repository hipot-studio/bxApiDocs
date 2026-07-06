<?php

declare(strict_types=1);

namespace Bitrix\Main\UpdateSystem\Migration;

/**
 * Selects how migration helpers (Table, Agent, Event, Module, Option, Stepper)
 * interact with the database during an updater run.
 */
enum DatabaseUpdateMode
{
	/** No DB operations, no SQL generation — every helper short-circuits. */
	case Disabled;

	/**
	 * Only DDL operations (Table::create/alter/drop) execute against the live
	 * database. Module-state operations (Event/Agent/Option/Stepper/Module,
	 * Table::insertRow/query) are skipped. Intended for box installations
	 * that want schema in sync but defer module-state changes.
	 */
	case DdlOnly;

	/** Forward-direction full migration: DDL + module-state changes hit the live DB. */
	case Full;

	/**
	 * Initial activation of a module. Behaves like {@see self::Full} for the
	 * forward-direction helpers, with two install-specific tweaks:
	 *   - {@see Agent::add}/{@see Event::register}: bypass the
	 *     `$context->isModuleInstalled()` guard — during install the module
	 *     entry does not exist yet, but agents and event handlers still need
	 *     to be wired up.
	 *   - {@see Table::alter}, {@see Table::drop}, {@see Agent::remove},
	 *     {@see Event::unregister}: short-circuit. Fresh install only builds
	 *     new schema and state; it never modifies or removes pre-existing
	 *     entries.
	 */
	case ModuleInstall;

	/**
	 * Generates DDL queries without executing them against the live DB. Closures
	 * that look up the live schema are short-circuited (use hint names as-is,
	 * skip column/index existence checks). Used by the preliminary DDL generator.
	 */
	case PreliminaryGeneration;

	/**
	 * Reverse-direction migration used during module uninstall: {@see Table::create}
	 * is interpreted as drop, and {@see Event::register} flips to unregister.
	 * {@see Table::drop} and {@see Event::unregister} fire against the live DB;
	 * other module-state helpers (Agent::add/remove, Module::install,
	 * Option::set/delete, Stepper::add, Table::insertRow/query) are not
	 * auto-rerouted — the migration writer is responsible for choosing
	 * reverse-direction methods explicitly.
	 */
	case ModuleUninstall;

	/**
	 * Used by the site checker to enumerate the full expected schema. Behaves like
	 * {@see self::PreliminaryGeneration} (queries are generated, not executed; live
	 * schema lookups short-circuit), but {@see self::isPreliminary()} returns false
	 * so blocks marked with `disablePreliminaryExecution()` are still rendered —
	 * the site checker validates the full declared schema, including parts that
	 * are skipped during the box predeploy pipeline.
	 */
	case SiteChecker;

	/**
	 * Whether DDL helpers (Table::create/alter/drop) should produce queries.
	 * False only for {@see self::Disabled}.
	 */
	public function executesDdl(): bool
	{
		return $this !== self::Disabled;
	}

	/**
	 * Whether the mode permits forward-direction module-state writes through the
	 * Migration helpers (Event::register, Agent::add/remove, Module::install,
	 * Option::set/delete, Stepper::add, Table::insertRow/query). True for
	 * {@see self::Full} and {@see self::ModuleInstall}.
	 *
	 * Reverse-direction helpers ({@see \Bitrix\Main\UpdateSystem\Migration\Event::unregister},
	 * {@see \Bitrix\Main\UpdateSystem\Migration\Table::drop}) explicitly OR this
	 * with `$mode === ModuleUninstall` — they fire in Full and ModuleUninstall.
	 */
	public function canUpdateDatabase(): bool
	{
		return $this === self::Full || $this === self::ModuleInstall;
	}

	/**
	 * Whether queries actually hit the live database. False for {@see self::Disabled},
	 * {@see self::PreliminaryGeneration} and {@see self::SiteChecker} (the last two
	 * collect queries without executing them).
	 *
	 * Affects:
	 *  - {@see \Bitrix\Main\UpdateSystem\Migration\Table::executeQuery} — skips `$DB->Query` when false.
	 *  - {@see \Bitrix\Main\UpdateSystem\Migration\Table::alter} — skips the `tableExists` gate when false.
	 *  - Closures wired into {@see \Bitrix\Main\DB\Ddl\Builder\AlterTableBuilder} /
	 *    {@see \Bitrix\Main\DB\Ddl\Builder\CreateTableBuilder} — short-circuit live-schema lookups when false.
	 */
	public function usesRealDatabase(): bool
	{
		return match ($this)
		{
			self::Disabled, self::PreliminaryGeneration, self::SiteChecker => false,
			default => true,
		};
	}

	/**
	 * Whether the mode is part of the "preliminary deployment" pipeline — schema is
	 * applied ahead of the full deploy, or the queries are being collected
	 * for that pipeline. True for {@see self::DdlOnly} and {@see self::PreliminaryGeneration}.
	 *
	 * Used by builders that opt out of preliminary execution via
	 * `disablePreliminaryExecution()` — when this returns true and the builder
	 * is opted out, the queries are skipped.
	 */
	public function isPreliminary(): bool
	{
		return $this === self::DdlOnly || $this === self::PreliminaryGeneration;
	}

	/**
	 * Whether this is the initial-install pipeline ({@see self::ModuleInstall}).
	 *
	 * Affects:
	 *  - {@see Agent::add}/{@see Event::register}: bypass `$context->isModuleInstalled()`.
	 *  - {@see Table::alter}, {@see Table::drop}, {@see Agent::remove},
	 *    {@see Event::unregister}: short-circuit (fresh install only adds, never
	 *    modifies or removes pre-existing entries).
	 */
	public function isModuleInstall(): bool
	{
		return $this === self::ModuleInstall;
	}
}
