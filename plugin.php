<?php
/*
Plugin Name: YOURLS EE Multi Users
Plugin URI: https://github.com/p-arnaud/yourls-ee-multi-users
Description: This plugin enables multi users.
Version: 1.1
Author: p-arnaud
Author URI: https://github.com/p-arnaud
*/

// No direct call
if( !defined( 'YOURLS_ABSPATH' ) ) die();


if (!defined('YOURLS_DB_PREFIX')) {
    define('YOURLS_DB_PREFIX',"yourls_");
}

if(!defined('YOURLS_DB_TABLE_USERS')) {
    define('YOURLS_DB_TABLE_USERS', YOURLS_DB_PREFIX."users");
}

if(!defined('YOURLS_DB_TABLE_URL_TO_USER')) {
    define('YOURLS_DB_TABLE_URL_TO_USER', YOURLS_DB_PREFIX.'url_to_user');
}


if(!defined('YOURLS_DB_TABLE_URL')) {
    define('YOURLS_DB_TABLE_URL', YOURLS_DB_PREFIX.'url');
}


if(!defined('YOURLS_DB_TABLE_LOG')) {
    define('YOURLS_DB_TABLE_LOG', YOURLS_DB_PREFIX.'log');
}

global $ALLOWED_USERS_PLUGINS;
$ALLOWED_USERS_PLUGINS = array(
    'plugin_page_ee_mass_import',
    'plugin_page_ee_expirationdate',
    'plugin_page_ee_password',
    'plugin_page_ozh_lmr',
);


global $FORBIDDEN_ADMIN_PLUGINS;
$FORBIDDEN_ADMIN_PLUGINS = array(
    'plugin_page_ozh_lmr',
    'plugin_page_ee_mass_import',
    'plugin_page_ee_expirationdate',
    'plugin_page_ee_password',
);

# populate users/password list
global $yourls_user_passwords;
global $yourls_admins_user_passwords;
$yourls_admins_user_passwords = $yourls_user_passwords;
global $ydb;
// $yourls_user_passwords = array();
$table = YOURLS_DB_TABLE_USERS;
$results = $ydb->get_results("select `user_email`, `user_password` from `$table`");
foreach ($results as $value) {
    $yourls_user_passwords[$value->user_email] = $value->user_password;
}

# Install user table
yourls_add_action( 'activated_yourls-ee-multi-users/plugin.php', 'ee_multi_users_installer' );
function ee_multi_users_installer() {
    global $ydb;
    global $yourls_user_passwords;
    $tableuser = YOURLS_DB_TABLE_USERS;
    $results = $ydb->get_results("show tables like '$tableuser'; ");
    if(empty($results)) {
        $ydb->query("CREATE TABLE `$tableuser` (
		 `user_id` int(11) NOT NULL auto_increment,
		 `user_email` varchar(200) NOT NULL,
		 `user_password` varchar(1024) NOT NULL,
		 PRIMARY KEY  (`user_id`),
		 UNIQUE KEY `user_email_UNIQUE` (`user_email`)
		 );");
        $create_success = $ydb->query("SHOW TABLES LIKE '$tableuser'");
        if(!$create_success) {
            echo 'Problems on creation.' . $tableuser;
            die;
        } else {
            $users_exemple = array();
            $users_exemple['user1'] = 'user1';
            $users_exemple['user2'] = 'user2';
            foreach ($users_exemple as $username => $password) {
                if (substr( $password, 0, 7 ) != 'phpass:') {
                    // Create user as Yourls's core does
                    $hash = yourls_phpass_hash( $password );
                    // PHP would interpret $ as a variable, so replace it in storage.
                    $hash = str_replace( '$', '!', $hash );
                    $password = 'phpass:'.$hash;

                }
                $ydb->query("insert into `$tableuser` (user_email, user_password) values ('$username', '$password')");
            }
        }
    }
    $table_url_to_user = YOURLS_DB_TABLE_URL_TO_USER;
    $results = $ydb->get_results("show tables like '$table_url_to_user'; ");
    if(empty($results)) {
        $ydb->query("CREATE TABLE `$table_url_to_user` (
			`url_keyword` varchar(200) character set latin1 collate latin1_bin NOT NULL,
			`users_user_id` int(11) NOT NULL,
			PRIMARY KEY  (`url_keyword`,`users_user_id`)
			);");
        $create_success = $ydb->query("SHOW TABLES LIKE '$table_url_to_user'");
        if(!$create_success) {
            echo "Problems on creation. " . $table_url_to_user;
            die;
        }
    }

}

// Register plugin page in admin page
yourls_add_action( 'plugins_loaded', 'ee_multi_users_display_panel' );
function ee_multi_users_display_panel() {
    yourls_register_plugin_page( 'ee_multi_users', 'YOURLS EE Multi Users', 'ee_multi_users_display_page' );
}

// Add column to admin's url listing
yourls_add_filter('table_head_cells', 'ee_multi_users_table_head_cells');
function ee_multi_users_table_head_cells($args) {
    $username = YOURLS_USER;
    $is_admin = ee_multi_users_is_admin($username);
    if ($is_admin == 1) {
        unset($args['actions']);
        $args['username'] = 'Username';
    }
    return $args;
}
// Show username in admin's url listing
yourls_add_filter('table_add_row_cell_array', 'ee_multi_users_table_add_row_cell_array');
function ee_multi_users_table_add_row_cell_array($args) {
    $username = YOURLS_USER;
    $is_admin = ee_multi_users_is_admin($username);
    if ($is_admin == 1) {
        unset($args['actions']);
        global $ydb;
        $keyword = $args['keyword']['keyword_html'];
        $user_id = ee_multi_users_get_user_id_by_keyword($keyword);
        $username = ee_multi_users_get_username_by_id($user_id);
        $args['username'] = array(
            'template' => '%username%',
            'username' => $username,
        );
    }
    return $args;
}

// Function which will draw the admin page
function ee_multi_users_display_page() {
    global $ydb;
    if (isset($_GET['show_user'])) {
        $user_id = $_GET['show_user'];
        $user_id = yourls_sanitize_string($user_id);
        $username = ee_multi_users_get_username_by_id($user_id);
        if ($username === null) {
            echo "User doesn't exists";
            return;
        }
        $user_keywords = ee_multi_users_get_user_keywords($user_id);
        ?>
        <div>
            <h2>
                Account : <?php echo $username; ?>
            </h2>
        </div>
        <div>
            <h3>Edition</h3>
            <div>
                <form action="plugins.php?page=ee_multi_users" method="post" onsubmit="return confirm('Do you really want to submit the form?');">
                    <input type="hidden" name="username" id="username" value="<?php echo $username ?>">
                    <input type="text" name="rename_account" placeholder="Rename account" id="rename_account">
                    <input type="submit" value="Rename account" name="submit">
                </form>
            </div>
            <div>
                <form action="plugins.php?page=ee_multi_users" method="post" onsubmit="return confirm('Do you really want to submit the form?');">
                    <input type="hidden" name="username" id="username" value="<?php echo $username ?>">
                    <input type="text" name="new_password" placeholder="New password" id="new_password">
                    <input type="submit" value="Send" name="submit">
                </form>
            </div>
            <div>
                <form action="plugins.php?page=ee_multi_users" method="post" onsubmit="return confirm('Do you really want to submit the form?');">
                    <input type="hidden" name="username" id="username" value="<?php echo $username ?>">
                    <input type="hidden" name="delete_account" id="delete_account">
                    <input type="submit" value="Delete account and urls" name="submit">
                </form>
            </div>

        </div>
        <div>

            <h3>Urls</h3>
                <?php ee_multi_users_table_style(); ?>
            <table>
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Keyword</th>
                        <th>Yourls url</th>
                        <th>Redirect to</th>
                        <th>Clicks</th>
                        <th>Stats</th>
                    </tr>
                </thead>

            <?php
            foreach ($user_keywords as $index => $keyword) {
                $url = yourls_get_keyword_infos($keyword);
                ?>
                <tr>
                    <td><?php echo $url['title'] ?></td>
                    <td><?php echo $url['keyword'] ?></td>
                    <td><a href="<?php echo YOURLS_SITE . "/" . $keyword ?>"><?php echo YOURLS_SITE . "/" . $keyword ?></a></td>
                    <td><a href="<?php echo $url['url'] ?>"><?php echo $url['url'] ?></a></td>
                    <td><?php echo ee_multi_users_count_clicks_by_keyword($keyword) ?></td>
                    <td><a href="<?php echo YOURLS_SITE . "/" . $keyword ?>+">View</a></td>
                </tr>
                <?php
            }
            ?>
            </table>
        </div>
        <?php
        return true;
    }

    if (isset($_POST['delete_account']) && isset($_POST['username'])) {
        $username = yourls_sanitize_string($_POST['username']);

        $table = YOURLS_DB_TABLE_USERS;
        $user = $ydb->get_results("select user_id from `$table` where `user_email` = '$username'");
        $user_id = $user[0]->user_id;

        $keywords = ee_multi_users_get_user_keywords($user_id);
        $keywords = "'".implode("','", $keywords) . "'";

        $table = YOURLS_DB_TABLE_URL_TO_USER;
        $ydb->query("DELETE FROM `$table` where `users_user_id` = '$user_id'");

        $table = YOURLS_DB_TABLE_URL;
        $ydb->query("DELETE FROM `$table` where `keyword` IN ($keywords)");

        $table = YOURLS_DB_TABLE_USERS;
        $ydb->query("DELETE FROM `$table` where `user_email` = '$username'");
        header("Refresh:0");
    }

    if (isset($_POST['rename_account']) && isset($_POST['username'])) {
        $new_username = yourls_sanitize_string($_POST['rename_account']);
        $username = yourls_sanitize_string($_POST['username']);

        $table = YOURLS_DB_TABLE_USERS;
        $ydb->query("UPDATE `$table` SET `user_email`= '$new_username' where `user_email` = '$username'");
        header("Refresh:0");
    }

    if (isset($_POST['new_password']) && isset($_POST['username'])) {
        $username = yourls_sanitize_string($_POST['username']);
        $new_password = $_POST['new_password'];

        // Create user as Yourls's core does
        $hash = yourls_phpass_hash( $new_password );
        // PHP would interpret $ as a variable, so replace it in storage.
        $hash = str_replace( '$', '!', $hash );
        $new_password = 'phpass:'.$hash;
        $table = YOURLS_DB_TABLE_USERS;
        $ydb->query("UPDATE `$table` SET `user_password`= '$new_password' where `user_email` = '$username'");
        header("Refresh:0");
    }

    if (isset($_POST['new_username']) && isset($_POST['new_password'])) {
        $username = yourls_sanitize_string($_POST['new_username']);
        $password = $_POST['new_password'];

        if(!empty($username) && !empty($password)) {
            $table = YOURLS_DB_TABLE_USERS;
            $results = $ydb->get_results("select user_email from `$table` where `user_email` = '$username'");
            if($results) {
                echo "Please choose other username.<br>";
            } else {
                $hash = yourls_phpass_hash( $password );
                // PHP would interpret $ as a variable, so replace it in storage.
                $hash = str_replace( '$', '!', $hash );
                $password = 'phpass:'.$hash;
                $ydb->query("insert into `$table` (user_email, user_password) values ('$username', '$password')");
                $results = $ydb->get_results("select user_email from `$table` where `user_email` = '$username'");
                if (!empty($results)) {
                    echo "User $username added.<br>";
                } else {
                }
            }
            header("Refresh:0");

        } else {
            echo "Please fill all fields.<br>";
        }
    }
    ?>
    <form action="plugins.php?page=ee_multi_users" method="post" >
        <h2>Add new user:</h2>
        <input type="text" name="new_username"  placeholder="New username" id="new_username">
        <input type="text" name="new_password" placeholder="New password" id="new_password">
        <input type="submit" value="Send" name="submit">
    </form>
    <h2>Exising users:</h2>
    <?php
    // list existing users
    global $yourls_user_passwords;
    ee_multi_users_table_style();
    ?>
    <table>
        <thead>
            <tr>
                <th>Username</th>
                <th>Urls count</th>
                <th>Total clicks</th>
            </tr>
        </thead>
    <?php
    foreach ($yourls_user_passwords as $username => $value) {
        if (ee_multi_users_is_admin($username)) {
            continue;
        }

        $user_id = ee_multi_users_get_userid_by_username($username);
        ?>
            <tr>
                <td>
                    <?php echo "<a href='plugins.php?page=ee_multi_users&show_user=" . $user_id . "'>" . $username . "</a>";?>
                </td>
                <td>
                    <?php echo count (ee_multi_users_get_user_keywords($user_id)); ?>
                </td>
                <td>
                    <?php echo ee_multi_users_get_click_by_userid($user_id); ?>
                </td>
            </tr>
        <?php
    }
    ?>
    </table>
    <?php
}

yourls_add_filter('add_new_link', "ee_multi_users_add_new_link");
function ee_multi_users_add_new_link($args) {
    global $ydb;
    error_log(print_r($args, true));
    if ($args['status'] == 'success') {
        $url = $args['url']['url'];
        $keyword = $args['url']['keyword'];
        $title = $args['url']['title'];

        $username = YOURLS_USER;
        $table = YOURLS_DB_TABLE_USERS;
        $user = $ydb->get_results("select user_id from `$table` where `user_email` = '$username'");
        $user_id = $user[0]->user_id;

        $table = YOURLS_DB_TABLE_URL_TO_USER;
        if($user_id != false && !empty($keyword)) {
            $ydb->query("insert into `$table` (url_keyword, users_user_id) values ('$keyword', '$user_id')");
        }
    }
    return $args;
}

yourls_add_action( 'pre_edit_link', "ee_multi_users_edit_link");
function ee_multi_users_edit_link($args) {
    global $ydb;
    if($args[4]) {
        $table = YOURLS_DB_TABLE_URL_TO_USER;
        $keyword = $args[1];
        $newkeyword = $args[2];
        $ydb->query("UPDATE `$table` SET `url_keyword` = '$newkeyword' WHERE `url_keyword` = '$keyword';");
    }
    return $args;
}

yourls_add_filter( 'delete_link', "ee_multi_users_delete_link");
function ee_multi_users_delete_link($args) {
    global $ydb;
    $keyword = $args[0];
    $table = YOURLS_DB_TABLE_URL_TO_USER;
    $ydb->query("DELETE FROM `$table` WHERE `url_keyword` = '$keyword';");
    return $args;
}

# Only show user own link
yourls_add_filter('admin_list_where', 'ee_multi_users_admin_list_where', 'where');
function ee_multi_users_admin_list_where($where) {
    global $ydb;
    $username = YOURLS_USER;
    if (!ee_multi_users_is_admin($username)) {
        $keywords = ee_multi_users_get_current_user_keywords();
        $keywords = "'".implode("','", $keywords) . "'";
        $where .= " AND keyword IN ($keywords)";
    }
    return $where;
}


// Restrict access to plugin management
yourls_add_filter('html_head', 'ee_multi_users_html_head', 'context');
function ee_multi_users_html_head($context) {
    global $ALLOWED_USERS_PLUGINS;
    global $FORBIDDEN_ADMIN_PLUGINS;
    $username = YOURLS_USER;
    if ((strpos($context[0], 'plugin_page_') !== false or $context[0] == 'plugins')) {
        if (ee_multi_users_is_admin($username) !== true) {
            if (array_search($context[0], $ALLOWED_USERS_PLUGINS) !== false) {
                return $context;
            }
            else {
                return yourls_redirect( '/admin', 301 );
            }
        }
        else if (array_search($context[0], $FORBIDDEN_ADMIN_PLUGINS) !== false)
        {
            return yourls_redirect( '/admin', 301 );
        }
    }
    return $context;
}

// Remove not allowed plugins for non admin users
yourls_add_filter('admin_sublinks', 'ee_multi_users_admin_sublinks', 'context');
function ee_multi_users_admin_sublinks($context) {
    global $ALLOWED_USERS_PLUGINS;
    global $FORBIDDEN_ADMIN_PLUGINS;
    $username = YOURLS_USER;

    foreach ($context['plugins'] as $key => $value) {
        if (ee_multi_users_is_admin($username) === false) {
            if (array_search('plugin_page_'. $key, $ALLOWED_USERS_PLUGINS) === false) {
                unset($context['plugins'][$key]);
            };
        }
        else {
            if (array_search('plugin_page_'. $key, $FORBIDDEN_ADMIN_PLUGINS) !== false) {
                unset($context['plugins'][$key]);
            }
        }
    }
    return $context;
}

// Admin cant add urls
yourls_add_filter( 'html_addnew', 'ee_multi_users_html_addnew');
function ee_multi_users_html_addnew( $args ) {
    ?>
    <script>
        document.getElementById("new_url").remove();
    </script>
    <?php
    return 0;
}

function ee_multi_users_is_admin($username) {
    global $yourls_admins_user_passwords;
    if (array_search($username,array_keys($yourls_admins_user_passwords)) !== false) {
        return true;
    }
    else {
        return false;
    }
}

function ee_multi_users_get_user_keywords($user_id) {
    global $ydb;
    $table = YOURLS_DB_TABLE_URL_TO_USER;
    $results = $ydb->get_results("select url_keyword from `$table` where `users_user_id` = '$user_id'");
    $keywords = array();
    foreach ($results as $value) {
        foreach ($value as $result) {
            $keywords[] = $result;
        }
    }
    return $keywords;
}


function ee_multi_users_get_current_user_keywords() {
    $user_id = ee_multi_users_get_current_user_id();
    $keywords = ee_multi_users_get_user_keywords($user_id);
    return $keywords;
}

function ee_multi_users_get_current_user_id() {
    global $ydb;
    $username = YOURLS_USER;
    $table = YOURLS_DB_TABLE_USERS;
    $user = $ydb->get_results("select user_id from `$table` where `user_email` = '$username'");
    $user_id = $user[0]->user_id;
    return $user_id;
}

function ee_multi_users_get_user_id_by_keyword($keyword) {
    global $ydb;
    $table = YOURLS_DB_TABLE_URL_TO_USER;
    $results = $ydb->get_results("select users_user_id from `$table` where `url_keyword` = '$keyword'");
    $user_id = $results[0]->users_user_id;
    return $user_id;
}

function ee_multi_users_get_username_by_id($user_id) {
    global $ydb;
    $table = YOURLS_DB_TABLE_USERS;
    $results = $ydb->get_results("select user_email from `$table` where `user_id` = '$user_id'");
    $username = $results[0]->user_email;
    return $username;
}


function ee_multi_users_get_userid_by_username($username) {
    global $ydb;
    $table = YOURLS_DB_TABLE_USERS;
    $results = $ydb->get_results("select user_id from `$table` where `user_email` = '$username'");
    $username = $results[0]->user_id;
    return $username;
}

function ee_multi_users_count_clicks_by_keyword($keyword) {
    global $ydb;
    $table = YOURLS_DB_TABLE_LOG;
    $results = $ydb->get_results("select count(*) as count from `$table` where `shorturl` = '$keyword'");
    return $results[0]->count;
}


function ee_multi_users_get_click_by_userid($user_id) {
    global $ydb;
    $table = YOURLS_DB_TABLE_LOG;
    $keywords = ee_multi_users_get_user_keywords($user_id);
    $keywords = "'".implode("','", $keywords) . "'";
    $where = " WHERE shorturl IN ($keywords)";
    $results = $ydb->get_results("select count(*) as count from `$table`" . $where);
    return $results[0]->count;
}

function ee_multi_users_table_style() {
    $style = <<<EOT
    <style media="screen" type="text/css">

        table {
            width: 100%;
            /*border-collapse:separate;*/
            border:solid #e3f3ff 1px;
            border-radius:6px;
            -moz-border-radius:6px;
        }

        td, th {
            border-left:solid #e3f3ff 1px;
            border-top:solid #e3f3ff 1px;
            padding: 5px;
        }

        th {
            background-color: #e3f3ff;
            border-top: none;
        }

        td:first-child, th:first-child {
            border-left: none;
        }
        tr:hover {background-color: #f5f5f5;}
    </style>
EOT;
    echo $style;
}

?>
