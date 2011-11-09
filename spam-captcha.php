<?php
/**
Plugin Name: Spam Captcha
Description: <p>This plugins avoids spam actions on your website.</p><p>Captcha image and Akismet API are available for this plugin.</p><p>You may configure (for the captcha): <ul><li>The color of the background</li><li>The color of the letters</li><li>The size of the image</li><li>The size of the letters</li><li>The slant of the letters</li><li>...</li></ul></p><p>This plugin is under GPL licence</p>
Version: 1.1.2
Framework: SL_Framework
Author: SedLex
Author URI: http://www.sedlex.fr
Author Email: sedlex@sedlex.fr
Framework Email: sedlex@sedlex.fr
Plugin URI: http://wordpress.org/extend/plugins/spam-captcha/
License: GPL3
*/

//Including the framework in order to make the plugin work

require_once('core.php') ; 

/** ====================================================================================================================================================
* This class has to be extended from the pluginSedLex class which is defined in the framework
*/
class spam_captcha extends pluginSedLex {

	/** ====================================================================================================================================================
	* Plugin initialization
	* 
	* @return void
	*/
	static $instance = false;

	protected function _init() {
		global $wpdb ; 
		
		// Name of the plugin (Please modify)
		$this->pluginName = 'Spam Captcha' ; 
		
		// The structure of the SQL table
		$this->table_name = $wpdb->prefix . "pluginSL_" . get_class() ; 
		$this->table_sql = "id int NOT NULL AUTO_INCREMENT, id_comment mediumint(9) NOT NULL, status VARCHAR(10) DEFAULT 'ok', new_status VARCHAR(10) DEFAULT 'ok', author TEXT DEFAULT '', content TEXT DEFAULT '', date TIMESTAMP, UNIQUE KEY id_post (id)" ; 
		
		//Configuration of callbacks, shortcode, ... (Please modify)
		// For instance, see 
		//	- add_shortcode (http://codex.wordpress.org/Function_Reference/add_shortcode)
		//	- add_action 
		//		- http://codex.wordpress.org/Function_Reference/add_action
		//		- http://codex.wordpress.org/Plugin_API/Action_Reference
		//	- add_filter 
		//		- http://codex.wordpress.org/Function_Reference/add_filter
		//		- http://codex.wordpress.org/Plugin_API/Filter_Reference
		// Be aware that the second argument should be of the form of array($this,"the_function")
		// For instance add_action( "the_content",  array($this,"modify_content")) : this function will call the function 'modify_content' when the content of a post is displayed
		
		add_action('parse_request', array($this,'check_if_captcha_image') , 1);

		add_action('preprocess_comment', array($this,'check_comment_captcha'), 1, 1);
		add_action('comment_form', array($this,'add_captcha_image'), 1, 1);
		
		add_action('wp_insert_comment', array($this,'check_comment_akismet'), 100, 2);
		// Quand on change le status vers approve
		add_action('comment_spam_to_approved', array($this,'change_to_ham'), 1, 1);
		add_action('comment_spam_to_unapproved', array($this,'change_to_ham'), 1, 1);
		add_action('comment_trash_to_approved', array($this,'change_to_ham'), 1, 1);
		// Quand on change le status vers spam
		add_action('comment_approved_to_spam', array($this,'change_to_spam'), 1, 1);
		add_action('comment_unapproved_to_spam', array($this,'change_to_spam'), 1, 1);

		add_action( 'comment_form', array($this,'add_error_to_comment_form'), 2, 1);
		
		// Important variables initialisation (Do not modify)
		$this->path = __FILE__ ; 
		$this->pluginID = get_class() ; 
		
		// activation and deactivation functions (Do not modify)
		register_activation_hook(__FILE__, array($this,'install'));
		register_deactivation_hook(__FILE__, array($this,'uninstall'));
	}

	/**====================================================================================================================================================
	* Function called when the plugin is activated
	* For instance, you can do stuff regarding the update of the format of the database if needed
	* If you do not need this function, you may delete it.
	*
	* @return void
	*/
	
	public function _update() {
		
	}
	
	/**====================================================================================================================================================
	* Function to instantiate the class and make it a singleton
	* This function is not supposed to be modified or called (the only call is declared at the end of this file)
	*
	* @return void
	*/
	
	public static function getInstance() {
		if ( !self::$instance ) {
			self::$instance = new self;
		}
		return self::$instance;
	}
	
	/** ====================================================================================================================================================
	* Define the default option values of the plugin
	* This function is called when the $this->get_param function do not find any value fo the given option
	* Please note that the default return value will define the type of input form: if the default return value is a: 
	* 	- string, the input form will be an input text
	*	- integer, the input form will be an input text accepting only integer
	*	- string beggining with a '*', the input form will be a textarea
	* 	- boolean, the input form will be a checkbox 
	* 
	* @param string $option the name of the option
	* @return variant of the option
	*/
	public function get_default_option($option) {
		switch ($option) {
			// Alternative default return values (Please modify)
			case 'akismet_id' 		: return "" 		; break ; 
			case 'akismet_enable' 		: return false 	; break ; 
			
			case 'captcha_enable' 		: return false 	; break ; 
			case 'captcha_logged' 		: return false 	; break ; 
			case 'captcha_number' 		: return 4 	; break ; 
			case 'captcha_height' 		: return 32 	; break ; 
			case 'captcha_width' 		: return 80 	; break ; 
			case 'captcha_angle' 		: return 35 	; break ; 
			case 'captcha_size' 		: return 12 	; break ; 
			case 'captcha_background' 		: return "CCCCCC" 	; break ; 
			case 'captcha_font_color' 		: return "FFFFFF" 	; break ; 
			case 'captcha_noise' 		: return true 	; break ; 
			case 'captcha_color_variation' 		: return true 	; break ; 
			
			case 'captcha_html' 		: return "*<div class='captcha_image'> 
%image% 
<input type='text' id='captcha_comment' name='captcha_comment' />
<p>Please type the characters of this captcha image in the input box</p></div>" 	; break ; 
		}
		return null ;
	}

	/** ====================================================================================================================================================
	* The admin configuration page
	* This function will be called when you select the plugin in the admin backend 
	*
	* @return void
	*/
	
	public function configuration_page() {
		global $wpdb;
		?>
		<div class="wrap">
			<div id="icon-themes" class="icon32"><br></div>
			<h2><?php echo $this->pluginName ?></h2>
		</div>
		<div style="padding:20px;">			
			<?php
			//===============================================================================================
			// After this comment, you may modify whatever you want
			
			
			$tabs = new adminTabs() ; 
			
			ob_start() ; 
				echo "<p>".__("The following table summarizes the number of rejected comments", $this->pluginID)."</p>" ; 
				$table = new adminTable() ; 
				$table->title(array(__("Type of protection", $this->pluginID), __("Summary", $this->pluginID))) ; 
				$cel1 = new adminCell("<p>".__("CAPTCHA protection:", $this->pluginID)."</p>") ;	
				$nb_captcha = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE status='captcha'") ; 
				$cel2 = new adminCell("<p>".sprintf(__("%s messages have been blocked as the CAPTCHA test failed", $this->pluginID), "<b>".$nb_captcha."</b>")."</p>") ; 		
				$table->add_line(array($cel1, $cel2), '1') ; 
				$cel1 = new adminCell("<p>".__("AKISMET protection:", $this->pluginID)."</p>") ; 	
				$nb_spam = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE status='spam' AND new_status='spam'") ; 
				$nb_ham = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE status='ok' AND new_status='ok'") ; 
				$nb_false_spam = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE status='spam' AND new_status='ok'") ; 
				$nb_false_ham = $wpdb->get_var("SELECT COUNT(*) FROM ".$this->table_name." WHERE status='ok' AND new_status='spam'") ; 
				
				$cel2 = new adminCell("<p>".sprintf(__('This plugin have stopped %s spam-comments (and missed %s that you have manually indicated as being spam)', $this->pluginID), "<b>".$nb_spam."</b>", "<b>".$nb_false_ham."</b>")."</p><p>".sprintf(__('Moreover, this plugin processes %s normal comments (and marked as spam %s that you have manually indicated as being normal)', $this->pluginID), "<b>".$nb_ham."</b>", "<b>".$nb_false_spam."</b>")."</p>") ; 		
				$table->add_line(array($cel1, $cel2), '2') ; 
				echo $table->flush() ; 
			$tabs->add_tab(__('Summary of Protection',  $this->pluginID), ob_get_clean() ) ; 	
			
			ob_start() ; 	
				$params = new parametersSedLex($this, "tab-parameters") ; 
				
				$params->add_title(__("Do you want to enable CAPTCHA for posted comments?", $this->pluginID)) ; 
				if  ( (!function_exists("imagecreate")) || (!function_exists("imagefill")) || (!function_exists("imagecolorallocate")) || (!function_exists("imagettftext")) || (!function_exists("imageline")) || (!function_exists("imagepng")) ) {
					$params->add_comment(sprintf(__("Your server does not seems to have GD installed with the following functions: %s", $this->pluginID), "<code>imagecreate</code>, <code>imagefill</code>, <code>imagecolorallocate</code>, <code>imagettftext</code>, <code>imageline</code>, <code>imagepng</code>")) ; 
					$params->add_comment(__("Thus, it is not possible to activate this option... sorry !", $this->pluginID)) ; 
				} else {
					$params->add_param('captcha_enable', __('Yes/No (for the comment):', $this->pluginID)) ; 
					$params->add_param('captcha_logged', __('Use Capctha even if user is logged:', $this->pluginID)) ; 					
					$params->add_comment(sprintf(__("WARNING: If you enable this option, the Captcha will be only effective with users with the following role: %s", $this->pluginID)." <br/>".__("Then the Captcha will be displayed (for instance, to make sure that the display/CSS/etc. is correct) but ineffective with users with the following role: %s", $this->pluginID), "<em>Subscriber</em>, <em>Contributor</em>, <em>Author</em>", "<em>Editor</em>, <em>Administrator</em>")) ; 
					$params->add_param('captcha_number', __('Number of letters:', $this->pluginID)) ; 
					$params->add_comment(sprintf(__("Default value %s", $this->pluginID),$this->get_default_option('captcha_number'))) ; 
					$params->add_param('captcha_width', __('Width of image:', $this->pluginID)) ; 
					$params->add_comment(sprintf(__("Default value %s", $this->pluginID),$this->get_default_option('captcha_width'))) ; 
					$params->add_param('captcha_height', __('Height of image:', $this->pluginID)) ; 
					$params->add_comment(sprintf(__("Default value %s", $this->pluginID),$this->get_default_option('captcha_height'))) ; 
					$params->add_param('captcha_angle', __('Maximum +/- angle of letters:', $this->pluginID)) ; 
					$params->add_comment(sprintf(__("Default value %s", $this->pluginID),$this->get_default_option('captcha_angle'))) ; 
					$params->add_param('captcha_size', __('Size of letters:', $this->pluginID)) ; 
					$params->add_comment(sprintf(__("Default value %s", $this->pluginID),$this->get_default_option('captcha_size'))) ; 
					$params->add_param('captcha_background', __('Color of the background:', $this->pluginID)) ; 
					$params->add_comment(sprintf(__("Default value %s", $this->pluginID),$this->get_default_option('captcha_background'))) ; 
					$params->add_param('captcha_font_color', __('Color of the font:', $this->pluginID)) ; 
					$params->add_comment(sprintf(__("Default value %s", $this->pluginID),$this->get_default_option('captcha_font_color'))) ; 
					$params->add_param('captcha_color_variation', __('Variation of the color of the letters:', $this->pluginID)) ; 
					$params->add_comment(__("The color of the letters are not the identical", $this->pluginID)) ; 
					$params->add_param('captcha_noise', __('Variation of the color of the background:', $this->pluginID)) ; 
					$params->add_comment(__("The color of the background are not the homogenous", $this->pluginID)) ; 
					$params->add_param('captcha_html', __('The HTML that will be inserted in your page to display captcha image:', $this->pluginID)) ; 
					$params->add_comment(sprintf(__("The default html is: %s (please note that %s will be replace with the captcha image)", $this->pluginID),"<br><code>&lt;div class='captcha_image'&gt; <br>%image%<br>&lt;input type='text' id='captcha_comment' name='captcha_comment' /&gt;<br/>&lt;p&gt;Please type the characters of this captcha image in the input box&lt;/p&gt;&lt;/div&gt;</code><br>", "%image%")) ; 

					
				}
				
				$params->add_title(__("Do you want to use Akismet API to check spam against posted comments?", $this->pluginID)) ; 
				$params->add_param('akismet_enable', __('Yes/No:', $this->pluginID)) ; 
				$params->add_param('akismet_id', __('The Akismet ID:', $this->pluginID)) ; 
				if ($this->get_param('akismet_id')!="") {
					if ($this->verify_akismet_key($this->get_param('akismet_id'))) {
						$params->add_comment(__("Your Akismet ID is correct.", $this->pluginID)) ; 
					} else {
						$params->add_comment(__("Your Akismet ID does not seem to be correct.", $this->pluginID)."<br/>".sprintf(__("To get an Akismet ID, see %s here %s.", $this->pluginID),"<a href='http://akismet.com/get/'>", "</a>")) ; 
					}
				} else {
					$params->add_comment(sprintf(__("To get an Akismet ID, see %s here %s.", $this->pluginID),"<a href='http://akismet.com/get/'>", "</a>")) ; 
				}
				$params->flush() ; 
			$tabs->add_tab(__('Parameters',  $this->pluginID), ob_get_clean() ) ; 	

			ob_start() ; 
				$plugin = str_replace("/","",str_replace(basename(__FILE__),"",plugin_basename( __FILE__))) ; 
				$trans = new translationSL($this->pluginID, $plugin) ; 
				$trans->enable_translation() ; 
			$tabs->add_tab(__('Manage translations',  $this->pluginID), ob_get_clean() ) ; 	

			ob_start() ; 
				echo __('This form is an easy way to contact the author and to discuss issues / incompatibilities / etc.',  $this->pluginID) ; 
				$plugin = str_replace("/","",str_replace(basename(__FILE__),"",plugin_basename( __FILE__))) ; 
				$trans = new feedbackSL($plugin, $this->pluginID) ; 
				$trans->enable_feedback() ; 
			$tabs->add_tab(__('Give feedback',  $this->pluginID), ob_get_clean() ) ; 	
			
			echo $tabs->flush() ; 
			
			
			// Before this comment, you may modify whatever you want
			//===============================================================================================
			?>
			<?php echo $this->signature ; ?>
		</div>
		<?php
	}
	
	/** ====================================================================================================================================================
	* Called before a new comment is stored in the database
	*
	*/
	function check_comment_captcha($comment) {
		global $wpdb;
		session_start() ; 
		
		// on ne fait rien pour les trackbacks et les pingback (car il ne sont pas cense avoir des captcha...)
		if ($comment['comment_type'] == 'pingback' || $comment['comment_type'] == 'trackback') {
			return $comment ; 
		}

		if (($this->get_param('captcha_enable')==true)&&((!is_user_logged_in())||($this->get_param('captcha_logged')))) {
			
			// First we check if the user may edit comment... if so we return the comment 
			if ((is_numeric($comment['user_ID']))&&($comment['user_ID']>0)) {
				$user = new WP_User( $comment['user_ID'] );
				if ( ! empty( $user->roles ) && is_array( $user->roles ) ) {
					foreach ( $user->roles as $role ) {
						if (($role=="administrator")||($role=="editor")){
							return $comment ; 
						}
					}
				}
			}
			
			// If not we check the captcha
			if (($_POST['captcha_comment']=="")||($_SESSION['keyCaptcha']!=md5($_POST['captcha_comment']))) {
				// we save it...
				$wpdb->query("INSERT INTO ".$this->table_name." (id_comment, status, new_status, author, content, date) VALUES (0, 'captcha', 'captcha', '".mysql_real_escape_string($comment['comment_author'])."', '".mysql_real_escape_string($comment['comment_content'])."', NOW())") ; 
					
				$permalink = get_permalink( $comment['comment_post_ID'] );
				wp_redirect(add_query_arg(array("error_checker"=>"captcha"),$permalink."#error", 302)) ; 
				die();
			}
		}
		return $comment ; 
	}
	
	/** ====================================================================================================================================================
	* Called when the comment form is displayed in order to add the captcha
	*
	*/
	function add_captcha_image($id_post) {
		if (($this->get_param('captcha_enable')==true)&&((!is_user_logged_in())||($this->get_param('captcha_logged')))) {
			$html = $this->get_param('captcha_html') ; 
			echo str_replace("%image%", "<img src='".add_query_arg(array("display_captcha"=>"true"))."' alt='".__("Please type the characters of this captcha image in the input box", $this->pluginID)."'>", $html) ; 
		}
	}
	
	/** ====================================================================================================================================================
	* Display the image
	*
	*/
	function check_if_captcha_image($vars) {
		session_start() ; 
		
		if (isset($_GET['display_captcha'])) {
			$maxX = $this->get_param('captcha_width') ; 
			$maxY = $this->get_param('captcha_height') ; 
			$nbLetter = $this->get_param('captcha_number') ; 
			$angle = $this->get_param('captcha_angle') ; 
			$sizeLetter = $this->get_param('captcha_size') ; 
			
			$text = Utils::rand_str($nbLetter, "abcdefghijklmnopqrstuvwxyz") ; 
			
			$captcha = imagecreate($maxX, $maxY);
  			$r1 = hexdec(substr($this->get_param('captcha_background'), 0, 2)) ; 
  			$g1 = hexdec(substr($this->get_param('captcha_background'), 2, 2)) ; 
  			$b1 = hexdec(substr($this->get_param('captcha_background'), 4, 2)) ; 
  			
			$r2 = hexdec(substr($this->get_param('captcha_font_color'), 0, 2)) ; 
  			$g2 = hexdec(substr($this->get_param('captcha_font_color'), 2, 2)) ; 
  			$b2 = hexdec(substr($this->get_param('captcha_font_color'), 4, 2)) ; 
			
			
			imagefill ($captcha, 0 , 0, $grey) ;  
			
			//
			// We generate colors in array to avoid memory overload
			//
			$color_back = array(imagecolorallocate($captcha, $r1, $g1, $b1)) ; 
			$color_font = array(imagecolorallocate($captcha, $r2, $g2, $b2)) ;
			$balance = rand(20,80) ; 
			
			if ( ($this->get_param('captcha_noise')) ) {
				// 50 for the background
				for ($i=0 ; $i<50 ; $i++) {
					$ratio_color = rand(10,100) ; 
					$r3 = floor(($balance*$r1+(100-$balance)*$r2)/100 + $ratio_color/100 * ($r1-$r2)*$balance/100 ) ; 
					$g3 = floor(($balance*$g1+(100-$balance)*$g2)/100 + $ratio_color/100 * ($g1-$g2)*$balance/100 ) ; 
					$b3 = floor(($balance*$b1+(100-$balance)*$b2)/100 + $ratio_color/100 * ($b1-$b2)*$balance/100 ) ; 
					$color_back[] = imagecolorallocate($captcha, $r3 , $g3, $b3 );
				}
			}
			
			if ( ($this->get_param('captcha_color_variation')) ) {
				// 50 for the font
				for ($i=0 ; $i<50 ; $i++) {
					$ratio_color = rand(10,100) ; 
					$r3 = floor(($balance*$r1+(100-$balance)*$r2)/100 - $ratio_color/100 * ($r1-$r2)*$balance/100 ) ; 
					$g3 = floor(($balance*$g1+(100-$balance)*$g2)/100 - $ratio_color/100 * ($g1-$g2)*$balance/100 ) ; 
					$b3 = floor(($balance*$b1+(100-$balance)*$b2)/100 - $ratio_color/100 * ($b1-$b2)*$balance/100 ) ; 
					$color_font[] = imagecolorallocate($captcha, $r3 , $g3, $b3 );
				}
			} 
			
			
			if ( ($this->get_param('captcha_noise')) ) {
				$percentage_of_noise = 30 ;  
				for ($i=0 ; $i<($maxX/10*$maxY/10)*$percentage_of_noise ; $i++) {
					$x = rand(0,$maxX) ; 
					$y = rand(0,$maxY) ; 
					$size = rand(3, floor($maxY/2)) ; 
					
					$color = rand(0, count($color_back)-1) ; 
					imagefilledellipse ( $captcha , $x , $y , $size , $size ,$color_back[$color] ) ; 
				}
			}
			
			for ($i=0 ; $i<$nbLetter ; $i++) {
				$police = rand(1,2) ; 
				// ANGLE
				$slant = rand(-$angle, $angle) ; 
				// X
				$x = floor( $maxX/(2*$nbLetter) - $sizeLetter/2 + $i*$maxX/($nbLetter)) ; 
				$delta_x = rand(-floor( $maxX/(4*$nbLetter) - $sizeLetter/4 ), floor( $maxX/(4*$nbLetter) - $sizeLetter/4 )) ;
				// Y
				$y = floor( $maxY/2 + $sizeLetter/2 ); 
				$delta_y = rand(-floor( $maxY/4 - $sizeLetter/4 ), floor( $maxY/4 - $sizeLetter/4 )) ; 
				// SIZE
				$delta_size = rand(0, floor( $maxY/4 - $sizeLetter/4 )) ;
				
				$color = rand(0, count($color_font)-1) ; 
				
				$return = imagettftext($captcha, $sizeLetter+$delta_size, $slant, $x+$delta_x, $y+$delta_y, $color_font[$color], WP_PLUGIN_DIR."/".str_replace(basename( __FILE__),"",plugin_basename(__FILE__))."spam-captcha-".$police.".ttf", substr($text, $i, 1));
			}
			
			
			
			$_SESSION['keyCaptcha'] = md5($text);
			header("Content-type: image/png");
			// Do not cache this image
			header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
			header("Cache-Control: no-cache");
			header("Pragma: no-cache");
			echo imagepng($captcha);
			die() ; 
		}
	}
	
	/** ====================================================================================================================================================
	* Called when a new comment is submitted (after its storing)
	*
	*/
	function check_comment_akismet($id, $comment) {
		global $wpdb;

		if ($this->get_param('akismet_enable')) {
			if ($this->get_param('akismet_id') != "") {
				$com['blog']       = get_option('home');
				$com['user_ip']    = $comment->comment_author_IP;
				$com['user_agent'] = $comment->comment_agent;
				$com['referrer']   = $_SERVER['HTTP_REFERER'];
				$com['permalink']  = get_permalink($comment->comment_post_ID);
				$com['comment_type']  = $comment->comment_type ;
				$com['comment_author']  = $comment->comment_author;
				$com['comment_author_email']  = $comment->comment_author_email ;
				$com['comment_author_url']  = $comment->comment_author_url; 
				$com['comment_content']  = $comment->comment_content;
				
				$query_string = "" ;
				foreach ( $com as $key => $data ) {
					$query_string .= $key . '=' . urlencode( stripslashes($data) ) . '&';
				}
				
				if ($this->check_spam_akismet($query_string)==true) {
					// we mark as spam
					wp_set_comment_status( $id, "spam" ) ; 
					// we save it...
					$wpdb->query("INSERT INTO ".$this->table_name." (id_comment, status, new_status, author, content, date) VALUES (".$id.", 'spam', 'spam', '".mysql_real_escape_string($comment->comment_author)."', '".mysql_real_escape_string($comment->comment_content)."', NOW())") ; 
					// we redirect the page to inform the user
					wp_redirect(add_query_arg(array("error_checker"=>"spam"),get_permalink($comment->comment_post_ID)."#error"),302);
					die() ; 
				} else {
					// we save it...
					$wpdb->query("INSERT INTO ".$this->table_name." (id_comment, status, new_status, author, content, date) VALUES (".$id.", 'ok', 'ok', '".mysql_real_escape_string($comment->comment_author)."', '".mysql_real_escape_string($comment->comment_content)."', NOW())") ; 
				}
			}
		}
	}
	
	/** ====================================================================================================================================================
	* Called when the comment is change to ham
	*
	*/
	function change_to_ham($comment) {
		global $wpdb;
		$wpdb->query("UPDATE ".$this->table_name." SET new_status='ok' WHERE id_comment='".$comment->comment_ID."'") ; 
	}
	
	/** ====================================================================================================================================================
	* Called when the comment is change to spam
	*
	*/
	function change_to_spam($comment) {
		global $wpdb;
		$wpdb->query("UPDATE ".$this->table_name." SET new_status='spam' WHERE id_comment='".$comment->comment_ID."'") ; 
	}
	
	/** ====================================================================================================================================================
	* Called when the comment form is called
	*
	*/
	function add_error_to_comment_form($id_post) {
		if ($_GET['error_checker']=="spam") {
			echo "<a name='error'><div class='error_spam_captcha'><p>" ; 
			echo __('You have submitted a comment which is considered as a spam... If not, please modify it and retry', $this->pluginID) ; 
			echo "</p></div>" ; 
		}
		if ($_GET['error_checker']=="captcha") {
			echo "<a name='error'><div class='error_spam_captcha'><p>" ; 
			echo __('You have mistyped the captcha : to prove that your are not a spam machine, please retry!', $this->pluginID) ; 
			echo "</p></div>" ; 
		}
	}
	
	/** ====================================================================================================================================================
	* To check comment against akismet
	*
	* $request = 'blog='. urlencode($data['blog']) .
          *     '&user_ip='. urlencode($data['user_ip']) .
          *     '&user_agent='. urlencode($data['user_agent']) .
          *     '&referrer='. urlencode($data['referrer']) .
          *     '&permalink='. urlencode($data['permalink']) .
          *     '&comment_type='. urlencode($data['comment_type']) .
          *     '&comment_author='. urlencode($data['comment_author']) .
          *     '&comment_author_email='. urlencode($data['comment_author_email']) .
          *     '&comment_author_url='. urlencode($data['comment_author_url']) .
          *     '&comment_content='. urlencode($data['comment_content']);
	*
	* @param string request the request to check
	* @return boolean true if it is a spam 
	*/
	function check_spam_akismet($request) {
		global $wp_version;

		$http_args = array(
			'body'		=> $request,
			'headers'		=> array(
				'Content-Type'	=> 'application/x-www-form-urlencoded; charset=' . get_option( 'blog_charset' ),
				'User-Agent'	=> "WordPress/{$wp_version} | Akismet/2.5.3"
			),
			'httpversion'	=> '1.0',
			'timeout'		=> 15
		);
		if (($this->get_param('akismet_enable')) && ($this->get_param('akismet_id')!="") ) {
			$akismet_url = "http://".$this->get_param('akismet_id').".rest.akismet.com/1.1/comment-check";
			$response = wp_remote_post( $akismet_url, $http_args );
			
			if ( is_wp_error( $response ) )
				return false;
			
			if ($response['body']=="true") {
				return true ; 
			}
			return false ; 
		} else {
			return false ;
		}
	}
	
	/** ====================================================================================================================================================
	* To check if the key is valid or not 
	*
	* @param string key the key to check
	* @return boolean true if it is a spam 
	*/
	
	function verify_akismet_key($key) {
		$request = 'key='. $key .'&blog='. get_option('home');
		
		$http_args = array(
			'body'		=> $request,
			'headers'		=> array(
				'Content-Type'	=> 'application/x-www-form-urlencoded; charset=' . get_option( 'blog_charset' ),
				'User-Agent'	=> "WordPress/{$wp_version} | Akismet/2.5.3"
			),
			'httpversion'	=> '1.0',
			'timeout'		=> 15
		);
		
		$akismet_url = "http://rest.akismet.com/1.1/verify-key";
		$response = wp_remote_post( $akismet_url, $http_args );
		if ( is_wp_error( $response ) )
			return false;
		 if ($response['body']=='valid') 
			return true ; 
		return false ; 

	}
}

$spam_captcha = spam_captcha::getInstance();

?>