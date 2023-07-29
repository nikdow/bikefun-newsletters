<?php

/**
 * Plugin Name: Bike Fun Newsletter
 * Plugin URI: http://www.cbdweb.net
 * Description: Send email to subscribers
 * Version: 1.0
 * Author: Nik Dow, CBDWeb
 * License: GPL2
 */
/*
 * Newsletters
 */

use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\RFCValidation;

require __DIR__ . '/vendor/autoload.php';
function bf_newsletter_admin_scripts( $hook = "" ) {
    global $post;
    if( $post->post_type !== 'bf_newsletter' && "bf_newsletter_options" != $hook ) return;
    wp_register_script( 'angular1', "https://ajax.googleapis.com/ajax/libs/angularjs/1.4.5/angular.min.js", 'jquery' );
    wp_register_script( 'ui-bootstrap', plugins_url( 'js/ui-bootstrap-tpls-1.1.0.min.js', __FILE__ ), 'angular' );
    wp_register_script( 'newsletter-admin', plugins_url( 'js/newsletter-admin.js' , __FILE__ ), 'angular' );
    wp_enqueue_script('angular1');
    wp_enqueue_script('ui-bootstrap');
    wp_localize_script( 'newsletter-admin', '_main',
        array( 'post_url' => admin_url('post.php'),
               'ajax_url' => admin_url('admin-ajax.php'),
        ) 
    );
    wp_enqueue_script( 'newsletter-admin' );
    wp_enqueue_style('newsletter_style', plugins_url( 'css/admin-style.css' , __FILE__ ) );
}
add_action( 'admin_enqueue_scripts', 'bf_newsletter_admin_scripts' );

add_action( 'init', 'create_bf_newsletter' );
function create_bf_newsletter() {
	$labels = array(
        'name' => _x('Newsletters', 'post type general name'),
        'singular_name' => _x('Newsletter', 'post type singular name'),
        'add_new' => _x('Add New', 'events'),
        'add_new_item' => __('Add New Newsletter'),
        'edit_item' => __('Edit Newsletter'),
        'new_item' => __('New Newsletter'),
        'view_item' => __('View Newsletter'),
        'search_items' => __('Search Newsletter'),
        'not_found' =>  __('No newsletters found'),
        'not_found_in_trash' => __('No newsletters found in Trash'),
        'parent_item_colon' => '',
    );
    register_post_type( 'bf_newsletter',
        array(
            'label'=>__('Newsletters'),
            'labels' => $labels,
            'description' => 'Each post is one newsletter.',
            'public' => true,
            'can_export' => true,
            'exclude_from_search' => false,
            'has_archive' => true,
            'show_ui' => true,
            'capability_type' => 'post',
            'menu_icon' => "dashicons-megaphone",
            'hierarchical' => false,
            'rewrite' => false,
            'supports'=> array('title', 'editor' ) ,
            'show_in_nav_menus' => true,
        )
    );
}
/*
 * specify columns in admin view of signatures custom post listing
 */
add_filter ( "manage_edit-bf_newsletter_columns", "bf_newsletter_edit_columns" );
add_action ( "manage_posts_custom_column", "bf_newsletter_custom_columns" );
function bf_newsletter_edit_columns($columns) {
    $columns = array(
        "cb" => "<input type=\"checkbox\" />",
        "title" => "Subject",
    );
    return $columns;
}
function bf_newsletter_custom_columns($column) {
    global $post;
    switch ( $column ) {
        case "title":
            echo $post->post_title;
            break;
        }
}
/*
 * Add fields for admin to edit newsletter custom post
 */
add_action( 'admin_init', 'bf_newsletter_create' );
function bf_newsletter_create() {
    add_meta_box('bf_newsletter_meta', 'Newsletter', 'bf_newsletter_meta', 'bf_newsletter' );
}
function bf_newsletter_meta() {
    global $post;
    
    echo '<input type="hidden" name="bf-newsletter-nonce" id="bf-newsletter-nonce" value="' .
        wp_create_nonce( 'bf-send-newsletter-nonce' ) . '" />';
    ?>
    
    <div class="bf-meta" ng-app="newsletterAdmin" ng-controller="newsletterAdminCtrl">
            <ul>
                <li>Test addresses (leave blank to send bulk):</li>
                <li><input class='wide' name='bf_newsletter_test_addresses'/></li>
                <li><button type="button" ng-click="sendNewsletter()">Send newsletter</button></li>
                <li ng-show="showLoading"><img src="<?php echo get_site_url();?>/wp-includes/js/thickbox/loadingAnimation.gif"></li>
                <li ng-show='showProgressMessage'>
                    {{email.message}}
                </li>
                <li ng-show='showProgressNumber || showProgressMessage'>
                    <uib-progressbar max="email.total" value="email.count"><span style="white-space:nowrap;">{{email.count}} / {{email.total}}</span></uib-progressbar>
                </li>
            </ul>
    <input name='ajax_id' value="<?=$post->ID?>" type="hidden" />
    <?=wp_nonce_field( 'bf_sendNewsletter', 'bf-sendNewsletter', false, false );?>
    <input name='bf_newsletter_send_newsletter' value='0' type='hidden' />
    <?php    
}

add_action ('save_post', 'save_bf_newsletter');
 
function save_bf_newsletter(){
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    
    global $post;
    
    if( 'bf_newsletter' === $_POST['post_type'] ) {

    // - still require nonce

        if ( !wp_verify_nonce( $_POST['bf-newsletter-nonce'], 'bf-send-newsletter-nonce' )) {
            return $post->ID;
        }

        if ( !current_user_can( 'edit_post', $post->ID ))
            return $post->ID;

        // - convert back to unix & update post
        $sendTo = [];
        if( isset( $_POST['bf_newsletter_send_newsletter']) && $_POST[ 'bf_newsletter_send_newsletter' ] === '1' ) {
            
            $test_addresses = $_POST['bf_newsletter_test_addresses'];
            session_write_close (); // avoid session locking blocking progess ajax calls
            update_post_meta($post->ID, "bf_newsletter_progress", json_encode( array ( 'count'=>0, 'total'=>Count( $sendTo ), 'message'=>'querying the database' ) ) );
                    
            if( $test_addresses !== "" ) {
                
                $addressArray = explode(",", $test_addresses );
                $sendTo = array();
                foreach ( $addressArray as $address ) {
                    $sendTo[] = (object) array("name"=>"", "email"=>trim( $address ) );
                }
                
            } else {
                
                $already_sent = get_post_meta( $post->ID, 'bf_newsletter_sent', true );
                if ( "1" === $already_sent ) {
                    echo json_encode( array ( 'error'=>'Newsletter already sent once, can\'t send again' ) );
                    die;
                }
               
                global $wpdb;

                $query = $wpdb->prepare ( 
                    "SELECT p.post_title as email "
                    . "FROM " . $wpdb->posts . " p" .
                    " WHERE p.post_type='bf_subscription' AND p.`post_status`='private'", array() );
                $sendTo = $wpdb->get_results ( $query );
            }
            $testing = false; // true on dev computer - not the same as test addresses from UI
            $count =0;
            $console = new wpdb( DB_USER, DB_PASSWORD, 'console', 'DB_HOST' );
            $fromParam = [];
            $fromAddrStr = get_option('newsletter-sender-address');
            $validator = new EmailValidator();
            if( ! $validator->isValid($fromAddrStr, new RFCValidation())){
                echo json_encode(array('error'=>'sender address is invalid'));
                die;
            }
            $fromParam[$fromAddrStr] = get_option('bf-organisation');
            foreach ( $sendTo as $one ) {
                $email = trim($one->email);
                if( $email === "") continue;
                if ( $testing ) $email = "nik@cbdweb.net";
                if( ! $validator->isValid( $email, new RFCValidation() )) continue;

                $message = str_replace( "%email%", $email, $post->post_content );
                $message = str_replace("\r\n", "<br/>\r\n", $message );

                $subject = $post->post_title;
                if ( $testing ) $subject .= " - " . $one->email;
/*                $headers = array();
                $headers[] = 'From: "' . get_option('bf-organisation') . '" <' . get_option('newsletter-sender-address') . '>';
                $headers[] = "Content-type: text/html";
                $message = str_replace( "%email%", $email, $post->post_content );
                $message = str_replace("\r\n", "<br/>\r\n", $message );
                wp_mail( $email, $subject, $message, $headers );*/

                $params = [
                    'dummy' => false,
                    'fromParam' => $fromParam,
                    'mailto' => $email,
                    'subject' => $subject,
                    'textmessagebody' => 'Your email reader is not able to display this rich-text email',
                    'htmlmessagebody' => $message,
                ];
                if( ! $console->insert('email_queue', ['json'=>json_encode($params), 'domain'=>'bikefun' ]) ){
                    error_log('Error inserting to email_queue ' . $email );
                }
                $count++;
                update_post_meta($post->ID, "bf_newsletter_progress", json_encode( array ( 
                    'count'=>$count, 'total'=>Count( $sendTo ),
                    'message'=>'last email sent: ' . $email,
                ) ) );
                if ( $testing && $count > 5 ) break;
            }
            echo json_encode( array ( "success"=>"completed: " . $count . " emails" ) );
            if( ! $testing && $test_addresses === "" ) {
                update_post_meta ( $post->ID, "bf_newsletter_sent", "1" );
            }
            die;
        }
    }
}

add_action( 'wp_ajax_bf_newsletter_progress', 'bf_newsletter_progress' );
function bf_newsletter_progress() {
    $post_id = $_POST['post_id'];
    echo get_post_meta( $post_id, 'bf_newsletter_progress', true );
    die;
}

add_filter('post_updated_messages', 'newsletter_updated_messages');
 
function newsletter_updated_messages( $messages ) {
 
  global $post, $post_ID;
 
  $messages['bf_newsletter'] = array(
    0 => '', // Unused. Messages start at index 1.
    1 => sprintf( __('Newsletter updated. <a href="%s">View item</a>'), esc_url( get_permalink($post_ID) ) ),
    2 => __('Custom field updated.'),
    3 => __('Custom field deleted.'),
    4 => __('Newsletter updated.'),
    /* translators: %s: date and time of the revision */
    5 => isset($_GET['revision']) ? sprintf( __('Newsletter restored to revision from %s'), wp_post_revision_title( (int) $_GET['revision'], false ) ) : false,
    6 => sprintf( __('Newsletter published. <a href="%s">View Newsletter</a>'), esc_url( get_permalink($post_ID) ) ),
    7 => __('Newsletter saved.'),
    8 => sprintf( __('Newsletter submitted. <a target="_blank" href="%s">Preview newsletter</a>'), esc_url( add_query_arg( 'preview', 'true', get_permalink($post_ID) ) ) ),
    9 => sprintf( __('Newsletter scheduled for: <strong>%1$s</strong>. <a target="_blank" href="%2$s">Preview newsletter</a>'),
      // translators: Publish box date format, see http://php.net/date
      date_i18n( __( 'M j, Y @ G:i' ), strtotime( $post->post_date ) ), esc_url( get_permalink($post_ID) ) ),
    10 => sprintf( __('Newsletter draft updated. <a target="_blank" href="%s">Preview newsletter</a>'), esc_url( add_query_arg( 'preview', 'true', get_permalink($post_ID) ) ) ),
  );
 
  return $messages;
}
/*
 * label for title field on custom posts
 */

add_filter('enter_title_here', 'bf_newsletter_enter_title');
function bf_newsletter_enter_title( $input ) {
    global $post_type;

    if ( 'bf_newsletter' === $post_type ) {
        return __( 'Newsletter (email) subject' );
    }
    return $input;
}
/*
 * create the newsletter from templates and event data
 */

add_filter( 'default_content', 'newsletter_content', 10, 2 );

function newsletter_content( $content, $post ) {

    if( $post->post_type !== "bf_newsletter") {
        return $content;
    }
        
    $header = get_option( 'newsletter-header-template' );
    $footer = get_option( 'newsletter-footer-template' );

    $today = date("jS F Y");
    $unsubscribe = add_query_arg ( "email", "%email%", get_permalink( get_page_by_title( 'Unsubscribe' ) ) );
    
    $content = str_replace( 
                array( '{today}',
                    '{unsubscribe}',
                    ),
                array( $today,
                    $unsubscribe,
                    ),
                $header );
            
    global $wpdb;
    $now = time();

    $query = $wpdb->prepare ( 
        "SELECT p.post_title, p.ID, p.post_content, pms.meta_value as startdate from " . $wpdb->posts . " p" .
        " LEFT JOIN " . $wpdb->postmeta . " pms ON pms.post_id=p.ID AND pms.meta_key='bf_events_startdate'" .
        " LEFT JOIN " . $wpdb->postmeta . " pme ON pme.post_id=p.ID AND pme.meta_key='bf_events_enddate'" .
        " WHERE p.post_type='bf_events' AND p.`post_status`='publish' AND pme.meta_value > " . $now .
        " ORDER BY startdate ASC", array()
    );
    error_log( $query );
    $rows = $wpdb->get_results ( $query );
    
    
    $eventTemplate = get_option( 'newsletter-event-template' );
    
    foreach ( $rows as $row ) {
        $custom = get_post_custom( $row->ID );
        $meta_sd = $custom["bf_events_startdate"][0];
        $meta_ed = $custom["bf_events_enddate"][0];
        $startDT = new DateTime();
        $startDT->setTimestamp( $meta_sd );
        $startDT->setTimezone( new DateTimeZone ( get_option( 'timezone_string' ) ) );
        $endDT = new DateTime();
        $endDT->setTimestamp( $meta_ed );
        $endDT->setTimezone( new DateTimeZone ( get_option( 'timezone_string' ) ) );
        
        $meta_place = $custom["bf_events_place"][0];
        $meta_url = $custom["bf_events_url"][0];
        
        $time_format = get_option('time_format');
        
        $clean_sd = $startDT->format( "D, d M Y" );
        $clean_ed = $endDT->format( "D, d M Y" );
        $clean_st = $startDT->format( $time_format );
        $clean_et = $endDT->format( $time_format );
        if( $clean_ed === $clean_sd ) $clean_ed = "";
        
        $if_url = $meta_url ? "display: block;" : "display: none;";
        $permalink = get_permalink( $row->ID );
        
        $content .= str_replace( 
                array( '{post_title}',
                    '{start_date}',
                    '{start_time}',
                    '{end_date}',
                    '{end_time}',
                    '{place}',
                    '{thumbnail}',
                    '{description}',
                    '{if_url}',
                    '{url}',
                    '{permalink}',
                    '{post_id}'
                    ),
                array( $row->post_title,
                    $clean_sd,
                    $clean_st,
                    $clean_ed,
                    $clean_et,
                    $meta_place,
                    get_the_post_thumbnail( $row->ID, 'medium'),
                    $row->post_content,
                    $if_url,
                    $meta_url,
                    $permalink,
                    $row->ID,
                    ),
                $eventTemplate);
    }
    
    $content .= str_replace( 
                array( '{today}',
                    '{unsubscribe}',
                    ),
                array( $today,
                    $unsubscribe,
                    ),
                $footer
            );
    
    return $content;
}

add_action( 'admin_menu', 'newsletter_menu' );

/** Step 1. */
function newsletter_menu() {
        add_submenu_page( 'edit.php?post_type=bf_newsletter', 'Newsletter Options', 'Options', 'manage_options', basename(__FILE__), 'newsletter_options' );
}

/** Step 3. */
function newsletter_options() {
        bf_newsletter_admin_scripts( "bf_newsletter_options" ); // load the admin CSS
	if ( !current_user_can( 'manage_options' ) )  {
		wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	}

            // variables for the field and option names 
            $hidden_field_name = 'bf_submit_hidden';
            $options_array = array ( 
                array('opt_name'=>'newsletter-sender-name', 'data_field_name'=>'newsletter_sender-name', 
                    'opt_label'=>'Newsletter sender (common name)', 'field_type'=>'text'),
                array('opt_name'=>'newsletter-sender-address', 'data_field_name'=>'newsletter-sender-address', 
                    'opt_label'=>'Newsletter sender (email address)', 'field_type'=>'email'),
                array('opt_name'=>'newsletter-header-template', 'data_field_name'=>'newsletter-header-template',
                    'opt_label'=>"HTML header for newsletter - prepended to events list:", 'field_type'=>'textarea'),
                array('opt_name'=>'newsletter-event-template', 'data_field_name'=>'newsletter-event-template',
                    'opt_label'=>"HTML template for each event in newsletter:", 'field_type'=>'textarea' ),
                array('opt_name'=>'newsletter-footer-template', 'data_field_name'=>'newsletter-footer-template',
                    'opt_label'=>"HTML footer for newesletter - appended to events list:", 'field_type'=>'textarea'),
            );

            // See if the user has posted us some information
            // If they did, this hidden field will be set to 'Y'
            if( isset($_POST[ $hidden_field_name ]) && $_POST[ $hidden_field_name ] == 'Y' ) {

                foreach ($options_array as $option_array ) {
                    
                    // Read their posted value
                    $opt_val = stripslashes_deep ( $_POST[ $option_array['data_field_name'] ] );

                    // Save the posted value in the database
                    update_option( $option_array ['opt_name'], $opt_val );
                }

                // Put an settings updated message on the screen

                ?>
                <div class="updated"><p><strong><?php _e('settings saved.' ); ?></strong></p></div>
            <?php }

            // Now display the settings editing screen
            ?>
            <div class="wrap">

            <h2>Newsletter Settings</h2>

            <form name="newsletter_options" id="newsletter_options" method="post" action="">
                <input type="hidden" name="<?php echo $hidden_field_name; ?>" value="Y">

                <?php 
                foreach ( $options_array as $option_array ) { 
                    // Read in existing option value from database
                    $opt_val = get_option( $option_array[ 'opt_name' ] );
                    ?>
                    <p><?php _e( $option_array[ 'opt_label' ] );
                        if($option_array[ 'field_type' ] === 'textarea' ) { ?>
                            <textarea name="<?php echo $option_array[ 'data_field_name' ]; ?>"><?php echo $opt_val; ?></textarea>
                        <?php } else { ?>
                            <input type="<?=$option_array[ 'field_type' ]?>" name="<?=$option_array[ 'data_field_name' ]?>" value="<?=$opt_val?>"/>
                        <?php } ?>
                    </p>
                <?php } ?>
                <hr />

                <p class="submit">
                <input type="submit" name="Submit" class="button-primary" value="<?php esc_attr_e('Save Changes') ?>" />
                </p>

            </form>
        </div>
    <?php
}
/*
 * Add link to menu bar for newsletters
 */
add_action( 'admin_bar_menu', 'toolbar_newsletter_link', 999 );
function toolbar_newsletter_link( $wp_admin_bar ) {
    $args = array ( 
        'id'=>'bf-newsletter',
        'title'=>'Newsletters',
        'parent'=>'site-name',
        'href'=>get_site_url() . '/wp-admin/edit.php?post_type=bf_newsletter',
    );

    $wp_admin_bar->add_node( $args );
}
