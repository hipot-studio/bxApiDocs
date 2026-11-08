<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Public\Service\Storage\StorageOperationKind;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSettingCollection;

/**
 * Single home for the storage-node taxonomy the reverse validator needs. Node types are the canonical
 * (lower-cased) activity codes the forward converter emits. IMMUTABLE_TYPES are passthrough-only, checked
 * against the baseline directly (ALG-01); CONSUMER_TYPES are editable under the guard (ALG-02), whose per-node
 * operation kind is derived here so a result data-view is refused only when editing.
 *
 * The create node is told apart from the rest of the immutable class on its own: outside the baseline it is the
 * type the storage admission policy can still let through (ALG-10). setuptemplateactivity is immutable here as
 * before - what changed is its fate outside the baseline, where a policy of its own now judges it
 * ({@see SetupTemplateNodePolicy}), not the taxonomy it belongs to. Its predicate is deliberately kept out of this
 * class: the wizard configures the template as a whole and touches no storage, so answering for it here would
 * invent a commonality the two node families do not have. It lives in
 * {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\SetupTemplateNodeMatcher}.
 */
final class StorageNodeClassifier
{
	private const TYPE_CREATE = 'createstoragenode';

	private const IMMUTABLE_TYPES = [self::TYPE_CREATE, 'setuptemplateactivity'];

	private const TYPE_READ = 'readdatastorageactivity';
	private const TYPE_WRITE = 'writedatastorageactivity';
	private const TYPE_DELETE = 'deletedatastorageactivity';

	private const STORAGE_CODE_SETTING = 'StorageCode';
	private const STORAGE_ID_SETTING = 'StorageId';
	private const STORAGE_COLUMNS_SETTING = 'SelectedFields';
	private const REWRITE_MODE_SETTING = 'RewriteMode';

	// write RewriteMode value that means "create a new item"; anything else is an update of an existing item.
	private const WRITE_NEW_ITEM_MODE = 'newitem';

	public static function isImmutable(string $type): bool
	{
		return in_array(mb_strtolower($type), self::IMMUTABLE_TYPES, true);
	}

	public static function isStorageCreator(string $type): bool
	{
		return mb_strtolower($type) === self::TYPE_CREATE;
	}

	public static function isConsumer(string $type): bool
	{
		return in_array(mb_strtolower($type), [self::TYPE_READ, self::TYPE_WRITE, self::TYPE_DELETE], true);
	}

	public static function resolveOperationKind(string $type, AgentSettingCollection $settings): ?StorageOperationKind
	{
		return match (mb_strtolower($type))
		{
			self::TYPE_READ => StorageOperationKind::Read,
			self::TYPE_DELETE => StorageOperationKind::Delete,
			self::TYPE_WRITE => self::isWriteUpdate($settings) ? StorageOperationKind::Update : StorageOperationKind::Write,
			default => null,
		};
	}

	public static function extractStorageCode(AgentSettingCollection $settings): ?string
	{
		$value = self::findSettingValue($settings, self::STORAGE_CODE_SETTING);

		return is_string($value) ? $value : null;
	}

	public static function extractStorageId(AgentSettingCollection $settings): mixed
	{
		return self::findSettingValue($settings, self::STORAGE_ID_SETTING);
	}

	/**
	 * The columns a create node declares, as written. Read here rather than by the caller so every setting name of
	 * a storage node keeps being named in one place ({@see StorageNodeFingerprint::columnContractOf()} turns the
	 * value into the contract the code stands for).
	 */
	public static function extractStorageColumns(AgentSettingCollection $settings): mixed
	{
		return self::findSettingValue($settings, self::STORAGE_COLUMNS_SETTING);
	}

	private static function isWriteUpdate(AgentSettingCollection $settings): bool
	{
		$rewriteMode = self::findSettingValue($settings, self::REWRITE_MODE_SETTING);
		if (!is_string($rewriteMode) || $rewriteMode === '')
		{
			// Absent/empty RewriteMode defaults to newItem (a create of a new item, not an update).
			return false;
		}

		return mb_strtolower($rewriteMode) !== self::WRITE_NEW_ITEM_MODE;
	}

	/**
	 * Returns the LAST match, the value the draft converter would persist (it lays settings out into a
	 * name-keyed map). A duplicate name is refused upstream by the settings validator; keeping the same
	 * semantics here means the guard and the persisted template can never judge different values.
	 */
	private static function findSettingValue(AgentSettingCollection $settings, string $name): string|array|null
	{
		$value = null;
		foreach ($settings as $setting)
		{
			if (strcasecmp($setting->name, $name) === 0)
			{
				$value = $setting->value;
			}
		}

		return $value;
	}
}
