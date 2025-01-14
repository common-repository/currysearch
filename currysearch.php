<?php
/*
Plugin Name: CurrySearch
Plugin URI:  https://www.curry-software.com/en/curry_search/
Description: CurrySearch is an better cloud-based search for WordPress. It supports custom post types, advanced autocomplete, relevance based results and filter.
Version:     1.6
Author:      CurrySoftware GmbH
Author URI:  https://www.curry-software.com/en/
Text Domain: currysearch
Domain Path: /languages
License:     GPL3
License URI: https://www.gnu.org/licenses/gpl-3.0.en.html

CurrySearch Official Plugin for WordPess
Copyright (C) 2018 CurrySoftware GmbH

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
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Get Plugin Path to
define('CURRYSEARCH_PLUGIN_PATH', plugin_dir_path(__FILE__));
// Load Constants
include_once(CURRYSEARCH_PLUGIN_PATH.'includes/cs-constants.php');
// And Utils
include_once(CURRYSEARCH_PLUGIN_PATH.'includes/cs-utils.php');
// And the Admin Page
include_once(CURRYSEARCH_PLUGIN_PATH.'includes/cs-admin.php');


register_activation_hook(__FILE__, array('CurrySearch', 'install'));
register_deactivation_hook( __FILE__, array('CurrySearch', 'uninstall' ));

// Hook to intercept queries
add_action('pre_get_posts', array('CurrySearch', 'intercept_query'));
add_action('get_search_form', array('CurrySearch', 'rewrite_searchform'));

add_action('admin_enqueue_scripts', array('CurrySearch', 'admin_enqueue_scripts' ));
add_action('wp_enqueue_scripts', array('CurrySearch', 'enqueue_scripts'));
add_action('plugins_loaded', array('CurrySearch', 'load_textdomain'));
add_action('admin_menu', array('CurrySearch', 'init_menu'));

// Hook for cron
add_action('currysearch_reindexing', array('CurrySearch', 'reindexing'));
add_action( 'admin_notices', array('CurrySearch', 'plan_warning') );


/**
 * The central static class in this plugin. All methods are static, nothing is to be instantiated!
 * It's tasks are plugin registration, indexing, keeping the index up to date
 * and intercepting search requests that are meant for the CurrySearch API.
 *
 */
class CurrySearch {

	public static $cs_query;

	static $options;

	/**
	 * Abstracts away the loading of options
	 */
	static function options() {
		if (!isset(CurrySearch::$options)) {
			CurrySearch::$options = get_option(CurrySearchConstants::OPTIONS, $default = false);
		}
		return CurrySearch::$options;
	}

	/**
     * Gets the api_key of the current wordpress installation.
	 */
	static function get_apikey() {
		return CurrySearch::options()['api_key'];
	}




	/**
	 * Gets the port to communicate to with the search system.
	 * This port is assigned after the first successfull indexing process.
	 * It lies between 36000 and 36099
	 *
	 * A nonexisten port indicates an unsuccessfull indexing process
	 */
	static function get_port() {
		return CurrySearch::options()['port'];
	}

	/**
	 * Gets the public portion (first 8bytes) of the api_key
	 * This is needed for autocomplete requests which are not handled by WordPress but
	 * the users browser.
	 */
	static function get_public_api_key() {
		$key = CurrySearch::get_apikey();
		return substr($key, 0, 16);
	}

	/**
	 * Time of last successfull indexing as determined by the CurrySearch System
	 */
	static function get_last_indexing() {
		return CurrySearch::options()['last_indexing'];
	}


	/**
	 * Currently selected plan. One of Free, Small, Medium, Large or Premium
	 */
	static function get_current_plan() {
		return CurrySearch::options()['plan'];
	}

	/**
	 * Check if the currently selected plan is sufficient for this wp instance
	 */
	static function current_plan_sufficient() {
		switch (CurrySearch::options()['plan']) {
			case "Free":
				return CurrySearch::get_indexed_documents() <= 50;

			case "Small":
				return CurrySearch::get_indexed_documents() <= 200;

			case "Medium":
				return CurrySearch::get_indexed_documents() <= 1000;

			case "Large":
				return CurrySearch::get_indexed_documents() <= 5000;

			case "Premium":
				return true;
		}
	}

	/**
	 * Language of the content as detected by the CurrySearch System
	 */
	static function get_detected_language() {
		return CurrySearch::options()['detected_language'];
	}

	/**
	 * Number of indexed documents as determined by the CurrySearch System
	 */
	static function get_indexed_documents() {
		return CurrySearch::options()['document_count'];
	}

	/**
	 * Registers the menu
	 */
	static function init_menu() {
		new CurrySearchAdminPage();
	}

	/**
	 * Calls the statistics backend and get an individual authentication token.
	 * Returns a full url
	 */
	static function backend_link() {
		$key = CurrySearch::get_apikey();
		$token = json_decode(CurrySearchUtils::call_stats(CurrySearchConstants::TOKEN_ACTION, $key, NULL));
		return "https://my.curry-search.com/auth?token=".$token;
	}

	/**
	 * Creates the link to the purchase site...
	 * Returns a full url
	 */
	static function purchase_link() {
		$key = CurrySearch::get_apikey();
		return "https://my.curry-search.com/kaufen/?apiKey=".$key;
	}

	/**
	 * Gets the status from the CurrySearch System
	 */
	static function get_status() {
		$status = json_decode(CurrySearchUtils::call_ms(
			CurrySearchConstants::STATUS_ACTION, CurrySearch::get_apikey(), NULL));
		$options = CurrySearch::options();
		$options['detected_language'] = $status->detected_language;
		$options['document_count'] = $status->document_count;
		$options['plan'] = $status->plan;
		$date = new DateTime();
		$options['last_indexing'] = $date->setTimestamp($status->last_indexing->secs_since_epoch);
		CurrySearch::$options = $options;
		update_option(CurrySearchConstants::OPTIONS, $options, /*autoload*/'yes');
	}

    /**
     *
     */
    static function reindexing() {
        CurrySearch::full_indexing();
    }

	/**
	 * Register and Enqueue JavaScripts and CSSs
	 *
	 * These are mainly for query autocompletion
	 */
	static function enqueue_scripts() {
		wp_register_script('cs-autocomplete.min.js',
						   plugins_url('public/js/cs-autocomplete.min.js', __FILE__));
		wp_register_style("currysearch.css",
						  plugins_url('public/css/currysearch.css',  __FILE__));

		wp_enqueue_script('cs-autocomplete.min.js');
		wp_enqueue_style('currysearch.css');
	}

	static function admin_enqueue_scripts($hook_suffix) {
		// first check that $hook_suffix is appropriate for your admin page
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'my-script-handle', plugins_url('public/js/cs-colorpicker.js', __FILE__ ), array( 'wp-color-picker' ), false, true );
	}

	static function load_textdomain() {
		load_plugin_textdomain('currysearch', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/');
	}

	static function plan_warning() {
		$settings = get_option(CurrySearchConstants::SETTINGS);
		if (isset($settings['show_plan_warning']) && $settings['show_plan_warning'] == false) {
			return;
		}
		if (CurrySearch::current_plan_sufficient()) {
			return;
		}
		$label = esc_html__('Zu viele Dokumente', 'currysearch');
		$link = CurrySearch::purchase_link();
		$upgradenote = esc_html__('Please upgrade your CurrySearch plan!', 'currysearch');
		$settingslab = esc_html__('CurrySearch Settings', 'currysearch');
		$dissmisslab = esc_html__('Dissmiss this warning', 'currysearch');
		$settingsurl = admin_url('options-general.php?page=currysearch');
		$dissmissurl = wp_nonce_url(admin_url('options-general.php?page=currysearch&dissmiss_warning=true'), 'dissmiss_warning');

		echo "
<div class='notice notice-warning is-dismissible'>
  <p><strong><span style='display: block; margin: 0.5em 0.5em 0 0; clear: both;'>
		  Too many documents: <a href='$link'>$upgradenote</a>
  </span></strong></p>
  <span style='display: block; margin: 0.5em 0.5em 0 0; clear: both;'>
    <a href='$settingsurl'>$settingslab</a> | <a href='$dissmissurl' class='dismiss-notice'>$dissmisslab</a></span>
</strong></p><button type='button' class='notice-dismiss'><span class='screen-reader-text'>Dissmiss this warning</span></button>
    </div>
";
	}

	/**
	 * A full indexing task.
	 *
	 * Gets all relevant Posts (published) and sends them to the API.
	 * Also registers tags and categories for later filtering
	 */
	static function full_indexing() {
		//Get ApiKey from options
		$key = CurrySearch::get_apikey();

		$settings = get_option(CurrySearchConstants::SETTINGS);
		$post_types = $settings['indexing_post_types'];

		//Get all posts
		//https://codex.wordpress.org/Template_Tags/get_posts
		if (!isset($post_types) || empty($post_types)) {
			$postlist = array();
		} else {
			$postlist = get_posts(array(
				'numberposts' => -1,
				'post_type' => $post_types,
			   	'post_status' => 'publish',
			));
		}


		// Get all potential childposts with post_status inherit
		// Get them, and then check if their parents are published
		if (!isset($post_types) || empty($post_types)) {
			$child_postlist = array();
		} else {
			$child_postlist = get_posts(array(
				'numberposts' => -1,
				'post_type' => $post_types,
			   	'post_status' => 'inherit',
			));
		}


		foreach ($child_postlist as $childpost) {
			if (get_post_status($childpost->post_parent) == 'publish') {
				array_push($postlist, $childpost);
			}
		}

		$published_posts = count($postlist);


		//Initiate indexing
		CurrySearchUtils::call_ms(
			CurrySearchConstants::INDEXING_START_ACTION, $key,
			array('collection_size' => $published_posts, 'url' => home_url()));

		//Chunk them into parts of 100
		$post_chunks = array_chunk($postlist, 100);

		//Register some fields that will be searched
		//Title, body, post_tag and category
		CurrySearchUtils::call_ms(CurrySearchConstants::REGISTER_FIELDS_ACTION, $key, array(
			array(
				'name' => 'title',
				'data_type' => 'Text',
				'field_type' => 'Search',
				'autocomplete_source' => true
			),
			array(
				'name' => 'body',
				'data_type' => 'Text',
				'field_type' => 'Search',
				'autocomplete_source' => true
			),
			array(
				'name' => 'post_tag',
				'data_type' => 'Number',
				'field_type' => 'Filter',
				'autocomplete_source' => false
			),
			array(
				'name' => 'category',
				'data_type' => 'Number',
				'field_type' => 'HierarchyFilter',
				'autocomplete_source' => false
			),
			array(
				'name' => 'category_text',
				'data_type' => 'Text',
				'field_type' => 'Search',
				'autocomplete_source' => true
			),
			array(
				'name' => 'post_tag_text',
				'data_type' => 'Text',
				'field_type' => 'Search',
				'autocomplete_source' => true
			))
		);

		// Register categories
		CurrySearch::register_hierarchy($key, 'category');
		$taxos = array('post_tag', 'category');

		$part_count = 0;
		//Index posts chunk by chunk
		foreach ($post_chunks as $chunk) {
			$posts = array();
			// For each post
			foreach ($chunk as $post) {
				$taxo_terms = array();
				// Get all its taxo terms (category and tag)
				foreach ($taxos as $taxo) {
					$taxo_terms[$taxo] = array();
					$taxo_terms[$taxo.'_text'] = array();
					$wp_terms = get_the_terms( $post->ID, $taxo );
					if (is_array($wp_terms) && count($wp_terms) > 0 ) {
						foreach( $wp_terms as $term) {
							array_push($taxo_terms[$taxo], $term->term_id);
							array_push($taxo_terms[$taxo.'_text'], $term->name);
						}
					}
				}

				// Add its title, contents and taxo terms to the processed chunk
				array_push($posts, array(
					'id' => $post->ID,
					'raw_fields' =>  array (
						array('title', html_entity_decode( strip_tags( $post->post_title), ENT_QUOTES, 'UTF-8')),
						// We could leave the tags. Then we would have more information during indexing...
						array('body', html_entity_decode(
							strip_tags( wp_strip_all_tags( $post->post_content ) ), ENT_QUOTES, 'UTF-8' )),
						array('post_tag', implode(' ', $taxo_terms['post_tag'])),
						array('category', implode(' ', $taxo_terms['category'])),
						array('category_text', implode(' ', $taxo_terms['category_text'])),
						array('post_tag_text', implode(' ', $taxo_terms['post_tag_text'])),
					)
				));
			}
			// Send chunk to the server
			CurrySearchUtils::call_ms(
				CurrySearchConstants::INDEXING_PART_ACTION, $key, array('posts' => $posts));
			$part_count += 1;
			$posts = array();
		}

		// Wrapping up... telling the API that we are finished
	 	$port = CurrySearchUtils::call_ms(
			CurrySearchConstants::INDEXING_DONE_ACTION, $key, array( 'parts' => $part_count ));

		$port = json_decode($port, true);

		$options = ['api_key' => $key, 'port' => $port];
		CurrySearch::$options = $options;
		update_option(CurrySearchConstants::OPTIONS, $options, /*autoload*/'yes');
	}

	/**
	 * This function is called from indexing.
	 * It registers a hierarchical taxonomy with the API.
	 */
	static function register_hierarchy($key, $taxo) {
		// Only register it as hierarchical if it really IS hierarchical
		if (is_taxonomy_hierarchical($taxo)) {
			$terms = get_terms( array(
				'taxonomy' => $taxo,
				'hide_empty' => false,
			));

			$cs_terms = array();
			// Get all (term_id, Option<parent_term_id>) pairs
			foreach($terms as $term) {
				if (isset($term->parent) && ($term->parent != 0)) {
					array_push($cs_terms, array($term->term_id, $term->parent));
				} else {
				    array_push($cs_terms, array($term->term_id, null));
				}
			}

			// And send them to the api.
			CurrySearchUtils::call_ms(
				CurrySearchConstants::REGISTER_HIERARCHY_ACTION, $key, array( $taxo, $cs_terms));
		}
	}


	/**
	 * Activation callback
	 *
	 * First it registers the index to the endpoint, then it indexes all posts.
	 */
	static function install() {
		//register index
		$api_key = CurrySearchUtils::call_ms(CurrySearchConstants::REGISTER_ACTION, NULL, NULL);
		$api_key = json_decode($api_key, true);

		$options = ['api_key' => $api_key];
		add_option(CurrySearchConstants::OPTIONS, $options, /*deprecated parameter*/'', /*autoload*/'yes');

		$settings = ['indexing_post_types' => array('post', 'page'),
					 'inject_autocomplete' => 'true', 'ac_colors' => ['#000', '#DDD', '#555', '#EEE']];
		add_option(CurrySearchConstants::SETTINGS, $settings, '', 'no');

        //Add daily cron to reindex
        if(!wp_next_scheduled('currysearch_reindexing')) {
            wp_schedule_event(time() + 86400, 'daily', 'currysearch_reindexing');
        }

		self::full_indexing();
	}

	/**
	 * Deactivation callback
	 *
     * Tell backend that we where deactivated, remove api-key from options!
	 */
	static function uninstall() {
		$port = CurrySearch::get_port();
		$key = CurrySearch::get_apikey();

		CurrySearchUtils::call_ms(CurrySearchConstants::DEACTIVATE_ACTION."/".$port, $key, NULL);

        $timestamp = wp_next_scheduled('currysearch_reindexing');
        wp_unschedule_event($timestamp, 'currysearch_reindexing');

		delete_option(CurrySearchConstants::OPTIONS);
	}


	/**
	 * Hooks into the standard searchform if autocomplete is activated.
	 * Adds an id to the search field, turns browser based autocomplete off and adds a bit of javascript and style
	 */
	static function rewrite_searchform($db_form) {
		$settings = get_option(CurrySearchConstants::SETTINGS, $default = false);
		if (!(isset($settings['inject_autocomplete']))) {
			return $db_form;
		} else {
			$form = "";
			if (false === ($form === get_transient(CurrySearchConstants::SEARCHFORMTRANSIENT))) {
				$form = $db_form;
				// Looks for an input field with the name="s" (the WordPress search parameter)
				if (preg_match('/<input[^>]*name="s"[^>]*\/>/', $form, $matches)) {
					// We found one
					$input_field = $matches[0];
					// Check if id is set in \"
					if (preg_match('/id="([^"]*)"/', $input_field, $id_matches)) {
						$id = $id_matches[1].uniqid();
						$input_field = str_replace('id="'.$id_matches[1].'"', 'id="'.$id.'"', $input_field);
					//Check if id is set in \'
					} else if (preg_match("/id='([^']*)'/", $input_field, $id_matches)) {
						$id = $id_matches[1].uniqid();
						$input_field = str_replace("id='".$id_matches[1]."'", "id='".$id."'", $input_field);
					}
					else {
						//There is no id. We have to set our own
						$id = 'curry-search-input'.uniqid();
						$input_field =str_replace('<input', '<input id="'.$id.'"', $input_field);
					}
					// Check if value is set
					if (!strpos('value="', $input_field)) {
						// If no value is set, we will set our own to the current query
						$input_field =str_replace('<input', '<input value="'.get_search_query().'"', $input_field);
					}
					// turn browser autocomplete off. We ship our own
					$input_field =str_replace('<input', '<input autocomplete="off"', $input_field);

					//Now replace the input field
					$form = preg_replace('/<input[^>]*name="s"[^>]*\/>/', $input_field, $form);
				} else {
					// We didnt find any search form... this probably means the theme does not have a searchform.
					// Anyways.. We will just create a blank one
					$id = 'curry-search-input_blank'.uniqid();
					$form = '<form method="get" action="' . esc_url( home_url( '/' ) ) . '">
<input value="'.get_search_query().'" id="'.$id.'" name="s" autocomplete="off" type="search" />
<input value="'.esc_html__("Search", "currysearch").'" type="submit"></form>';
				}
				// Keep it for one hour
				set_transient(CurrySearchConstants::SEARCHFORMTRANSIENT, $form, 3600);
			}
			$session_hash = CurrySearchUtils::get_session_hash();
			$public_api_key = CurrySearch::get_public_api_key();
			$url = CurrySearchConstants::APPLICATION_URL.':'.CurrySearch::get_port().'/'.CurrySearchConstants::QAC_ACTION;
			if (isset($settings['ac_colors'])) {
				$style=CurrySearchConstants::autocomplete_style($settings['ac_colors']);
			} else {
				$style = '';
			}
			return $form.$style.CurrySearchConstants::elm_hook($url, $public_api_key, $session_hash, $id);
		}
	}

	/**
	 * Callback for 'pre_get_posts'.
	 * Checks if we want to handle this particular search request
	 * If so, we create a 'CurrySearchQuery' object which will handle all the rest!
	 *
	 * Be sure to check out 'CurrySearchQuery' for details!
	 */
	static function intercept_query($query) {
		if (($query->is_search() || is_search())
			&& isset($query->query['s'])
			&& ($query->query['s'] !== '')
			&& ($query->is_admin != 1)) {
			self::$cs_query =
					  new CurrySearchQuery(
						  CurrySearch::get_apikey(),
						  CurrySearch::get_port(),
						  CurrySearchUtils::get_session_hash(),
						  $query->query['s'],
						  $query->get('paged'));
		}
	}
}


/**
 * The 'CurrySearchQuery' class handles all processes regarding query execution.
 *
 * During construction it registers some hooks (see CurrySearchQuery::setup())
 * and calls the endpoint to execute the query.
 *
 * These hooks have two basic purposes:
 * 1. Make sure, that WordPress does not execute the original query.
 * This is not strictly necessary but reduces pressure on the database and page load time.
 *
 * This is handled by CurrySearchQuery::posts_request and CurrySearchQuery::found_posts_query
 *
 * 2. Retrieve the ids of all relevant posts for the specified page from the endpoint.
 * Then hand all these ids to a WP_Query object, execute it and return the resulting posts.
 *
 * API communication happens in the constructor (see CurrySearchQuery::execute).
 * The posts are fetched from the database and returned to the usual WordPress flow in 'CurrySearch::posts_results'
 *
 * It is worth noting, that the endpoint only returns the necessary ids (the ones visible on the specified page)
 * together with a total result count.
 * To enable pagination nevertheless, we also hook 'found_posts' and return this total count.
 *
 *
 * As soon as we injected our results we can tear down all the hooks again
 * to allow subsequent querys to run without disturbance.
 */
class CurrySearchQuery{

	/** Needed for communication with the api */
	private $api_key;
	/** Needed for communicating with the correct server */
	private $port;
	/** stores the original query string */
	private $query;
	/** contains the ids of all relevant posts */
	private $query_result;
	/** Total number of relevant posts for this query */
	private $result_count;
	/** The page we are currently on. Starts at 1!*/
	private $page;
	/** The session hash for this request*/
	private $hash;

	/**
	 * Constructor.
     * Sets up all the hooks and gets the query result from the api-endpoint.
     */
	function __construct($api_key, $port, $hash, $query, $page) {
		$this->hash = $hash;
		$this->api_key = $api_key;
		$this->query = $query;
		$this->port = $port;
		if ($page === 0) {
			$this->page =  1;
		} else {
			$this->page = $page;
		}
		// Setup hooks
		$this->setup();
		// Run query against API
		$this->execute();
	}

	/**
	 * Sets up all necessary hooks.
	 */
	function setup() {
		add_filter("posts_request", array( $this, "posts_request"));
		add_filter("found_posts", array( $this, "found_posts"));
		add_filter("posts_results", array( $this, "posts_results"));
		add_filter("found_posts_query", array( $this, "found_posts_query"));
	}

	/**
	 * Tears down all the necessary hooks again for subsequent queries to be undisturbed from our plugin!
	 */
	function tear_down() {
		remove_filter("posts_request", array( $this, "posts_request"));
		remove_filter("found_posts", array( $this, "found_posts"));
		remove_filter("posts_results", array( $this, "posts_results"));
		remove_filter("found_posts_query", array( $this, "found_posts_query"));
	}

	/**
	 * Call the api-endpoint and retrieve relevant posts and total result count
     */
	function execute() {
		$query_args = array();

		// Get read the filterarguments
		// For non hierarchical taxonomies more than one term can be filtered
		foreach($_GET as $k=>$v) {
			if (preg_match('/cs_(\w+)_(\d+)/', $k, $matches)) {
				// If we already have filters for that taxonomy
				if (isset($query_args['filter'][$matches[1]]) &&
					is_array($query_args['filter'][$matches[1]])) {
					// Add the term
					array_push($query_args['filter'][$matches[1]], $v);
				} else {
					// Otherwise create a new array
					$query_args['filter'][$matches[1]] = array($v);
				}
			}
		}
		$query_args['value'] = $this->query;
		$query_args['page'] = (int)$this->page;
		$query_args['page_size'] = (int)get_option('posts_per_page');

		// Start the request
		$response =	CurrySearchUtils::call_as(
				CurrySearchConstants::SEARCH_ACTION, $this->port, $this->api_key, $this->hash, $query_args);

		// Parse the response
		$decoded = json_decode($response, true);
		$this->query_result = $decoded['posts'];
		$this->result_count = $decoded['estimated_count'];
	}


	/**
	 * Callback for 'posts_results'.
	 *
	 * Inject our results into the original WP_Query.
	 * This is done by creating a new WP_Query that only retrieves the relevant posts by id.
	 * Then return these posts.
	 */
	function posts_results($posts) {
		//last hook for this request. Remove actions
		$this->tear_down();
		$query = new WP_Query(array(
			'post__in' => $this->query_result
		));
		return $this->query_result;
	}

	/**
	 * Callback for 'found_posts'.
	 *
	 * Injects our result_count into the original WP_Query.
	 */
	function found_posts($found_posts=0) {
		return $this->result_count;
	}

	/**
	 * Callback for 'posts_request',
	 *
	 * Prevent WordPress from executing its query.
	 */
	static function posts_request($sqlQuery) {
		return '';
	}

	/**
	 * Callback for 'found_posts_query'
	 *
	 * Prevent WordPress from executing its query.
	 */
	static function found_posts_query($query) {
	    return '';
	}

}
