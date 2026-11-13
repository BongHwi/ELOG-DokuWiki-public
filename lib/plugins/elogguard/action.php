<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;

/**
 * ELOG guard
 *
 * DokuWiki ACL levels are cumulative (upload > create > edit), so a user who may
 * create entries and upload files may also edit every existing entry. A logbook
 * needs "create and upload, but never edit". This plugin enforces that rule for
 * everyone who is not an administrator:
 *
 *  - saving: creating a page is allowed for a logged-in user with AUTH_CREATE;
 *    editing, deleting or reverting an existing page is refused. This covers the
 *    web editor, the ELOG template forms and the remote API, because all of them
 *    save through saveWikiText().
 *  - the editor actions (edit, save, preview, drafts, revert) are turned into "show".
 *  - section edit buttons are not rendered.
 *
 * Command line tools (bin/*.php) are not restricted.
 */
class action_plugin_elogguard extends ActionPlugin
{
    /** Editor actions that only administrators may use. */
    protected const EDITOR_ACTIONS = ['edit', 'save', 'preview', 'draft', 'draftdel', 'recover', 'revert'];

    /** @inheritDoc */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'BEFORE', $this, 'guardSave', null, -1000);
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'guardAction', null, -1000);
        $controller->register_hook('HTML_SECEDIT_BUTTON', 'BEFORE', $this, 'hideSectionEdit');
    }

    /**
     * Refuse every page save that is not an administrator's, except the creation
     * of a new page by a logged-in user who holds create rights on it.
     */
    public function guardSave(Event $event)
    {
        global $INPUT;

        if (PHP_SAPI === 'cli') return;
        if (auth_isadmin()) return;

        $id = (string)($event->data['id'] ?? '');
        $isCreate = (($event->data['changeType'] ?? null) === DOKU_CHANGE_TYPE_CREATE);
        $loggedIn = !blank($INPUT->server->str('REMOTE_USER'));

        if ($isCreate && $loggedIn && $id !== '' && auth_quickaclcheck($id) >= AUTH_CREATE) return;

        $event->preventDefault();
        $event->stopPropagation();
        msg(
            $isCreate
                ? 'You need to log in with posting rights to create an entry.'
                : 'Existing entries can only be changed by an administrator. Use Reply to add a correction.',
            -1
        );
    }

    /**
     * Keep non-administrators out of the page editor.
     */
    public function guardAction(Event $event)
    {
        $act = $event->data;
        if (is_array($act)) $act = key($act);
        $act = strtolower((string)$act);

        if (!in_array($act, self::EDITOR_ACTIONS, true)) return;
        if (auth_isadmin()) return;

        $event->data = 'show';
    }

    /**
     * Do not render section edit buttons for non-administrators.
     */
    public function hideSectionEdit(Event $event)
    {
        if (auth_isadmin()) return;

        $event->preventDefault();
        $event->result = '';
    }
}
