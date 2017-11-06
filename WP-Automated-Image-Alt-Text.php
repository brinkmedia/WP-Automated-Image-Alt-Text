<?php
/*
Plugin Name: WP Automated Image Alt Text
Plugin URI: http://brink.com/
Description: Auto generate tags, alt-texts with the power of Machine Learning and Google Vision API
Author: BRINKmedia, Inc
Version: 1.0
Author URI: http://brink.com
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

require 'vendor/autoload.php';

class TagGenSettingsPage
{
    /**
     * Holds the values to be used in the fields callbacks
     */
    private $options;

    /**
     * Start up
     */
    public function __construct()
    {
        add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
        add_action( 'admin_init', array( $this, 'page_init' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );

        //save uploaded settings file
        if(isset($_FILES['tag_gen_option_json']) && $_FILES['tag_gen_option_json']['type'] == "application/json"){
            if (!move_uploaded_file($_FILES["tag_gen_option_json"]["tmp_name"], __DIR__ . '/cred/wp-tag-generator.json')) {
                echo "Sorry, there was an error uploading your file.";
            }
        }


    }

    public function admin_notices()
    {
        if(!file_exists(__DIR__ . '/cred/wp-tag-generator.json'))
        {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e( '<strong>Tag Generator Service is not fully activated. Please check it\'s config: <a href="/wp-admin/options-general.php?page=tag-gen-setting-admin">Settings → Tag Generator</a></strong>', 'sample-text-domain' ); ?></p>
            </div>
            <?php
        }
    }

    /**
     * Add options page
     */
    public function add_plugin_page()
    {
        // This page will be under "Settings"
        add_options_page(
            'Settings Admin',
            'Tag Generator',
            'manage_options',
            'tag-gen-setting-admin',
            array( $this, 'create_admin_page' )
        );
    }

    /**
     * Options page callback
     */
    public function create_admin_page()
    {
        // Set class property
        $this->options = get_option( 'tag_gen_option_name' );
        ?>
        <div class="wrap">
            <h1>Tag Generator Settings</h1>
            <form method="post" action="" enctype="multipart/form-data">
            <?php
                // This prints out all hidden setting fields
                settings_fields( 'tag_gen_option_group' );
                do_settings_sections( 'tag-gen-setting-admin' );
                submit_button();
            ?>
            </form>
        </div>
        <?php
    }

    /**
     * Register and add settings
     */
    public function page_init()
    {
        register_setting(
            'tag_gen_option_group', // Option group
            'tag_gen_option_name', // Option name
            array( $this, 'sanitize' ) // Sanitize
        );

        add_settings_section(
            'setting_section_id', // ID
            'Tag Generator Settings', // Title
            array( $this, 'print_section_info' ), // Callback
            'tag-gen-setting-admin' // Page
        );

        add_settings_field(
            'google_api_credentials', // ID
            'Google API Credentials', // Title
            array( $this, 'google_api_credentials_callback' ), // Callback
            'tag-gen-setting-admin', // Page
            'setting_section_id' // Section
        );
    }

    /**
     * Sanitize each setting field as needed
     *
     * @param array $input Contains all settings fields as array keys
     */
    public function sanitize( $input )
    {
        $new_input = array();
        if( isset( $input['id_number'] ) )
            $new_input['id_number'] = absint( $input['id_number'] );

        if( isset( $input['title'] ) )
            $new_input['title'] = sanitize_text_field( $input['title'] );

        return $new_input;
    }

    /**
     * Print the Section text
     */
    public function print_section_info()
    {
        print 'Enter your settings below:';

        if(file_exists(__DIR__ . '/cred/wp-tag-generator.json'))
        {
            echo "<pre class='margin: 2em 0;'>" . file_get_contents(__DIR__ . '/cred/wp-tag-generator.json') . "</pre>";
        }
    }

    public function google_api_credentials_callback()
    {
        printf('<input type="file" id="json_file" name="tag_gen_option_json" value="%s" />', '');
    }
}

class TagGenInjector
{
    protected $options;
    protected $visionClient;

    public function __construct()
    {
        if (!file_exists(__DIR__ . '/cred/wp-tag-generator.json'))
            return;

        //google api keys
        $this->connectGoogleVisionClient();
        add_action('add_attachment', array( $this, 'init_attachment'));
    }

    public function init_attachment($postId)
    {
        if(wp_attachment_is_image($postId))
        {
            $imgPath = get_attached_file($postId);
            update_post_meta($postId, '_wp_attachment_image_alt', $this->getImageAltText($imgPath));
        }
    }

    public function getImageAltText($path)
    {
        $tags = $this->getPhotoData($path);
        return implode(', ', $tags);
    }

    protected function getPhotoData($path)
    {
        if (!file_exists($path))
        {
            die("Upload file not found");
        }

        $image = $this->visionClient->image(file_get_contents($path), array('LABEL_DETECTION'));
        $annotation = $this->visionClient->annotate($image);

        $tags = array();
        if(!is_null($annotation->labels())){
            foreach ($annotation->labels() as $label) {
                if($label->score() > 0.8)
                {
                    $tags[] = $label->description();
                }
            }
        }

        return $tags;
    }

    protected function connectGoogleVisionClient()
    {
        $this->visionClient = new Google\Cloud\Vision\VisionClient(array(
           'keyFilePath' => __DIR__ . '/cred/wp-tag-generator.json'
        ));

        return $this->visionClient;
    }
}

if( is_admin() ) {
    $tag_gen_settings_page = new TagGenSettingsPage();
}

$tagGen = new TagGenInjector();
