<?php

/**
 * Класс-контейнер событий модуля <b>blog</b>.
 */
class _CEventsBlog
{
    /**
     * <p>Событие вызывается в методе <a href="http://dev.1c-bitrix.ru/api_help/blogs/classes/cblog/add.php">CBlog::Add</a> до вставки блога, и может быть использовано для отмены вставки или переопределения некоторых полей.</p>.
     *
     * @param array &$arParams <a href="http://dev.1c-bitrix.ru/api_help/blogs/fields.php#blog">Массив полей</a> блога.
     *
     * @return bool <a href="http://dev.1c-bitrix.ru/api_help/blogs/classes/cblog/add.php">CBlog::Add</a><nobr>$APPLICATION-&gt;<a
     *              href="http://dev.1c-bitrix.ru/api_help/main/reference/cmain/throwexception.php">ThrowException()</a></nobr><i>false</i><br>
     *
     * <h4>Example</h4>
     * <pre bgcolor="#323232" style="padding:5px;">
     * &lt;?
     * // файл /bitrix/php_interface/init.php
     * // регистрируем обработчик
     * AddEventHandler("blog",
     *                 "<b>OnBeforeBlogAdd</b>",
     *                 Array("MyClass", "OnBeforeBlogAddHandler"));
     *
     * @static
     *
     * @see http://dev.1c-bitrix.ru/api_help/blogs/events/onbeforeblogadd.php
     *
     * @author Bitrix
     */
    public static function OnBeforeBlogAdd(&$arParams) {}

    /**
     * <p>Событие вызывается в момент добавления блога.</p>.
     *
     * @param int   $intID     идентификатор добавленного блога
     * @param array &$arParams <a href="http://dev.1c-bitrix.ru/api_help/blogs/fields.php#blog">Массив полей</a> блога.
     *
     * @return bool
     *
     * <h4>Example</h4>
     * <pre bgcolor="#323232" style="padding:5px;">
     * &lt;?
     * // файл /bitrix/php_interface/init.php
     * // регистрируем обработчик
     * AddEventHandler("blog",
     *                 "<b>OnBlogAdd</b>",
     *                 Array("MyClass", "OnBlogAddHandler"));
     *
     * @static
     *
     * @see http://dev.1c-bitrix.ru/api_help/blogs/events/onblogadd.php
     *
     * @author Bitrix
     */
    public static function OnBlogAdd($intID, &$arParams) {}

    /**
     * <p>Событие вызывается в методе <a href="http://dev.1c-bitrix.ru/api_help/blogs/classes/cblog/update.php">CBlog::Update</a> до изменения блога и может быть использовано для отмены изменения или переопределения некоторых полей.</p>.
     *
     * @param int   $intID     идентификатор изменяемого блога
     * @param array &$arParams <a href="http://dev.1c-bitrix.ru/api_help/blogs/fields.php#blog">Массив полей</a> блога.
     *
     * @return bool <a href="http://dev.1c-bitrix.ru/api_help/blogs/classes/cblog/update.php">CBlog::Update</a><nobr>$APPLICATION-&gt;<a
     *              href="http://dev.1c-bitrix.ru/api_help/main/reference/cmain/throwexception.php">ThrowException()</a></nobr><i>false</i><br>
     *
     * <h4>Example</h4>
     * <pre bgcolor="#323232" style="padding:5px;">
     * &lt;?
     * // файл /bitrix/php_interface/init.php
     * // регистрируем обработчик
     * AddEventHandler("blog",
     *                 "<b>OnBeforeBlogUpdate</b>",
     *                 Array("MyClass", "OnBeforeBlogUpdateHandler"));
     *
     * @static
     *
     * @see http://dev.1c-bitrix.ru/api_help/blogs/events/onbeforeblogupdate.php
     *
     * @author Bitrix
     */
    public static function OnBeforeBlogUpdate($intID, &$arParams) {}

    /**
     * <p>Событие вызывается в момент изменения блога.</p>.
     *
     * @param int   $intID     идентификатор изменяемого блога
     * @param array &$arParams <a href="http://dev.1c-bitrix.ru/api_help/blogs/fields.php#blog">Массив полей</a> блога.
     *
     * @return bool
     *
     * <h4>Example</h4>
     * <pre bgcolor="#323232" style="padding:5px;">
     * &lt;?
     * // файл /bitrix/php_interface/init.php
     * // регистрируем обработчик
     * AddEventHandler("blog",
     *                 "<b>OnBlogUpdate</b>",
     *                 Array("MyClass", "OnBlogUpdateHandler"));
     *
     * @static
     *
     * @see http://dev.1c-bitrix.ru/api_help/blogs/events/onblogupdate.php
     *
     * @author Bitrix
     */
    public static function OnBlogUpdate($intID, &$arParams) {}

    /**
     * <p>Событие вызывается в методе <a href="http://dev.1c-bitrix.ru/api_help/blogs/classes/cblog/delete.php">CBlog::Delete</a> до удаления блога и может быть использовано для отмены удаления.</p>.
     *
     * @param int $intID идентификатор удаляемого блога
     *
     * @return bool <a href="http://dev.1c-bitrix.ru/api_help/blogs/classes/cblog/delete.php">CBlog::Delete</a><nobr>$APPLICATION-&gt;<a
     *              href="http://dev.1c-bitrix.ru/api_help/main/reference/cmain/throwexception.php">ThrowException()</a></nobr><i>false</i><br>
     *
     * <h4>Example</h4>
     * <pre bgcolor="#323232" style="padding:5px;">
     * &lt;?
     * // файл /bitrix/php_interface/init.php
     * // регистрируем обработчик
     * AddEventHandler("blog",
     *                 "<b>OnBeforeBlogDelete</b>",
     *                 Array("MyClass", "OnBeforeBlogDeleteHandler"));
     *
     * @static
     *
     * @see http://dev.1c-bitrix.ru/api_help/blogs/events/onbeforeblogdelete.php
     *
     * @author Bitrix
     */
    public static function OnBeforeBlogDelete($intID) {}

    /**
     * <p>Событие вызывается в момент удаления блога.</p>.
     *
     * @param int $intID идентификатор удаляемого блога
     *
     * @return bool
     *
     * <h4>Example</h4>
     * <pre bgcolor="#323232" style="padding:5px;">
     * &lt;?
     * // файл /bitrix/php_interface/init.php
     * // регистрируем обработчик
     * AddEventHandler("blog",
     *                 "<b>OnBlogDelete</b>",
     *                 Array("MyClass", "OnBlogDeleteHandler"));
     *
     * @static
     *
     * @see http://dev.1c-bitrix.ru/api_help/blogs/events/onblogdelete.php
     *
     * @author Bitrix
     */
    public static function OnBlogDelete($intID) {}

    /**
     * перед добавлением комментария.
     * <i>Вызывается в методе:</i><br>
     * <a href="http://dev.1c-bitrix.ru/api_help/blogs/classes/cblogcomment/add.php">CBlogComment::Add</a><br><br>.
     *
     * @see http://dev.1c-bitrix.ru/api_help/blogs/events/index.php
     *
     * @author Bitrix
     */
    public static function OnBeforeCommentAdd() {}

    /**
     * перед удалением комментария.
     * <i>Вызывается в методе:</i><br>
     * <a href="http://dev.1c-bitrix.ru/api_help/blogs/classes/cblogcomment/delete.php">CBlogComment::Delete</a><br><br>.
     *
     * @see http://dev.1c-bitrix.ru/api_help/blogs/events/index.php
     *
     * @author Bitrix
     */
    public static function OnBeforeCommentDelete() {}

    /**
     * перед изменением комментария.
     * <i>Вызывается в методе:</i><br>
     * <a href="http://dev.1c-bitrix.ru/api_help/blogs/classes/cblogcomment/update.php">CBlogComment::Update</a><br><br>.
     *
     * @see http://dev.1c-bitrix.ru/api_help/blogs/events/index.php
     *
     * @author Bitrix
     */
    public static function OnBeforeCommentUpdate() {}

    /**
     * перед добавлением сообщения.
     * <i>Вызывается в методе:</i><br>
     * <a href="http://dev.1c-bitrix.ru/api_help/blogs/classes/cblogpost/add.php">CBlogPost::Add</a><br><br>.
     *
     * @see http://dev.1c-bitrix.ru/api_help/blogs/events/index.php
     *
     * @author Bitrix
     */
    public static function OnBeforePostAdd() {}

    /**
     * перед удалением сообщения.
     * <i>Вызывается в методе:</i><br>
     * <a href="http://dev.1c-bitrix.ru/api_help/blogs/classes/cblogpost/delete.php">CBlogPost::Delete</a><br><br>.
     *
     * @see http://dev.1c-bitrix.ru/api_help/blogs/events/index.php
     *
     * @author Bitrix
     */
    public static function OnBeforePostDelete() {}

    /**
     * перед изменением сообщения.
     * <i>Вызывается в методе:</i><br>
     * <a href="http://dev.1c-bitrix.ru/api_help/blogs/classes/cblogpost/update.php">CBlogPost::Update</a><br><br>.
     *
     * @see http://dev.1c-bitrix.ru/api_help/blogs/events/index.php
     *
     * @author Bitrix
     */
    public static function OnBeforePostUpdate() {}

    /**
     * при добавлении комментария.
     * <i>Вызывается в методе:</i><br>
     * <a href="http://dev.1c-bitrix.ru/api_help/blogs/classes/cblogcomment/add.php">CBlogComment::Add</a><br><br>.
     *
     * @see http://dev.1c-bitrix.ru/api_help/blogs/events/index.php
     *
     * @author Bitrix
     */
    public static function OnCommentAdd() {}

    /**
     * при удалении комментария.
     * <i>Вызывается в методе:</i><br>
     * <a href="http://dev.1c-bitrix.ru/api_help/blogs/classes/cblogcomment/delete.php">CBlogComment::Delete</a><br><br>.
     *
     * @see http://dev.1c-bitrix.ru/api_help/blogs/events/index.php
     *
     * @author Bitrix
     */
    public static function OnCommentDelete() {}

    /**
     * при изменении комментария.
     * <i>Вызывается в методе:</i><br>
     * <a href="http://dev.1c-bitrix.ru/api_help/blogs/classes/cblogcomment/update.php">CBlogComment::Update</a><br><br>.
     *
     * @see http://dev.1c-bitrix.ru/api_help/blogs/events/index.php
     *
     * @author Bitrix
     */
    public static function OnCommentUpdate() {}

    /**
     * при добавлении сообщения.
     * <i>Вызывается в методе:</i><br>
     * <a href="http://dev.1c-bitrix.ru/api_help/blogs/classes/cblogpost/add.php">CBlogPost::Add</a><br><br>.
     *
     * @see http://dev.1c-bitrix.ru/api_help/blogs/events/index.php
     *
     * @author Bitrix
     */
    public static function OnPostAdd() {}

    /**
     * при удалении сообщения.
     * <i>Вызывается в методе:</i><br>
     * <a href="http://dev.1c-bitrix.ru/api_help/blogs/classes/cblogpost/delete.php">CBlogPost::Delete</a><br><br>.
     *
     * @see http://dev.1c-bitrix.ru/api_help/blogs/events/index.php
     *
     * @author Bitrix
     */
    public static function OnPostDelete() {}

    /**
     * при изменении сообщения.
     * <i>Вызывается в методе:</i><br>
     * <a href="http://dev.1c-bitrix.ru/api_help/blogs/classes/cblogpost/update.php">CBlogPost::Update</a><br><br>.
     *
     * @see http://dev.1c-bitrix.ru/api_help/blogs/events/index.php
     *
     * @author Bitrix
     */
    public static function OnPostUpdate() {}

    /**
     * при конвертации видео.
     * <i>Вызывается в методе:</i><br>
     * blogTextParser::blogConvertVideo<br><br>.
     *
     * @see http://dev.1c-bitrix.ru/api_help/blogs/events/index.php
     *
     * @author Bitrix
     */
    public static function videoConvert() {}

    /**
     * при конвертировании тега типа <pre class="code">[IMG ID=12345]</pre> в строку типа <i>&amp;ltimg .../&gt;</i>.
     *
     * <i>Вызывается в методе:</i><br>
     * <a href="http://dev.1c-bitrix.ru/api_help/blogs/classes/blogtextparser/index.php">blogTextParser::blogTextParser</a><br><br>
     *
     * @see http://dev.1c-bitrix.ru/api_help/blogs/events/index.php
     *
     * @author Bitrix
     */
    public static function BlogImageSize() {}

    /**
     * после изменения\добавления сообщения в блог, но перед обновлением пользовательских свойств.
     * <i>Вызывается в методе:</i><br>
     * <a href="http://dev.1c-bitrix.ru/api_help/blogs/classes/cblogpost/update.php">CBlogPost::Update</a><br><br>.
     *
     * @see http://dev.1c-bitrix.ru/api_help/blogs/events/index.php
     *
     * @author Bitrix
     */
    public static function OnBeforePostUserFieldUpdate() {}

    /**
     * после отправки уведомления об упоминании в сообщении\комментарии.
     * <i>Вызывается в методе:</i><br>
     * CBlogPost::NotifyIm<br><br>.
     *
     * @see http://dev.1c-bitrix.ru/api_help/blogs/events/index.php
     *
     * @author Bitrix
     */
    public static function OnBlogPostMentionNotifyIm() {}
}
