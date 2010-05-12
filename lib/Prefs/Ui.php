<?php
/**
 * Horde-specific prefs handling.
 *
 * Copyright 2010 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @author  Michael Slusarz <slusarz@horde.org>
 * @package Horde
 */
class Horde_Prefs_Ui
{
    /**
     * Populate dynamically-generated preference values.
     *
     * @param Horde_Core_Prefs_Ui $ui  The UI object.
     */
    public function prefsEnum($ui)
    {
        global $prefs, $registry;

        switch ($ui->group) {
        case 'display':
            if (!$prefs->isLocked('initial_application')) {
                $out = array();
                $apps = $registry->listApps(array('active'));
                foreach ($apps as $a) {
                    $perms = $GLOBALS['injector']->getInstance('Horde_Perms');
                    if (file_exists($registry->get('fileroot', $a)) &&
                        (($perms->exists($a) && ($perms->hasPermission($a, Horde_Auth::getAuth(), Horde_Perms::READ) || Horde_Auth::isAdmin())) ||
                         !$perms->exists($a))) {
                        $out[$a] = $registry->get('name', $a);
                    }
                }
                asort($out);
                $ui->override['initial_application'] = $out;
            }

            if (!$prefs->isLocked('theme')) {
                $out = array();
                $theme_base = $registry->get('themesfs', 'horde');
                $dh = @opendir($theme_base);
                if (!$dh) {
                    $GLOBALS['notification']->push(_("Theme directory can't be opened"), 'horde.error');
                } else {
                    while (($dir = readdir($dh)) !== false) {
                        if ($dir == '.' || $dir == '..') {
                            continue;
                        }

                        $theme_name = null;
                        @include $theme_base . '/' . $dir . '/info.php';
                        if (!empty($theme_name)) {
                            $out[$dir] = $theme_name;
                        }
                    }
                }

                asort($out);
                $ui->override['theme'] = $out;
            }
            break;

        case 'language':
            if (!$prefs->isLocked('language')) {
                $ui->override['language'] = Horde_Nls::$config['languages'];
                array_unshift($ui->override['language'], _("Default"));
            }

            if (!$prefs->isLocked('timezone')) {
                $ui->override['timezone'] = Horde_Nls::getTimezones();
                array_unshift($ui->override['timezone'], _("Default"));
            }
            break;
        }
    }

    /**
     * Code to run on init when viewing prefs for this application.
     *
     * @param Horde_Core_Prefs_Ui $ui  The UI object.
     */
    public function prefsInit($ui)
    {
        global $conf;

        switch ($ui->group) {
        case 'remote':
            Horde::addScriptFile('rpcprefs.js', 'horde');
            $ui->nobuttons = true;
            break;
        }

        /* Hide appropriate prefGroups. */
        try {
            Horde_Auth::singleton($conf['auth']['driver'])->hasCapability('update');
        } catch (Horde_Exception $e) {
            $ui->suppressGroups[] = 'forgotpass';
        }

        if (empty($conf['facebook']['enabled']) ||
            empty($conf['facebook']['key']) ||
            empty($conf['facebook']['secret'])) {
            $ui->suppressGroups[] = 'facebook';
        }

        if (empty($conf['twitter']['enabled']) ||
            empty($conf['twitter']['key']) ||
            empty($conf['twitter']['secret'])) {
            $ui->suppressGroups[] = 'twitter';
        }

        if (empty($conf['imsp']['enabled'])) {
            $ui->suppressGroups[] = 'imspauth';
        }

        if (empty($conf['activesync']['enabled'])) {
            $ui->suppressGroups[] = 'activesync';
        }
    }

    /**
     * Generate code used to display a special preference.
     *
     * @param Horde_Core_Prefs_Ui $ui  The UI object.
     * @param string $item             The preference name.
     *
     * @return string  The HTML code to display on the options page.
     */
    public function prefsSpecial($ui, $item)
    {
        switch ($item) {
        case 'categorymanagement':
            return $this->_categoryManagement($ui);

        case 'remotemanagement':
            return $this->_remoteManagement($ui);

        case 'syncmlmanagement':
            return $this->_syncmlManagement($ui);

        case 'activesyncmanagement':
            return $this->_activesyncManagement($ui);

        case 'facebookmanagement':
            return $this->_facebookManagement($ui);

        return '';
        }
    }

    /**
     * Special preferences handling on update.
     *
     * @param Horde_Core_Prefs_Ui $ui  The UI object.
     * @param string $item             The preference name.
     *
     * @return boolean  True if preference was updated.
     */
    public function prefsSpecialUpdate($ui, $item)
    {
        switch ($item) {
        case 'categorymanagement':
            return $this->_updateCategoryManagement($ui);

        case 'remotemanagement':
            $this->_updateRemoteManagement($ui);
            break;

        case 'syncmlmanagement':
            $this->_updateSyncmlManagement($ui);
            break;

        case 'activesyncmanagement':
            $this->_updateActiveSyncManagement($ui);
            break;

        case 'facebookmanagement':
            $this->_updateFacebookManagement($ui);
            break;
        }

        return false;
    }

    /**
     * Called when preferences are changed.
     *
     * @param Horde_Core_Prefs_Ui $ui  The UI object.
     */
    public function prefsCallback($ui)
    {
        global $prefs, $registry;

        $need_reload = false;
        $old_sidebar = $prefs->getValue('show_sidebar');

        if ($prefs->isDirty('language')) {
            if ($prefs->isDirty('language')) {
                Horde_Nls::setLanguageEnvironment($prefs->getValue('language'));
                foreach ($registry->listAPIs() as $api) {
                    if ($registry->hasMethod($api . '/changeLanguage')) {
                        $registry->call($api . '/changeLanguage');
                    }
                }
            }

            $need_reload = true;
        } else {
            /* Do reload on change of any of these variables. */
            $need_reload = (
                $prefs->isDirty('sidebar_width') ||
                $prefs->isDirty('theme') ||
                $prefs->isDirty('menu_view') ||
                $prefs->isDirty('menu_refresh_time')
            );
        }

        if ($prefs->isDirty('show_sidebar')) {
            $need_reload = true;
            $old_sidebar = !$old_sidebar;
        }

        if ($need_reload) {
            $url = Horde::applicationUrl('index.php')->setRaw(true)->add(array(
                'force_sidebar' => true,
                'url' => strval(Horde::selfUrl(true, false, true))
            ));

            /* If the old view was with sidebar, need to reload the entire
             * frame. */
            if ($old_sidebar) {
                Horde::addInlineScript(
                    'window.parent.frames.location = ' . Horde_Serialize::serialize($url, Horde_Serialize::JSON, Horde_Nls::getCharset()) . ';'
                );
            } else {
                Horde::redirect($url);
            }
        }
    }

    /**
     * Create code for category management.
     *
     * @param Horde_Core_Prefs_Ui $ui  The UI object.
     *
     * @return string  HTML UI code.
     */
    protected function _categoryManagement($ui)
    {
        Horde::addScriptFile('categoryprefs.js', 'horde');
        Horde::addScriptFile('colorpicker.js', 'horde');
        Horde::addInlineScript(array(
            'HordeAlarmPrefs.category_text = ' . Horde_Serialize::serialize(_("Enter a name for the new category:"), Horde_Serialize::JSON)
        ));

        $cManager = new Horde_Prefs_CategoryManager();
        $categories = $cManager->get();
        $colors = $cManager->colors();
        $fgcolors = $cManager->fgColors();

        $t = $GLOBALS['injector']->createInstance('Horde_Template');
        $t->setOption('gettext', true);

        if (!$GLOBALS['prefs']->isLocked('category_colors')) {
            $t->set('picker_img',  Horde::img('colorpicker.png', _("Color Picker")));
        }
        $t->set('delete_img',  Horde::img('delete.png'));

        // Default Color
        $color = isset($colors['_default_'])
            ? htmlspecialchars($colors['_default_'])
            : '#FFFFFF';
        $fgcolor = isset($fgcolors['_default_'])
            ? htmlspecialchars($fgcolors['_default_'])
            : '#000000';
        $color_b = 'color_' . hash('md5', '_default_');

        $t->set('default_color', $color);
        $t->set('default_fgcolor', $fgcolor);
        $t->set('default_label', Horde::label($color_b, _("Default Color")));
        $t->set('default_id', $color_b);

        // Unfiled Color
        $color = isset($colors['_unfiled_'])
            ? htmlspecialchars($colors['_unfiled_'])
            : '#FFFFFF';
        $fgcolor = isset($fgcolors['_unfiled_'])
            ? htmlspecialchars($fgcolors['_unfiled_'])
            : '#000000';
        $color_b = 'color_' . hash('md5', '_unfiled_');

        $t->set('unfiled_color', $color);
        $t->set('unfiled_fgcolor', $fgcolor);
        $t->set('unfiled_label', Horde::label($color_b, _("Unfiled")));
        $t->set('unfiled_id', $color_b);

        $entries = array();
        foreach ($categories as $name) {
            $color = isset($colors[$name])
                ? htmlspecialchars($colors[$name])
                : '#FFFFFF';
            $fgcolor = isset($fgcolors[$name])
                ? htmlspecialchars($fgcolors[$name])
                : '#000000';
            $color_b = 'color_' . hash('md5', $name);

            $entries[] = array(
                'color' => $color,
                'fgcolor' => $fgcolor,
                'label' => Horde::label($color_b, ($name == '_default_' ? _("Default Color") : htmlspecialchars($name))),
                'id' => $color_b,
                'name' => htmlspecialchars($name)
            );
        }
        $t->set('categories', $entries);

        return $t->fetch(HORDE_TEMPLATES . '/prefs/category.html');
    }

    /**
     * Create code for remote server management.
     *
     * @param Horde_Core_Prefs_Ui $ui  The UI object.
     *
     * @return string  HTML UI code.
     */
    protected function _remoteManagement($ui)
    {
        $rpc_servers = @unserialize($GLOBALS['prefs']->getValue('remote_summaries'));
        if (!is_array($rpc_servers)) {
            $rpc_servers = array();
        }

        $js = $serverlist = array();
        foreach ($rpc_servers as $key => $val) {
            $js[] = array($val['url'], $val['user']);
            $serverlist[] = array(
                'i' => $key,
                'l' => htmlspecialchars($val['url'])
            );
        }

        Horde::addInlineScript(array(
            'HordeRpcPrefs.servers = ' . Horde_Serialize::serialize($js, Horde_Serialize::JSON)

        ));

        $t = $GLOBALS['injector']->createInstance('Horde_Template');
        $t->setOption('gettext', true);

        $t->set('serverlabel', Horde::label('server', _("Your remote servers:")));
        $t->set('serverlist', $serverlist);
        $t->set('urllabel', Horde::label('url', _("Remote URL (http://www.example.com/horde):")));
        $t->set('userlabel', Horde::label('user', _("Username:")));
        $t->set('passwdlabel', Horde::label('passwd', _("Password:")));

        return $t->fetch(HORDE_TEMPLATES . '/prefs/rpc.html');
    }

    /**
     * Create code for SyncML management.
     *
     * @param Horde_Core_Prefs_Ui $ui  The UI object.
     *
     * @return string  HTML UI code.
     */
    protected function _syncmlManagement($ui)
    {
        Horde::addScriptFile('syncmlprefs.js', 'horde');
        $devices = SyncML_Backend::factory('Horde')->getUserAnchors(Horde_Auth::getAuth());

        $t = $GLOBALS['injector']->createInstance('Horde_Template');
        $t->setOption('gettext', true);

        $partners = array();
        $selfurl = $ui->selfUrl()->add('deleteanchor', 1);

        foreach ($devices as $device => $anchors) {
            foreach ($anchors as $anchor) {
                $partners[] = array(
                    'anchor' => htmlspecialchars($anchor['syncml_clientanchor']),
                    'db' => htmlspecialchars($anchor['syncml_db']),
                    'deviceid' => $device,
                    'rawdb' => $anchor ['syncml_db'],
                    'device' => htmlspecialchars($device),
                    'time' => strftime($GLOBALS['prefs']->getValue('date_format') . ' %H:%M', $anchor['syncml_serveranchor'])
                );
            }
        }
        $t->set('devices', $partners);

        return $t->fetch(HORDE_TEMPLATES . '/prefs/syncml.html');
    }

    /**
     * Create code for ActiveSync management.
     *
     * @param Horde_Core_Prefs_Ui $ui  The UI object.
     *
     * @return string HTML UI code.
     */
    protected function _activesyncManagement($ui)
    {
        if (!empty($GLOBALS['conf']['activesync']['enabled'])) {
            $state_params = $GLOBALS['conf']['activesync']['state']['params'];
            $state_params['db'] = $GLOBALS['injector']->getInstance('Horde_Db_Adapter_Base');
            $stateMachine = new Horde_ActiveSync_State_History($state_params);
        } else {
            return _("ActiveSync not activated.");
        }
        Horde::addScriptFile('activesyncprefs.js', 'horde');
        $t = $GLOBALS['injector']->createInstance('Horde_Template');
        $t->setOption('gettext', true);
        $selfurl = $ui->selfUrl();
        $t->set('reset', $selfurl->copy()->add('reset', 1));
        $devices = $stateMachine->listDevices(Horde_Auth::getAuth());
        $devs = array();
        $i = 1;
        foreach ($devices as $device) {
            $device['class'] = fmod($i++, 2) ? 'rowOdd' : 'rowEven';
            $stateMachine->loadDeviceInfo($device['device_id'], Horde_Auth::getAuth());
            $ts = $stateMachine->getLastSyncTimestamp();
            $device['ts'] = empty($ts) ? _("None") : strftime($GLOBALS['prefs']->getValue('date_format') . ' %H:%M', $ts);
            switch ($device['device_rwstatus']) {
            case Horde_ActiveSync::RWSTATUS_PENDING:
                $status = '<span class="notice">' . _("Wipe is pending") . '</span>';
                $device['ispending'] = true;
                break;
            case Horde_ActiveSync::RWSTATUS_WIPED:
                $status = '<span class="notice">' . _("Device is wiped") . '</span>';
                break;
            default:
                $status = $device['device_policykey'] ?_("Provisioned") : _("Not Provisioned");
            }
            $device['wipe'] = $selfurl->copy()->add(array('wipe' => $device['device_id']));
            $device['remove'] = $selfurl->copy()->add(array('remove' => $device['device_id']));
            $device['status'] = $status . '<br />' . _("Device id:") . $device['device_id'] . '<br />' . _("Policy Key:") . $device['device_policykey'] . '<br />' . _("User Agent:") . $device['device_agent'];
            $devs[] = $device;
        }

        $t->set('devices', $devs);

        return $t->fetch(HORDE_TEMPLATES . '/prefs/activesync.html');
    }

    /**
     * Facebook session management
     *
     * @param Horde_Core_Prefs_Ui $ui  The UI object.
     *
     * @return string  The HTML code to display the Facebook prefs.
     */
    protected function _facebookManagement($ui)
    {
        global $prefs;

        /* Horde_Service_Facebook */
        $GLOBALS['injector']->addBinder('Facebook', new Horde_Core_Binder_Facebook());
        try {
            $facebook = $GLOBALS['injector']->getInstance('Facebook');
        } catch (Horde_Exception $e) {
            return _($e->getMessage());
        }

        /* Horde_Template */
        $t = $GLOBALS['injector']->createInstance('Horde_Template');
        $t->setOption('gettext', true);
        $t->set('css_link', $GLOBALS['registry']->get('themesuri', 'horde') . '/facebook.css');

        /* Check for facebook session */
        $fbp = unserialize($prefs->getValue('facebook'));
        $uid = !empty($fbp['uid']) ? $fbp['uid'] : 0;
        $sid = !empty($fbp['sid']) ? $fbp['sid'] : 0;

        /* Ensure we have authorized horde */
        if (!empty($uid)) {
            try {
                $have_app = $facebook->users->isAppUser($uid);
            } catch (Horde_Service_Facebook_Exception $e) {
                return $e->getMessage();
            }
        }

        /* Def. have a user that has authroized horde. See if the session key we
         * have is still valid. */
        if (!empty($have_app) && !empty($sid)) {
            $facebook->auth->setUser($uid, $sid, 0);
            try {
                /* Verify the userid matches the one we expect for the session */
                $session_uid = $facebook->auth->getLoggedInUser();
                if ($uid != $session_uid) {
                    // This should never happen.
                    $haveSession = false;
                } else {
                    $haveSession = true;
                }
            } catch (Horde_Service_Facebook_Exception $e) {
                $haveSession = false;
                $prefs->setValue('facebook', serialize(array('uid' => $uid, 'sid' => 0)));
            }
        }

        /* We have a session, build the template */
        if (!empty($haveSession)) {
            try {
                $t->set('have_offline',
                        $facebook->users->hasAppPermission(Horde_Service_Facebook_Auth::EXTEND_PERMS_OFFLINE, $uid));
                $t->set('have_publish',
                        $facebook->users->hasAppPermission(Horde_Service_Facebook_Auth::EXTEND_PERMS_PUBLISHSTREAM, $uid));
                $t->set('have_read',
                        $facebook->users->hasAppPermission(Horde_Service_Facebook_Auth::EXTEND_PERMS_READSTREAM, $uid));
            } catch (Horde_Service_Facebook_Exception $e) {
                $error = $e->getMessage();
            }

            /* Get the user info - facebook's TOS recommends placing the photo
             * and using fb-like css for this. */
            $fql = 'SELECT first_name, last_name, status, pic_with_logo, current_location FROM user WHERE uid IN (' . $uid . ')';
            try {
                $user_info = $facebook->fql->run($fql);
            } catch (Horde_Service_Facebook_Exception $e) {
                $notify->push(_("Temporarily unable to connect with Facebook, Please try again."), 'horde.alert');
            }
            /* URL links */
            $url = $facebook->auth->getExtendedPermUrl(
                Horde_Service_Facebook_Auth::EXTEND_PERMS_OFFLINE,
                Horde_Util::addParameter(Horde::url('services/facebook.php', true), 'action', 'authsuccess'));
            $t->set('authUrl', Horde::signQueryString($url));
            $t->set('have_session', true);
            $t->set('user_pic_url', $user_info[0]['pic_with_logo']);
            $t->set('user_name', $user_info[0]['first_name'] . ' ' . $user_info[0]['last_name']);

            /* publish links */
            $url = $facebook->auth->getExtendedPermUrl(
                Horde_Service_Facebook_Auth::EXTEND_PERMS_PUBLISHSTREAM,
                Horde::url($ui->selfUrl(), true));
            $t->set('publish_url', $url);

            /* Read links */
            $url = $facebook->auth->getExtendedPermUrl(
                Horde_Service_Facebook_Auth::EXTEND_PERMS_READSTREAM,
                Horde::url($ui->selfUrl(), true));
            $t->set('read_url', $url);

            return $t->fetch(HORDE_TEMPLATES . '/prefs/facebook.html');
        }

        /* No existing session */
        $t->set('have_session', false);
        $t->set('authUrl', $facebook->auth->getLoginUrl(Horde::url('services/facebook.php', true)));

        return $t->fetch(HORDE_TEMPLATES . '/prefs/facebook.html');
    }

    /**
     * Update category related preferences.
     *
     * @param Horde_Core_Prefs_Ui $ui  The UI object.
     *
     * @return boolean  True if preferences were updated.
     */
    protected function _updateCategoryManagement($ui)
    {
        $cManager = new Horde_Prefs_CategoryManager();

        /* Always save colors of all categories. */
        $colors = array();
        $categories = $cManager->get();
        foreach ($categories as $category) {
            if ($color = $ui->vars->get('color_' . hash('md5', $category))) {
                $colors[$category] = $color;
            }
        }
        if ($color = $ui->vars->get('color_' . hash('md5', '_default_'))) {
            $colors['_default_'] = $color;
        }
        if ($color = $ui->vars->get('color_' . hash('md5', '_unfiled_'))) {
            $colors['_unfiled_'] = $color;
        }
        $cManager->setColors($colors);

        switch ($ui->vars->cAction) {
        case 'add':
            $cManager->add($ui->vars->category);
            break;

        case 'remove':
            $cManager->remove($ui->vars->category);
            break;

        default:
            /* Save button. */
            Horde::addInlineScript(array(
                'if (window.opener && window.name) window.close();'
            ));
            return true;
        }

        return false;
    }

    /**
     * Update remote servers related preferences.
     *
     * @param Horde_Core_Prefs_Ui $ui  The UI object.
     */
    protected function _updateRemoteManagement($ui)
    {
        global $notification, $prefs;

        $rpc_servers = @unserialize($prefs->getValue('remote_summaries'));
        if (!is_array($rpc_servers)) {
            $rpc_servers = array();
        }

        if ($ui->vars->rpc_change || $ui->vars->rpc_create) {
            $tmp = array(
                'passwd' => $ui->vars->passwd,
                'url' => $ui->vars->url,
                'user' => $ui->vars->user
            );

            if ($ui->vars->rpc_change) {
                $rpc_servers[$ui->vars->server] = $tmp;
            } else {
                $rpc_servers[] = $tmp;
            }

            $prefs->setValue('remote_summaries', serialize($rpc_servers));
            $notification->push(sprintf(_("The server \"%s\" has been saved."), $ui->vars->url), 'horde.success');
        } elseif ($ui->vars->rpc_delete) {
            if ($ui->vars->server == -1) {
                $notification->push(_("You must select an server to be deleted."), 'horde.warning');
            } else {
                $notification->push(sprintf(_("The server \"%s\" has been deleted."), $rpc_servers[$ui->vars->server]['url']), 'horde.success');

                $deleted_server = $rpc_servers[$ui->vars->server]['url'];
                unset($rpc_servers[$ui->vars->server]);
                $prefs->setValue('remote_summaries', serialize(array_values($rpc_servers)));

                $chosenColumns = explode(';', $prefs->getValue('show_summaries'));
                if ($chosenColumns != array('')) {
                    $newColumns = array();
                    foreach ($chosenColumns as $chosenColumn) {
                        $chosenColumn = explode(',', $chosenColumn);
                        $remote = explode('|', $chosenColumn[0]);
                        if (count($remote) != 3 || $remote[2] == $deleted_server) {
                            $newColumns[] = implode(',', $chosenColumn);
                        }
                    }
                    $prefs->setValue('show_summaries', implode(';', $newColumns));
                }
            }
        }
    }

    /**
     * Update SyncML related preferences.
     *
     * @param Horde_Core_Prefs_Ui $ui  The UI object.
     */
    protected function _updateSyncmlManagement($ui)
    {
        $backend = SyncML_Backend::factory('Horde');

        if ($ui->vars->removedb && $ui->vars->removedevice) {
            $res = $backend->removeAnchor(Horde_Auth::getAuth(), $ui->vars->removedevice, $ui->vars->removedb);
            if ($res instanceof PEAR_Error) {
                $GLOBALS['notification']->push(_("Error deleting synchronization session:") . ' ' . $res->getMessage(), 'horde.error');
            } else {
                $GLOBALS['notification']->push(sprintf(_("Deleted synchronization session for device \"%s\" and database \"%s\"."), $ui->vars->deviceid, $ui->vars->db), 'horde.success');
            }
        } elseif ($ui->vars->deleteall) {
            $res = $backend->removeAnchor(Horde_Auth::getAuth());
            if ($res instanceof PEAR_Error) {
                $GLOBALS['notification']->push(_("Error deleting synchronization sessions:") . ' ' . $res->getMessage(), 'horde.error');
            } else {
                $GLOBALS['notification']->push(_("All synchronization sessions deleted."), 'horde.success');
            }
        }
    }

    /**
     * Update ActiveSync actions
     *
     * @param Horde_Core_Prefs_Ui $ui  The UI object.
     */
    protected function _updateActiveSyncManagement($ui)
    {
        $state_params = $GLOBALS['conf']['activesync']['state']['params'];
        $state_params['db'] = $GLOBALS['injector']->getInstance('Horde_Db_Adapter_Base');
        $stateMachine = new Horde_ActiveSync_State_History($state_params);
        $stateMachine->setLogger($GLOBALS['injector']->getInstance('Horde_Log_Logger'));
        if ($ui->vars->wipeid) {
            $stateMachine->loadDeviceInfo($ui->vars->wipeid, Horde_Auth::getAuth());
            $stateMachine->setDeviceRWStatus($ui->vars->wipeid, Horde_ActiveSync::RWSTATUS_PENDING);
            $GLOBALS['notification']->push(sprintf(_("A Remote Wipe for device id %s has been initiated. The device will be wiped during the next SYNC request."), $ui->vars->wipe));
        } elseif ($ui->vars->cancelwipe) {
            $stateMachine->loadDeviceInfo($ui->vars->cancelwipe, Horde_Auth::getAuth());
            $stateMachine->setDeviceRWStatus($ui->vars->cancelwipe, Horde_ActiveSync::RWSTATUS_OK);
            $GLOBALS['notification']->push(sprintf(_("The Remote Wipe for device id %s has been cancelled."), $ui->vars->wipe));
        } elseif ($ui->vars->reset) {
            $devices = $stateMachine->listDevices(Horde_Auth::getAuth());
            foreach ($devices as $device) {
                $stateMachine->removeState(null, $device['device_id'], Horde_Auth::getAuth());
            }
            $GLOBALS['notification']->push(_("All state removed for your devices. They will resynchronize next time they connect to the server."));
        } elseif ($ui->vars->removedevice) {
            $stateMachine->removeState(null, $ui->vars->removedevice, Horde_Auth::getAuth());
        }
    }

    /**
     * Update facebook related prefs
     *
     * @param Horde_Core_Prefs_Ui $ui  The UI object.
     */
    protected function _updateFacebookManagement($ui)
    {
        global $prefs;

        /* Horde_Service_Facebook */
        $GLOBALS['injector']->addBinder('Facebook', new Horde_Core_Binder_Facebook());
        try {
            $facebook = $GLOBALS['injector']->getInstance('Facebook');
        } catch (Horde_Exception $e) {
            return _($e->getMessage());
        }
        switch ($ui->vars->fbactionID) {
        case 'revokeInfinite':
            $fbp = unserialize($prefs->getValue('facebook'));
            if (!$fbp) {
                // Something wrong
            }
            $facebook->auth->setUser($fbp['uid'], $fbp['sid']);
            $facebook->auth->revokeExtendedPermission(
                Horde_Service_Facebook_Auth::EXTEND_PERMS_OFFLINE,
                $facebook->auth->getUser());
            break;
        case 'revokeApplication':
            $fbp = unserialize($prefs->getValue('facebook'));
            if (!$fbp) {
                // Something wrong
            }
            $facebook->auth->setUser($fbp['uid'], $fbp['sid']);
            $facebook->auth->revokeAuthorization();
            // Clear prefs.
            $prefs->setValue('facebook', array('uid' => '',
                                               'sid' => ''));

            break;
        case 'revokePublish':
            $fbp = unserialize($prefs->getValue('facebook'));
            if (!$fbp) {
                // Something wrong
            }
            $facebook->auth->setUser($fbp['uid'], $fbp['sid']);
            $facebook->auth->revokeExtendedPermission(
                Horde_Service_Facebook_Auth::EXTEND_PERMS_PUBLISHSTREAM,
                $facebook->auth->getUser());
            break;
        }
    }

}
