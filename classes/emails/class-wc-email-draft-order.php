<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Email_Draft_Order extends WC_Email {

    public function __construct() {
        $this->id             = 'wc_draft_order_notification';
        $this->title          = __('Draft Order Notification', 'woocommerce');
        // Paths
            // Paths
        $theme_template_path = get_stylesheet_directory() . '/woocommerce/emails/admin-draft-order.php';
        $plugin_template_path = plugin_dir_path(dirname(__DIR__)) . 'templates/emails/admin-draft-order.php';

        // Check if the template exists in the theme
        if (!file_exists($theme_template_path)) {
            // Create directory if it doesn't exist
            if (!file_exists(dirname($theme_template_path))) {
                wp_mkdir_p(dirname($theme_template_path));
            }

            // Copy the template from the plugin
            if (file_exists($plugin_template_path)) {
                copy($plugin_template_path, $theme_template_path);
            }
        }

        // Set the WooCommerce default path
        $this->template_html  = 'emails/admin-draft-order.php';
        
        $this->placeholders   = array(
            '{order_date}'   => '',
            '{order_number}' => '',
        );
       $this->init_form_fields();
 
         
        parent::__construct();

        // Enable email by default
        $this->enabled = 'yes';
        // Other settings.
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );       // Call parent constructor
    }

 
    
    /**
		 * Get email subject.
		 *
		 * @since  3.1.0
		 * @return string
		 */
		public function get_default_subject() {
			return __( '[{site_title}]: Draft order #{order_number}', 'woocommerce' );
		}

		/**
		 * Get email heading.
		 *
		 * @since  3.1.0
		 * @return string
		 */
		public function get_default_heading() {
			return __( 'Draft Order: #{order_number}', 'woocommerce' );
		}
        /**
		 * Default content to show below main email content.
		 *
		 * @since 3.7.0
		 * @return string
		 */
		public function get_default_additional_content() {
			return __( 'Congratulations on the sale.', 'woocommerce' );
		}

		/**
		 * Initialise settings form fields.
		 */
		public function init_form_fields() {
			/* translators: %s: list of placeholders */
			$placeholder_text  = sprintf( __( 'Available placeholders: %s', 'woocommerce' ), '<code>' . implode( '</code>, <code>', array_keys( $this->placeholders ) ) . '</code>' );
			$this->form_fields = array(
				'enabled'            => array(
					'title'   => __( 'Enable/Disable', 'woocommerce' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable this email notification', 'woocommerce' ),
					'default' => 'yes',
				),
				'recipient'          => array(
					'title'       => __( 'Recipient(s)', 'woocommerce' ),
					'type'        => 'text',
					/* translators: %s: WP admin email */
					'description' => sprintf( __( 'Enter recipients (comma separated) for this email. Defaults to %s.', 'woocommerce' ), '<code>' . esc_attr( get_option( 'admin_email' ) ) . '</code>' ),
					'placeholder' => '',
					'default'     => '',
					'desc_tip'    => true,
				),
				'subject'            => array(
					'title'       => __( 'Subject', 'woocommerce' ),
					'type'        => 'text',
					'desc_tip'    => true,
					'description' => $placeholder_text,
					'placeholder' => $this->get_default_subject(),
					'default'     => '',
				),
				'heading'            => array(
					'title'       => __( 'Email heading', 'woocommerce' ),
					'type'        => 'text',
					'desc_tip'    => true,
					'description' => $placeholder_text,
					'placeholder' => $this->get_default_heading(),
					'default'     => '',
				),
				'additional_content' => array(
					'title'       => __( 'Additional content', 'woocommerce' ),
					'description' => __( 'Text to appear below the main email content.', 'woocommerce' ) . ' ' . $placeholder_text,
					'css'         => 'width:400px; height: 75px;',
					'placeholder' => __( 'N/A', 'woocommerce' ),
					'type'        => 'textarea',
					'default'     => $this->get_default_additional_content(),
					'desc_tip'    => true,
				),
                'email_type' => array(
                'title'       => __( 'Email type', 'woocommerce' ),
                'type'        => 'select',
                'description' => __( 'Choose which format of email to send.', 'woocommerce' ),
                'default'     => 'html',
                'class'       => 'email_type wc-enhanced-select',
                'options'     => array(
                'html' => __( 'HTML', 'woocommerce' ),
                ),
                // 'options'     => $this->get_email_type_options(),
                'desc_tip'    => true,
                ),
			);
		}


    public function trigger($order_id) {
        if (!$order_id) {
            return;
        }

        $this->object = wc_get_order($order_id);
        $this->placeholders['{order_date}'] = wc_format_datetime($this->object->get_date_created());
        $this->placeholders['{order_number}'] = $this->object->get_order_number();

        // Only send if enabled and a valid recipient exists
        if (!$this->is_enabled() || !$this->get_recipient()) {
            return;
        }

        $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
    }

    public function get_content_html() {
        return wc_get_template_html($this->template_html, array(
            'order'         => $this->object,
            'email_heading' => $this->get_heading(),
            'sent_to_admin' => true,
            'plain_text'    => false,
            'email'         => $this,
        ));
    }

    public function get_content_plain() {
        return wc_get_template_html($this->template_plain, array(
            'order'         => $this->object,
            'email_heading' => $this->get_heading(),
            'sent_to_admin' => true,
            'plain_text'    => true,
            'email'         => $this,
        ));
    }
}
