<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid\Version;

/**
 * DTO-11: state code rendered in the version cell of an AI-agents grid row.
 *
 * The case values are an owner contract: the client extension picks the cell
 * label by them. Renaming a value breaks the cell.
 *
 * The first four cases are the states of a launched copy and follow from the
 * hasNewVersion/isCustomized pair. The last two cover the rows whose state was
 * never compared, so they are never derived from that pair.
 *
 * The only row left without a state code is the system template itself: it
 * carries a revision date instead - see VersionCellDto.
 */
enum VersionCellState: string
{
	/** Copy matches its installed version and no newer reference exists. */
	case UpToDate = 'upToDate';

	/** A newer reference version is available, copy logic is untouched. */
	case UpgradeAvailable = 'upgradeAvailable';

	/** Copy logic diverged from the installed version, no newer reference. */
	case Customized = 'customized';

	/** Copy logic diverged and a newer reference version is available. */
	case CustomizedUpgradeAvailable = 'customizedUpgradeAvailable';

	/**
	 * Copy linked to a reference, but its state was not compared: no installed version, the
	 * reference is gone from the portal, or a revision that does not compute.
	 */
	case Undetermined = 'undetermined';

	/** Agent built in the designer: it has no reference, so there is nothing to compare against. */
	case ManualBuild = 'manualBuild';
}
