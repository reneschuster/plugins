<?php
/**
 * i-MSCP InstantSSH plugin
 * Copyright (C) 2014 Laurent Declercq <l.declercq@nuxwin.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 */

/***********************************************************************************************************************
 * Functions
 */

/**
 * Send Json response
 *
 * @param int $statusCode HTTP status code
 * @param array $data JSON data
 * @return void
 */
function _instantssh_sendJsonResponse($statusCode = 200, array $data = array())
{
	header('Cache-Control: no-cache, must-revalidate');
	header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
	header('Content-type: application/json');

	switch ($statusCode) {
		case 400:
			header('Status: 400 Bad Request');
			break;
		case 404:
			header('Status: 404 Not Found');
			break;
		case 500:
			header('Status: 500 Internal Server Error');
			break;
		case 501:
			header('Status: 501 Not Implemented');
			break;
		default:
			header('Status: 200 OK');
	}

	exit(json_encode($data));
}

/**
 * Get SSH permissions
 *
 * @return void
 */
function instantssh_getSshPermissions()
{
	if (isset($_GET['ssh_permission_id'])) {
		try {
			$stmt = exec_query(
				'
					SELECT
						ssh_permission_id, ssh_permission_admin_id, ssh_permission_max_keys,
						ssh_permission_auth_options, ssh_permission_jailed_shell, admin_name
					FROM
						instant_ssh_permissions
					INNER JOIN
						admin ON(admin_id = ssh_permission_admin_id)
					WHERE
						ssh_permission_id = ?
				',
				intval($_GET['ssh_permission_id'])
			);

			if ($stmt->rowCount()) {
				_instantssh_sendJsonResponse(200, $stmt->fetchRow(PDO::FETCH_ASSOC));
			}

			_instantssh_sendJsonResponse(404, array('message' => tr('SSH permissions not found.')));
		} catch (iMSCP_Exception_Database $e) {
			write_log(sprintf('InstantSSH: Unable to get SSH permissions: %s', $e->getMessage()), E_USER_ERROR);

			_instantssh_sendJsonResponse(
				500, array('message' => tr('An unexpected error occurred: %s', true, $e->getMessage()))
			);
		}
	}

	_instantssh_sendJsonResponse(400, array('message' => tr('Bad request.')));
}

/**
 * Add/Update SSH permissions
 *
 * @return void
 */
function instantssh_addSshPermissions()
{
	if (isset($_POST['ssh_permission_id']) && isset($_POST['admin_name']) && isset($_POST['ssh_permission_max_keys'])) {
		$sshPermissionId = intval($_POST['ssh_permission_id']);
		$adminName = encode_idna(clean_input($_POST['admin_name']));
		$sshPermissionMaxKey = clean_input($_POST['ssh_permission_max_keys']);
		$sshPermissionAuthOptions = (isset($_POST['ssh_permission_auth_options'])) ? : 0;
		$sshPermissionJailedShell = (isset($_POST['ssh_permission_jailed_shell'])) ? : 0;

		if ($adminName == '' || $sshPermissionMaxKey == '') {
			_instantssh_sendJsonResponse(400, array('message' => tr('All fields are required.')));
		} elseif (!is_number($sshPermissionMaxKey)) {
			_instantssh_sendJsonResponse(
				400,
				array(
					'message' => tr("Wrong value for the 'Maximum number of SSH keys' field. Please, enter a number.")
				)
			);
		}

		$db = iMSCP_Database::getInstance();

		try {
			$db->beginTransaction();

			if (!$sshPermissionId) { // Add SSH permissions
				exec_query(
					'
						INSERT INTO instant_ssh_permissions(
							ssh_permission_admin_id, ssh_permission_max_keys, ssh_permission_auth_options,
							ssh_permission_jailed_shell
						) SELECT
							admin_id, ?, ?, ?
						FROM
							admin
						WHERE
							admin_name = ?
					',
					array($sshPermissionMaxKey, $sshPermissionAuthOptions, $sshPermissionJailedShell, $adminName)
				);

				$db->commit();

				send_request();

				write_log(sprintf('InstantSSH: SSH permissions were added for %s', $adminName), E_USER_NOTICE);

				_instantssh_sendJsonResponse(200, array('message' => tr('SSH permissions scheduled for addition.')));
			} else { // Update SSH permissions
				$stmt = exec_query(
					'
						SELECT
							ssh_permission_auth_options, ssh_permission_jailed_shell
						FROM
							instant_ssh_permissions
						WHERE
							ssh_permission_id = ?
					',
					$sshPermissionId
				);

				if($stmt->rowCount()) {
					$row = $stmt->fetchRow(PDO::FETCH_ASSOC);

					exec_query(
						'
							UPDATE
								instant_ssh_permissions
							SET
								ssh_permission_max_keys = ?, ssh_permission_auth_options = ?,
								ssh_permission_jailed_shell = ?
							WHERE
								ssh_permission_id = ?
						',
						array(
							$sshPermissionMaxKey, $sshPermissionAuthOptions, $sshPermissionJailedShell,
							$sshPermissionId
						)
					);

					if (
						$row['ssh_permission_auth_options'] != $sshPermissionAuthOptions ||
						$row['ssh_permission_jailed_shell'] != $sshPermissionJailedShell
					) {
						if($row['ssh_permission_auth_options'] != $sshPermissionAuthOptions) {
							/** @var iMSCP_Plugin_Manager $pluginManager */
							$pluginManager = iMSCP_Registry::get('pluginManager');
							$defaultSshAuthOptions = $pluginManager->getPlugin('InstantSSH')
								->getConfigParam('default_ssh_auth_options', '');

							exec_query(
								'
									UPDATE
										instant_ssh_keys
									SET
										ssh_auth_options = ?, ssh_key_status = ?
									WHERE
										ssh_permission_id = ?
								',
								array($defaultSshAuthOptions, 'tochange', $sshPermissionId)
							);
						} else {
							exec_query(
								'UPDATE instant_ssh_keys SET ssh_key_status = ? WHERE ssh_permission_id = ?',
								array('tochange', $sshPermissionId)
							);
						}

						$db->commit();

						send_request();
					}

					write_log(sprintf('SSH permissions were updated for %s', $adminName), E_USER_NOTICE);

					_instantssh_sendJsonResponse(
						200, array('message' => tr('SSH permissions were scheduled for update.'))
					);
				}
			}
		} catch (iMSCP_Exception_Database $e) {
			$db->rollBack();

			if ($e->getCode() != '23000') {
				write_log(
					sprintf('InstantSSH: Unable to update SSH permissions for %s: %s', $adminName, $e->getMessage()),
					E_USER_ERROR
				);

				_instantssh_sendJsonResponse(
					500, array('message' => tr('An unexpected error occurred: %s', true, $e->getMessage()))
				);
			}
		}
	}

	_instantssh_sendJsonResponse(400, array('message' => tr('Bad request.')));
}

/**
 * Add/Update SSH permissions
 *
 * @return void
 */
function instantssh_deleteSshPermissions()
{
	if (isset($_POST['ssh_permission_id'])) {
		$sshPermissionId = intval($_POST['ssh_permission_id']);

		try {
			$stmt = exec_query(
				'UPDATE instant_ssh_permissions SET ssh_permissions_status = ? WHERE ssh_permission_id = ?',
				array('todelete', $sshPermissionId)
			);

			if ($stmt->rowCount()) {
				send_request();

				write_log(
					sprintf('InstantSSH: SSH permissions with ID %s were scheduled for deletion', $sshPermissionId),
					E_USER_NOTICE
				);

				_instantssh_sendJsonResponse(
					200, array('message' => tr('SSH permissions were scheduled for deletion.'))
				);
			}
		} catch (iMSCP_Exception_Database $e) {
			write_log(
				sprintf(
					'InstantSSH: Unable to delete SSH permissions with ID %s: %s', $sshPermissionId, $e->getMessage()
				),
				E_USER_ERROR
			);

			_instantssh_sendJsonResponse(
				500, array('message' => tr('An unexpected error occurred: %s', true, $e->getMessage()))
			);
		}
	}

	_instantssh_sendJsonResponse(400, array('message' => tr('Bad request.')));
}

/**
 * Search customer
 *
 * Only customer which doesn't have ssh permissions already set are returned.
 *
 * @return void
 */
function instantssh_searchCustomer()
{
	if (isset($_GET['term'])) {
		$term = encode_idna(clean_input($_GET['term'])) . "%";

		try {
			$stmt = exec_query(
				'
					SELECT
						admin_name
					FROM
						admin
					WHERE
						admin_name LIKE ?
					AND
						admin_type = ?
					AND
						admin_id NOT IN(SELECT ssh_permission_admin_id FROM instant_ssh_permissions)
					',
				array($term, 'user')
			);

			if ($stmt->rowCount()) {
				$responseData = array();
				while ($row = $stmt->fetchRow(PDO::FETCH_ASSOC)) {
					$responseData[] = decode_idna($row['admin_name']);
				}
			} else {
				$responseData = array();
			}

			_instantssh_sendJsonResponse(200, $responseData);
		} catch (iMSCP_Exception_Database $e) {
			write_log(sprintf('InstantSSH: Unable to search customer: %s', $e->getMessage()), E_USER_ERROR);

			_instantssh_sendJsonResponse(
				500, array('message' => tr('An unexpected error occurred: %s', true, $e->getMessage()))
			);
		}
	}

	_instantssh_sendJsonResponse(400, array('message' => tr('Bad request.')));
}

/**
 * Get SSH permission list
 *
 * @return void
 */
function instantssh_getSshPermissionsList()
{
	try {
		$columns = array(
			'ssh_permission_id', 'ssh_permission_admin_id', 'admin_name', 'ssh_permission_max_keys',
			'ssh_permission_auth_options', 'ssh_permission_jailed_shell', 'ssh_permission_status'
		);

		$nbColumns = count($columns);

		$indexColumn = 'ssh_permission_id';

		/* DB table to use */
		$table = 'instant_ssh_permissions';

		/* Paging */
		$limit = '';

		if (isset($_GET['iDisplayStart']) && $_GET['iDisplayLength'] != '-1') {
			$limit = 'LIMIT ' . intval($_GET['iDisplayStart']) . ', ' . intval($_GET['iDisplayLength']);
		}

		/* Ordering */
		$order = '';

		if (isset($_GET['iSortCol_0'])) {
			$order = 'ORDER BY ';

			for ($i = 0; $i < intval($_GET['iSortingCols']); $i++) {
				if ($_GET['bSortable_' . intval($_GET['iSortCol_' . $i])] == 'true') {
					$order .= $columns[intval($_GET['iSortCol_' . $i])] . ' ' . $_GET['sSortDir_' . $i] . ', ';
				}
			}

			$order = substr_replace($order, '', -2);

			if ($order == 'ORDER BY') {
				$order = '';
			}
		}

		/* Filtering */
		$where = '';

		if ($_REQUEST['sSearch'] != '') {
			$where .= 'WHERE (';

			for ($i = 0; $i < $nbColumns; $i++) {
				$where .= $columns[$i] . ' LIKE ' . quoteValue("%{$_GET['sSearch']}%") . ' OR ';
			}

			$where = substr_replace($where, '', -3);
			$where .= ')';
		}

		/* Individual column filtering */
		for ($i = 0; $i < $nbColumns; $i++) {
			if (isset($_GET["bSearchable_$i"]) && $_GET["bSearchable_$i"] == 'true' && $_GET["sSearch_$i"] != '') {
				$where .= "AND {$columns[$i]} LIKE " . quoteValue("%{$_GET["sSearch_$i"]}%");
			}
		}

		/* Get data to display */
		$rResult = execute_query(
			'
				SELECT
					SQL_CALC_FOUND_ROWS ' . str_replace(' , ', ' ', implode(', ', $columns)) . "
				FROM
					$table
				INNER JOIN
					admin ON(admin_id = ssh_permission_admin_id)
				$where
				$order
				$limit
			"
		);

		/* Data set length after filtering */
		$resultFilterTotal = execute_query('SELECT FOUND_ROWS()');
		$resultFilterTotal = $resultFilterTotal->fetchRow(PDO::FETCH_NUM);
		$filteredTotal = $resultFilterTotal[0];

		/* Total data set length */
		$resultTotal = execute_query("SELECT COUNT($indexColumn) FROM $table");
		$resultTotal = $resultTotal->fetchRow(PDO::FETCH_NUM);
		$total = $resultTotal[0];

		/* Output */
		$output = array(
			'sEcho' => intval($_GET['sEcho']),
			'iTotalRecords' => $total,
			'iTotalDisplayRecords' => $filteredTotal,
			'aaData' => array()
		);

		$trEditTooltip = tr('Edit permissions');
		$trDeleteTooltip = tr('Revoke permissions');

		while ($data = $rResult->fetchRow(PDO::FETCH_ASSOC)) {
			$row = array();

			for ($i = 0; $i < $nbColumns; $i++) {
				if ($columns[$i] == 'admin_name') {
					$row[$columns[$i]] = tohtml(decode_idna($data[$columns[$i]]));
				} elseif ($columns[$i] == 'ssh_permission_auth_options') {
					$row[$columns[$i]] = ($data[$columns[$i]]) ? tr('yes') : tr('no');
				} elseif ($columns[$i] == 'ssh_permission_max_keys') {
					$row[$columns[$i]] = (!$data[$columns[$i]]) ? tr('unlimited') : $data[$columns[$i]];
				} elseif ($columns[$i] == 'ssh_permission_jailed_shell') {
					$row[$columns[$i]] = ($data[$columns[$i]]) ? tr('yes') : tr('no');
				} elseif ($columns[$i] == 'ssh_permission_status') {
					$row[$columns[$i]] = translate_dmn_status($data[$columns[$i]]);
				} else {
					$row[$columns[$i]] = tohtml($data[$columns[$i]]);
				}
			}

			if($data['ssh_permission_status'] == 'ok') {
				$row['ssh_permission_actions'] =
					"<span title=\"$trEditTooltip\" data-action=\"edit_ssh_permissions\" " .
					"data-ssh-permission-id=\"{$data['ssh_permission_id']}\" " .
					"class=\"icon icon_edit clickable\">&nbsp;</span> "
					.
					"<span title=\"$trDeleteTooltip\" data-action=\"delete_ssh_permissions\" " .
					"data-ssh-permission-id=\"{$data['ssh_permission_id']}\" " .
					"class=\"icon icon_delete clickable\">&nbsp;</span>";
			} else {
				$row['ssh_permission_actions'] = tr('n/a');
			}

			$output['aaData'][] = $row;
		}

		_instantssh_sendJsonResponse(200, $output);
	} catch (iMSCP_Exception_Database $e) {
		write_log(sprintf('InstantSSH: Unable to get SSH permissions list: %s', $e->getMessage()), E_USER_ERROR);

		_instantssh_sendJsonResponse(
			500, array('message' => tr('An unexpected error occurred: %s', true, $e->getMessage()))
		);
	}

	_instantssh_sendJsonResponse(400, array('message' => tr('Bad request.')));
}

/***********************************************************************************************************************
 * Main
 */

iMSCP_Events_Aggregator::getInstance()->dispatch(iMSCP_Events::onAdminScriptStart);

check_login('admin');

if (isset($_REQUEST['action'])) {
	if (is_xhr()) {
		$action = clean_input($_REQUEST['action']);

		switch ($action) {
			case 'get_ssh_permissions_list':
				instantssh_getSshPermissionsList();
				break;
			case 'search_customer':
				instantssh_searchCustomer();
				break;
			case 'add_ssh_permissions':
				instantssh_addSshPermissions();
				break;
			case 'get_ssh_permissions':
				instantssh_getSshPermissions();
				break;
			case 'delete_ssh_permissions':
				instantssh_deleteSshPermissions();
				break;
			default:
				_instantssh_sendJsonResponse(400, array('message' => tr('Bad request.')));
		}
	}

	showBadRequestErrorPage();
}

$tpl = new iMSCP_pTemplate();
$tpl->define_dynamic(
	array(
		'layout' => 'shared/layouts/ui.tpl',
		'page' => '../../plugins/InstantSSH/themes/default/view/admin/ssh_permissions.tpl',
		'page_message' => 'layout'
	)
);

if (iMSCP_Registry::get('config')->DEBUG) {
	$assetVersion = time();
} else {
	$pluginInfo = iMSCP_Registry::get('pluginManager')->getPluginInfo('InstantSSH');
	$assetVersion = strtotime($pluginInfo['date']);
}

$tpl->assign(
	array(
		'TR_PAGE_TITLE' => tr('Admin / Settings / SSH Permissions'),
		'ISP_LOGO' => layout_getUserLogo(),
		'INSTANT_SSH_ASSET_VERSION' => $assetVersion,
		'DATATABLE_TRANSLATIONS' => getDataTablesPluginTranslations()
	)
);

generateNavigation($tpl);
generatePageMessage($tpl);

$tpl->parse('LAYOUT_CONTENT', 'page');

iMSCP_Events_Aggregator::getInstance()->dispatch(iMSCP_Events::onAdminScriptEnd, array('templateEngine' => $tpl));

$tpl->prnt();
