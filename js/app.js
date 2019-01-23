/**
 * EGroupware - Profitbricks - UI
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <rb-AT-egroupware.org>
 * @package profitbricks
 * @copyright (c) 2017-19 by Ralf Becker <rb-AT-egroupware.org>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

/**
 * JS for profitbricks app
 *
 * @augments AppJS
 */
app.classes.profitbricks = AppJS.extend(
{
	appname: 'profitbricks',
	/**
	 * et2 widget container
	 */
	et2: null,
	/**
	 * path widget
	 */

	/**
	 * Constructor
	 *
	 * @memberOf app.timesheet
	 */
	init: function()
	{
		// call parent
		this._super.apply(this, arguments);
	},

	/**
	 * Destructor
	 */
	destroy: function()
	{
		delete this.et2;
		// call parent
		this._super.apply(this, arguments);
	},

	/**
	 * This function is called when the etemplate2 object is loaded
	 * and ready.  If you must store a reference to the et2 object,
	 * make sure to clean it up in destroy().
	 *
	 * @param {etemplate2} _et2
	 * @param {string} _name name of template loaded
	 */
	et2_ready: function(_et2, _name)
	{
		// call parent
		this._super.apply(this, arguments);

		switch(_name)
		{
			case 'profitbricks.index':
		}
	},

	/**
	 * Confirm an action: <action> server <server-name>?
	 *
	 * @param {egwAction} action
	 * @param {egwActionObject[]} selected
	 */
	confirm: function(action, selected)
	{
		var self = this;
		var data = this.egw.dataGetUIDdata(selected[0].id);
		var name = data && data.data && data.data.properties ? data.data.properties.name : null || selected[0].id;
		var message = action.caption+' server '+name;
		if (action.hint) message += "\n\n"+action.hint;

		et2_dialog.show_dialog(function(_button, _args)
		{
			if (_button == et2_dialog.YES_BUTTON)
			{
				self.action(_args[0], _args[1]);
			}
		}, message, this.egw.lang('Confirmation required'), [action, selected]);
	},

	/**
	 * Confirm an action: <action> server <server-name>?
	 *
	 * @param {egwAction} action
	 * @param {egwActionObject[]} selected
	 */
	console: function(action, selected)
	{
		var data = this.egw.dataGetUIDdata(selected[0].id);

		this.egw.openPopup('https://dcd.ionos.com/latest/noVnc/connect.html?uuid='+
			encodeURIComponent(data.data.id)+'&name='+encodeURIComponent(data.data.properties.name)+
			'&nocache='+'16290.705733353605'+'&lang='+encodeURIComponent(this.egw.preference('lang'))
			+'&useProxy=false', 800, 600, data.data.properties.name);
	},

	/**
	 * Run an action
	 *
	 * @param {egwAction} action
	 * @param {egwActionObject[]} selected
	 */
	action: function(action, selected)
	{
		var request = egw.json('profitbricks_ui::ajax_action', [action.id, selected[0].id]).sendRequest();
	},

	/**
	 * Show dialog to schedule an action for a given time
	 *
	 * @param {egwAction} action
	 * @param {egwActionObject[]} selected
	 */
	schedule: function(action, selected)
	{
		alert('Not (yet) implemented ;-)');

		//var request = egw.json('profitbricks_ui::ajax_action', [action_to_run, selected[0].id, schedule]).sendRequest();
	}
});
