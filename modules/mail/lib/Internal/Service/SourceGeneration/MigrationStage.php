<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

/**
 * The observable stages of one physical source migration of a mailbox.
 *
 * The three first cases repeat the states of the shared G1 initializer
 * ({@see BackfillService}) by value, so a state of the initializer converts into a
 * stage without a mapping table. Everything from PreparingG2 on belongs to the
 * migration operation itself and is stored with the prepared generation, so a
 * failure keeps the stage and the next run of the same operation continues from it.
 *
 * TailAppend stands between the import and the switch, and the order is the whole
 * reason it works ({@see TailAppendService}): it reaches the old source for the
 * attachments while that source is still the active one, and it leaves the appended
 * letters to the delta pass of ReadyToSwitch. Without the source system's authorization,
 * the completed tail stops at AwaitingSwitchAuthorization; the authorization command
 * moves it to ReadyToSwitch, whose repeatable passes walk every folder of the new source
 * before the route of the delivery moves.
 *
 * Two stages go backwards. ResettingImport: a source that changed the epoch of a folder is
 * taking back its promise that the numbers of it mean the same letters, so everything the
 * import of the generation created is dropped and the import starts over
 * ({@see MigrationService::resetImportedData()}). It is a stage of its own because the
 * drop is bounded like every other pass and therefore may end unfinished: while it stands
 * here, the operation goes nowhere near the switch.
 *
 * And ReadyToSwitch back to TailAppend: the hand over found folders the user created on the
 * old server after the snapshot was accepted, brought them into the new server and goes back
 * to the tail for their letters ({@see LateFolderReconciler}). The stored authorization of the
 * switch stays, and the returns are bounded by {@see LateFolderReconciler::MAX_FOLDER_RETURNS}.
 */
enum MigrationStage: string
{
	case Legacy = 'LEGACY';
	case InitializingG1 = 'INITIALIZING_G1';
	case G1Ready = 'G1_READY';
	case PreparingG2 = 'PREPARING_G2';
	case ShadowMatching = 'SHADOW_MATCHING';
	case ResettingImport = 'RESETTING_IMPORT';
	case Matching = 'MATCHING';
	case TailAppend = 'TAIL_APPEND';
	case AwaitingSwitchAuthorization = 'AWAITING_SWITCH_AUTHORIZATION';
	case ReadyToSwitch = 'READY_TO_SWITCH';
	case Switched = 'SWITCHED';

	/**
	 * The prepared generation is already serving the mailbox, so the operation is over
	 * and neither a user nor an automatic run may return to the previous generation.
	 */
	public function isFinal(): bool
	{
		return $this === self::Switched;
	}

	/**
	 * The operation stands still until an external condition is met. A repeated run of
	 * the same operation is harmless here but carries nothing over until then, so the
	 * caller of {@see MigrationService::run()} comes back later instead of right away.
	 */
	public function awaitsExternalInput(): bool
	{
		return $this === self::AwaitingSwitchAuthorization;
	}

	/**
	 * There is nowhere left to go and a repeated run changes nothing: the mailbox either
	 * serves the new source already or is not an IMAP one and gets no generation at all.
	 * This is what a loop over {@see MigrationService::run()} stops at.
	 *
	 * Not the same question as {@see isFinal()}, which answers the narrower one - whether
	 * the switch has happened - and carries the refusal of a second migration.
	 */
	public function hasNowhereToGo(): bool
	{
		return $this->isFinal() || $this === self::Legacy;
	}
}
