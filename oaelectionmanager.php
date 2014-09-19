<?php
/*
 * Plugin Name: OA Election Manager
 * Plugin URI: https://github.com/oa-bsa/election-manager/
 * Description: Wordpress plugin to handle collection of data related to OA candidate elections
 * Version: 1.0
 * Author: Dave Miller
 * Author URI: http://twitter.com/justdavemiller
 * Author Email: github@justdave.net
 * */

/*
 * Copyright (C) 2014 David D. Miller
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

add_action( 'admin_menu', 'oaelectionmanager_plugin_menu' );
add_action( 'parse_request', 'oaelectionmanager_url_handler' );
add_action( 'plugins_loaded', 'oaelectionmanager_update_db_check' );
register_activation_hook( __FILE__, 'oaelectionmanager_install' );
register_activation_hook( __FILE__, 'oaelectionmanager_install_data' );
add_action( 'wp_enqueue_scripts', 'oaelectionmanager_enqueue_scripts' );
add_action( 'init', 'oaelectionmanager_plugin_updater_init' );

add_action( 'wp_ajax_oaem_submit_election', 'oaelectionmanager_ajax_submit_election' );
add_action( 'wp_ajax_nopriv_oaem_submit_election', 'oaelectionmanager_ajax_submit_election' ); // need this to serve non logged in users

function oaelectionmanager_enqueue_scripts() {
    wp_register_style( 'oaelectionmanager-style', plugins_url('style.css', __FILE__) );
    wp_enqueue_style( 'oaelectionmanager-style' );
    wp_enqueue_script( 'jquery' );
    wp_enqueue_script( 'jquery-ui-datepicker' );
    wp_enqueue_style('jquery-style', 'https://ajax.googleapis.com/ajax/libs/jqueryui/1.8.2/themes/smoothness/jquery-ui.css');
}

function oaelectionmanager_plugin_updater_init() {
    /* Load Plugin Updater */
    require_once( trailingslashit( plugin_dir_path( __FILE__ ) ) . 'includes/plugin-updater.php' );

    /* Updater Config */
    $config = array(
        'base'      => plugin_basename( __FILE__ ), //required
        'repo_uri'  => 'http://www.justdave.net/dave/',
        'repo_slug' => 'oaelectionmanager',
    );

    /* Load Updater Class */
    new OAElectionManager_Plugin_Updater( $config );
}

global $oaelectionmanager_db_version;
$oaelectionmanager_db_version = 1;

function oaelectionmanager_create_table($ddl) {
    global $wpdb;
    $table = "";
    if (preg_match("/create table\s+(\w+)\s/i", $ddl, $match)) {
        $table = $match[1];
    } else {
        return false;
    }
    foreach ($wpdb->get_col("SHOW TABLES",0) as $tbl ) {
        if ($tbl == $table) {
            return true;
        }
    }
    // if we get here it doesn't exist yet, so create it
    $wpdb->query($ddl);
    // check if it worked
    foreach ($wpdb->get_col("SHOW TABLES",0) as $tbl ) {
        if ($tbl == $table) {
            return true;
        }
    }
    return false;
}

function oaelectionmanager_install() {
    /* Reference: http://codex.wordpress.org/Creating_Tables_with_Plugins */

    global $wpdb;
    global $oaelectionmanager_db_version;

    $dbprefix = $wpdb->prefix . "oaem_";

    //
    // CREATE THE TABLES IF THEY DON'T EXIST
    //

    // This code checks if each table exists, and creates it if it doesn't.
    // No checks are made that the DDL for the table actually matches,
    // only if it doesn't exist yet. If the columns or indexes need to
    // change it'll need update code (see below).

    $sql = "CREATE TABLE ${dbprefix}district (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sortkey INT NOT NULL DEFAULT 0,
    name VARCHAR(120) NOT NULL DEFAULT '',
    UNIQUE(name)
    )";
    oaelectionmanager_create_table( $sql );

    $sql = "CREATE TABLE ${dbprefix}chapter (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sortkey INT NOT NULL DEFAULT 0,
    name VARCHAR(120) NOT NULL DEFAULT '',
    district_id INT NOT NULL,
    UNIQUE(name),
    FOREIGN KEY (district_id) REFERENCES ${dbprefix}district(id)
    )";
    oaelectionmanager_create_table( $sql );

    $sql = "CREATE TABLE ${dbprefix}troop (
    id INT PRIMARY KEY AUTO_INCREMENT,
    chapter_id INT NOT NULL,
    troopnum INT NOT NULL,
    leader_name VARCHAR(120),
    leader_phone VARCHAR(20),
    leader_email VARCHAR(250),
    meeting_location TEXT,
    UNIQUE(chapter_id,troopnum),
    FOREIGN KEY (chapter_id) REFERENCES ${dbprefix}chapter(id)
    )";
    oaelectionmanager_create_table( $sql );

    $sql = "CREATE TABLE ${dbprefix}election (
    id INT PRIMARY KEY AUTO_INCREMENT,
    troop_id INT NOT NULL,
    election_date DATE,
    submission_source CHAR(2) NOT NULL DEFAULT 'UE', /* or SM */
    reg_active INT,
    youth_present INT,
    num_eligible INT,
    ballots_returned INT,
    ballots_required INT,
    num_elected INT,
    additional_info TEXT,
    submitter_name VARCHAR(120),
    submitter_email VARCHAR(250),
    submitter_phone VARCHAR(20),
    UNIQUE(troop_id,submission_source),
    FOREIGN KEY (troop_id) REFERENCES ${dbprefix}troop(id)
    )";
    oaelectionmanager_create_table( $sql );

    $sql = "CREATE TABLE ${dbprefix}electedscout (
    id INT PRIMARY KEY AUTO_INCREMENT,
    election_id INT NOT NULL,
    firstname VARCHAR(120) NOT NULL,
    middlename VARCHAR(120) NOT NULL DEFAULT '',
    lastname VARCHAR(120) NOT NULL,
    bsa_member_id BIGINT NOT NULL,
    scout_email VARCHAR(250),
    parent_email VARCHAR(250),
    mailing_address1 VARCHAR(120),
    mailing_address2 VARCHAR(120),
    city VARCHAR(60) NOT NULL,
    state CHAR(2) NOT NULL,
    zip VARCHAR(10),
    birthdate DATE,
    phone VARCHAR(20),
    UNIQUE(election_id,bsa_member_id),
    FOREIGN KEY (election_id) REFERENCES ${dbprefix}election(id)
    )";
    oaelectionmanager_create_table( $sql );

    //
    // DATABSE UPDATE CODE
    //

    // Check the stored database schema version and compare it to the version
    // required for this version of the plugin.  Run any SQL updates required
    // to bring the DB schema into compliance with the current version.
    // If new tables are created, you don't need to do anything about that
    // here, since the table code above takes care of that.  All that needs
    // to be done here is to make any required changes to existing tables.
    // Don't forget that any changes made here also need to be made to the DDL
    // for the tables above.

    $installed_version = get_option("oaelectionmanager_db_version");
    if (empty($installed_version)) {
        // if we get here, it's a new install, and the schema will be correct
        // from the initialization of the tables above, so make it the
        // current version so we don't run any update code.
        $installed_version = $oaelectionmanager_db_version;
        add_option( "oaelectionmanager_db_version", $oaelectionmanager_db_version );
    }

    //if ($installed_version < 2) {
    //    # Add a column for the Last Audit Date field
    //    $wpdb->query("ALTER TABLE ${dbprefix}dues_data ADD COLUMN reg_audit_date DATE");
    //}

    // insert next database revision update code immediately above this line.
    // don't forget to increment $oaelectionmanager_db_version at the top of the file.

    if ($installed_version < $oaelectionmanager_db_version ) {
        // updates are done, update the schema version to say we did them
        update_option( "oaelectionmanager_db_version", $oaelectionmanager_db_version );
    }
}

function oaelectionmanager_update_db_check() {
    global $oaelectionmanager_db_version;
    if (get_site_option( 'oaelectionmanager_db_version' ) != $oaelectionmanager_db_version) {
        oaelectionmanager_install();
    }
    # do these here instead of in the starting data insert code because these
    # need to be created if they don't exist when the plugin gets upgraded,
    # too, not just on a new install.  add_option does nothing if the option
    # already exists, sets default value if it does not.
    add_option('oaelectionmanager_slug', 'oaelectionmanager');
}

function oaelectionmanager_install_data() {
    global $wpdb;
    $dbprefix = $wpdb->prefix . "oaem_";

}

function oaelectionmanager_url_handler( &$wp ) {
    $slugchunks = explode("/" , $wp->request);
    $parentslug = $slugchunks[0];
    $sectionslug = $slugchunks[1];

    if($parentslug == get_option('oaelectionmanager_slug')) {
        # http://stackoverflow.com/questions/17960649/wordpress-plugin-generating-virtual-pages-and-using-theme-template
        # Note that we don't need to do a template redirect as suggesting in
        # the example because all we do is load the template anyway. We can let
        # the real template code work like it's supposed to and only override
        # the content.
        switch ($sectionslug) {
            case "report":
                add_filter('the_posts', 'oaelectionmanager_uesubmit_page');
                break;
            case "smreport":
                add_filter('the_posts', 'oaelectionmanager_smsubmit_page');
                break;
            default:
                break;
        }
        remove_filter('the_content', 'wpautop');
    }
}

function oaelectionmanager_makedummypost() {
    // have to create a dummy post as otherwise many templates
    // don't call the_content filter
    global $wp, $wp_query;

    //create a fake post intance
    $p = new stdClass;
    // fill $p with everything a page in the database would have
    $p->ID = -1;
    $p->post_author = 1;
    $p->post_date = current_time('mysql');
    $p->post_date_gmt =  current_time('mysql', $gmt = 1);
    $p->post_content = 'You forgot to set the page content';
    $p->post_title = 'You forgot to set the page title';
    $p->post_excerpt = '';
    $p->post_status = 'publish';
    $p->ping_status = 'closed';
    $p->post_password = '';
    $p->post_name = $wp->request;
    $p->to_ping = '';
    $p->pinged = '';
    $p->modified = $p->post_date;
    $p->modified_gmt = $p->post_date_gmt;
    $p->post_content_filtered = '';
    $p->post_parent = 0;
    $p->guid = get_home_url('/' . $p->post_name); // use url instead?
    $p->menu_order = 0;
    $p->post_type = 'page';
    $p->post_mime_type = '';
    $p->comment_status = 'closed';
    $p->comment_count = 0;
    $p->filter = 'raw';
    $p->ancestors = array(); // 3.6

    // reset wp_query properties to simulate a found page
    $wp_query->is_page = TRUE;
    $wp_query->is_singular = TRUE;
    $wp_query->is_home = FALSE;
    $wp_query->is_archive = FALSE;
    $wp_query->is_category = FALSE;
    unset($wp_query->query['error']);
    $wp->query = array();
    $wp_query->query_vars['error'] = '';
    $wp_query->is_404 = FALSE;

    $wp_query->current_post = $p->ID;
    $wp_query->found_posts = 1;
    $wp_query->post_count = 1;
    $wp_query->comment_count = 0;
    // -1 for current_comment displays comment if not logged in!
    $wp_query->current_comment = null;
    $wp_query->is_singular = 1;

    $wp_query->post = $p;
    $wp_query->posts = array($p);
    $wp_query->queried_object = $p;
    $wp_query->queried_object_id = $p->ID;
    $wp_query->current_post = $p->ID;
    $wp_query->post_count = 1;

    return $p;
}

/*
 * ==========================
 *
 * AJAX handlers are defined
 * in the following functions
 *
 * ==========================
 */

function oaelectionmanager_ajax_submit_election() {
  check_ajax_referer("oaem_election_form"); // this is the name you gave the nonce
  $chapter = $_POST["chapter"];
  $troop = $_POST["troopnum"];

  ?>Got submission from Chapter <?php esc_html_e($chapter) ?> Troop <?php esc_html_e($troop)?>.<?php
  die(); // wordpress may print out a spurious zero without this
}

/*
 * ===================================
 *
 * User-facing page content is defined
 * in the following functions
 *
 * ===================================
 */

function oaelectionmanager_submit_page($source_type) {
    global $wpdb;
    $dbprefix = $wpdb->prefix . "oaem_";

    # initialize the page object
    $p = oaelectionmanager_makedummypost();
    $source_name = "INVALID SOURCE TYPE";
    switch ($source_type) {
        case "SM":
            $source_name = "Troop Leader";
            break;
        case "UE":
            $source_name = "Unit Election Team";
            break;
        default;
            break;
    }
    $p->post_title = "Election Results Submission by $source_name";

    $chapters = $wpdb->get_results("SELECT c.id AS id, c.name AS chapter_name, d.name AS district_name FROM ${dbprefix}chapter AS c LEFT JOIN ${dbprefix}district AS d ON c.district_id = d.id ORDER BY c.sortkey");
    $nonce = wp_create_nonce( 'oaem_election_form' );

    ob_start();
    /* page content goes here */
    ?>
    <script type='text/javascript'>
    <!--
jQuery(document).ready(function() {
    jQuery('#submit_election_button').click(function() {
        var formdata = jQuery('#oaem_election_form').serializeArray();
        formdata.push({name: 'action', value: 'oaem_submit_election'});
        formdata.push({name: '_ajax_nonce', value: '<?php echo $nonce; ?>'});
        /* form validation code should go here */
        jQuery.ajax({
            type: "post",
            url: "<?php esc_html_e(admin_url('admin-ajax.php')) ?>",
            data: formdata,
            beforeSend: function() {
                /* display a spinner here? */
                jQuery("#submit_election_button").fadeOut('fast');
            },
            success: function(html) {
                /* if we added a spinner above, make it go away here, probably add an error: hook for the same */
                jQuery("#response_area").html(html);
            }
        });
    });
    jQuery('#election_date').datepicker({
        yearRange: "-1:+0",
        showButtonPanel: true,
        changeMonth: true,
        changeYear: true,
        dateFormat: "mm/dd/yy",
    });
    jQuery('#NumberBallotsReturned').keyup(function() {
        var returned = jQuery('#NumberBallotsReturned').val();
        var required = Math.ceil(returned / 2);
        jQuery('#NumberRequired').val(required);
    });
});
--></script>
<form id="oaem_election_form">
<p>Chapter/District:<br>
<select id="chapter" name="chapter">
  <option value="">---</option><?php
    foreach ($chapters AS $chapter) {
      ?><option value="<?php esc_html_e($chapter->id) ?>"><?php esc_html_e($chapter->chapter_name . " (" . $chapter->district_name . ")") ?></option><?php
    }
?>
</select></p>
<p>Troop Number:<br>
<input type="number" id="troopnum" name="troopnum" value="" size="4"></p>
<p>What camp is the troop attending in 2014<br>
<input type="text" id="camp" name="camp" value="" size="40"></p>
<p>Date of Election (MM/DD/YYYY)<br>
<input type="date" id="election_date" name="election_date" value="" size="10"></p>
<p>Election Location (when and where does the unit meet?)<br>
<textarea id="MeetingLocation" name="MeetingLocation" cols="40" rows="10"></textarea></p>
<p>Number of Registered Active Youth (all youth active in the troop, whether or not they were present)<br>
<input type="number" id="RegActiveYouth" name="RegActiveYouth" value="" size="5"></p>
<p>Number of Youth Present<br>
<input type="number" id="YouthPresent" name="YouthPresent" value="" size="5"></p>
<p>Number of Scouts Eligible<br>
<input type="number" id="NumberEligible" name="NumberEligible" value="" size="5"></p>
<p>Number of Ballots Turned In<br>
<input type="number" id="NumberBallotsReturned" name="NumberBallotsReturned" value="" size="5"></p>
<p>Number of Votes  Required for Election (automatically calculated)<br>
<input type="number" id="NumberRequired" name="NumberRequired" value="" size="5" readonly="readonly"></p>
<p>Number of Youth Elected<br>
<input type="number" id="NumberElected" name="NumberElected" value="" size="5"></p>
<p>Unit Leader's Name<br>
<input type="text" id="UnitLeaderName" name="UnitLeaderName" value="" size="40"></p>
<p>Unit Leader's Phone Number<br>
<input type="tel" id="UnitLeaderPhone" name="UnitLeaderPhone" value="" size="40"></p>
<p>Unit Leader's Email Address<br>
<input type="email" id="UnitLeaderEmail" name="UnitLeaderEmail" value="" size="40"></p>
<p>Additional Information<br>
<textarea id="AdditionalInfo" name="AdditionalInfo" cols="40" rows="10"></textarea></p>
<p>Submitter's Name<br>
<input type="text" id="SubmitterName" name="SubmitterName" value="" size="40"></p>
<p>Submitter's Email Address<br>
<input type="email" id="SubmitterEmail" name="SubmitterEmail" value="" size="40"></p>
<p>Submitter's Phone Number<br>
<input type="tel" id="SubmitterPhone" name="SubmitterPhone" value="" size="40"></p>


<input id="submit_election_button" value="Submit" type="button">
</form>
<div id="response_area">
 This is where we'll get the response (for testing)
</div>
    <?php

    $p->post_content = ob_get_clean();
    return array($p);
}

function oaelectionmanager_uesubmit_page($post) {
    return oaelectionmanager_submit_page("UE");
}

function oaelectionmanager_smsubmit_page($post) {
    return oaelectionmanager_submit_page("SM");
}

/*
 * ====================================
 *
 * Admin-facing page content is defined
 * in the following functions
 *
 * ====================================
 */

function oaelectionmanager_plugin_menu() {
    add_options_page( 'OA Election Manager', 'OA Election Manager', 'manage_options', 'oaelectionmanager', 'oaelectionmanager_options' );
}

function oaelectionmanager_options() {

    global $wpdb;

    $dbprefix = $wpdb->prefix . "oaem_";
    $hidden_field_name = 'oaem_submit_hidden';

    if ( !current_user_can( 'manage_options' ) )  {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }

    // =========================
    // form processing code here
    // =========================


    //
    // HANDLE SETTINGS SCREEN UPDATES
    //

    if( isset($_POST[ $hidden_field_name ]) && $_POST[ $hidden_field_name ] == 'oaelectionmanager-settings') {

        $slug = $_POST['oaelectionmanager_slug'];

        # form validation code goes here
        /*
        if (!$formvalidated) {
            ?><div class="error"><p><strong>'<?php esc_html_e($help_email); ?>' is not a valid email address.</strong></p></div><?php
        } else {
        */

            $foundchanges = 0;
            $slug = sanitize_title($slug);
            if ($slug != get_option('oaelectionmanager_slug')) {
                update_option('oaelectionmanager_slug', $slug);
                $foundchanges = 1;
            }

            if ($foundchanges) {
                ?><div class="updated"><p><strong>Changes saved.</strong></p></div><?php
            }
        /*
        }
        */

    }

    // ============================
    // screens and forms start here
    // ============================

    //
    // MAIN SETTINGS SCREEN
    //

    echo '<div class="wrap">';

    // header

    echo "<h2>" . __( 'OA Election Manager Settings', 'oaelectionmanager' ) . "</h2>";

    // settings form

?>

<h3 style="border-bottom: 1px solid black;">Submission Page Settings</h3>
<form name="oaelectionmanager-settings" method="post" action="">
<input type="hidden" name="<?php echo $hidden_field_name; ?>" value="oaelectionmanager-settings">
<table class="form-table">
<tbody>
<tr>
  <th scope="row"><label for="oaelectionmanager_slug">Submission Page Slug</label></th>
  <td><code><?php echo esc_html(get_option("home")); ?>/</code><input id="oaelectionmanager_slug" name="oaelectionmanager_slug" class="regular-text code" type="text" value="<?php echo esc_html(get_option("oaelectionmanager_slug")); ?>">
  <p class="description">The name appended to your Site URL to reach the submission page.</p>
  </td>
</tr>
</tbody>
</table>
<p class="submit"><input id="submit" class="button button-primary" type="submit" value="Save Changes" name="submit"></p>
</form>
<?php

    echo "</div>";
} // END OF SETTINGS SCREEN

