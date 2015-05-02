<?php

/***************************************************************************
 *
 *	Newpoints Thread Ratings Integration plugin (/inc/plugins/newpoints/newpoints_threadratings.php)
 *	Author: Omar Gonzalez
 *	Copyright: © 2015 Omar Gonzalez
 *
 *	Website: http://omarg.me
 *
 *	Charge your users to rate threads and give those points to the thread author.
 *
 ***************************************************************************

****************************************************************************
	This program is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program.  If not, see <http://www.gnu.org/licenses/>.
****************************************************************************/

// Disallow direct access to this file for security reasons
defined('IN_MYBB') or die('Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.');

// Add the hooks we are going to use.
if(!defined("IN_ADMINCP"))
{
	$plugins->add_hook('ratethread_start', 'newpoints_threadratings_ratethread_start');
}

// Plugin API
function newpoints_threadratings_info()
{
	global $lang;
	isset($lang->newpoints_threadratings) or newpoints_lang_load('newpoints_threadratings');

	return array(
		'name'			=> 'Newpoints Thread Ratings Integration',
		'description'	=> $lang->newpoints_threadratings_desc,
		'website'		=> 'http://omarg.me',
		'author'		=> 'Omar G.',
		'authorsite'	=> 'http://omarg.me',
		'version'		=> '1.0',
		'versioncode'	=> 1000,
		'compatibility'	=> '2*'
	);
}

// _activate() routine
function newpoints_threadratings_activate()
{
	global $cache, $lang;
	isset($lang->newpoints_threadratings) or newpoints_lang_load('newpoints_threadratings');

	// Add our settings
	newpoints_add_settings('newpoints_threadratings', array(
			'points'	=> array(
				'title'			=> $lang->setting_newpoints_threadratings_points,
				'description'	=> $lang->setting_newpoints_threadratings_points_desc,
				'type'			=> 'text',
				'value'			=> 1
			),
	));

	// Insert/update version into cache
	$plugins = $cache->read('ougc_plugins');
	if(!$plugins)
	{
		$plugins = array();
	}

	$info = newpoints_threadratings_info();

	if(!isset($plugins['newpoints_threadratings']))
	{
		$plugins['newpoints_threadratings'] = $info['versioncode'];
	}

	/*~*~* RUN UPDATES START *~*~*/

	/*~*~* RUN UPDATES END *~*~*/

	$plugins['newpoints_threadratings'] = $info['versioncode'];
	$cache->update('ougc_plugins', $plugins);
}

// Uninstall the plugin.
function newpoints_threadratings_uninstall()
{
	global $cache;

	// Remove settings
	newpoints_remove_settings("'newpoints_threadratings_points'");

	// Delete version from cache
	$plugins = (array)$cache->read('ougc_plugins');

	if(isset($plugins['newpoints_threadratings']))
	{
		unset($plugins['newpoints_threadratings']);
	}

	if(!empty($plugins))
	{
		$cache->update('ougc_plugins', $plugins);
	}
	else
	{
		$cache->delete('ougc_plugins');
	}
}

// Check if this plugin is installed.
function newpoints_threadratings_is_installed()
{
	global $cache;

	$plugins = (array)$cache->read('ougc_plugins');

	return isset($plugins['newpoints_threadratings']);
}

// Hijack the default feature and replace with ours
function newpoints_threadratings_ratethread_start()
{
	global $plugins, $mybb, $thread;

	$plugins->remove_hook('ratethread_process', 'newpoints_perrate');

	if(!$mybb->settings['newpoints_main_enabled'] || !$thread['uid'])
	{
		return;
	}
	
	if(!$mybb->user['uid'])
	{
		error_no_permission();
	}

	global $lang;
	isset($lang->newpoints_threadratings) or newpoints_lang_load('newpoints_threadratings');

	if(!$thread['uid'])
	{
		error($lang->setting_newpoints_threadratings_error_guest);
	}

	$plugins->add_hook('ratethread_process', 'newpoints_threadratings_ratethread_process');
}

// Magic
function newpoints_threadratings_ratethread_process()
{
	global $lang, $fid, $mybb;
	isset($lang->newpoints_threadratings) or newpoints_lang_load('newpoints_threadratings');

	$forumrules = newpoints_getrules('forum', $fid);
	if(!$forumrules)
	{
		$forumrules['rate'] = 1;
	}

	if($forumrules['rate'] == 0)
	{
		return;
	}

	$grouprules = newpoints_getrules('group', $mybb->user['usergroup']);
	if(!$grouprules)
	{
		$grouprules['rate'] = 1;
	}

	if($grouprules['rate'] == 0)
	{
		return;
	}

	global $ratecheck, $thread, $ismod;

	$points = (float)$mybb->settings['newpoints_threadratings_points'];
	$points *= $mybb->input['rating'];

	if($mybb->user['newpoints'] < $points)
	{
		error($lang->setting_newpoints_threadratings_error_no_enough_points);
	}

	// Remove the points from current suer
	newpoints_addpoints($mybb->user['uid'], -$points, $forumrules['rate'], $grouprules['rate']);

	// Give points to thread author
	$user = get_user($thread['uid']);
	$grouprules = newpoints_getrules('group', $user['usergroup']);
	if(!$grouprules)
	{
		$grouprules['rate'] = 1;
	}

	if($grouprules['rate'] != 0)
	{
		newpoints_addpoints($thread['uid'], $points, $forumrules['rate'], $grouprules['rate']);
	}
}

if(!function_exists('newpoints_add_settings'))
{
	/**
	 * Adds a new set of settings
	 * 
	 * @param string the name (unique identifier) of the setting plugin
	 * @param array the array containing the settings
	 * @return bool false on failure, true on success
	 *
	*/
	function newpoints_add_settings($plugin, $settings)
	{
		global $db;

		$plugin_escaped = $db->escape_string($plugin);

		$db->update_query('newpoints_settings', array('description' => 'NEWPOINTSDELETESETTING'), "plugin='{}'");

		$disporder = 0;

		// Create and/or update settings.
		foreach($settings as $key => $setting)
		{
			$setting = array_intersect_key($setting,
				array(
					'title'			=> 0,
					'description'	=> 0,
					'type'			=> 0,
					'value'			=> 0
				)
			);

			$setting = array_map(array($db, 'escape_string'), $setting);

			$setting = array_merge(
			array(
				'title'			=> '',
				'description'	=> '',
				'type'			=> 'yesno',
				'value'			=> 0,
				'disporder'		=> ++$disporder
			), $setting);

			$setting['plugin'] = $plugin_escaped;
			$setting['name'] = $db->escape_string($plugin.'_'.$key);

			$query = $db->simple_select('newpoints_settings', 'sid', "plugin='{$setting['plugin']}' AND name='{$setting['name']}'");

			if($sid = $db->fetch_field($query, 'sid'))
			{
				unset($setting['value']);
				$db->update_query('newpoints_settings', $setting, "sid='{$sid}'");
			}
			else
			{
				$db->insert_query('newpoints_settings', $setting);
			}
		}

		$db->delete_query('newpoints_settings', "plugin='{$plugin_escaped}' AND description='NEWPOINTSDELETESETTING'");

		newpoints_rebuild_settings_cache();
	}
}