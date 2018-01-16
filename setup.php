<?php
	// TWEET NEST
	// Setup guide

	// This file should be deleted once your tweet archive is set up.

	error_reporting(E_ALL ^ E_NOTICE);
	ini_set("display_errors", true); // This is NOT a production page; errors can and should be visible to aid the user.

	mb_language("uni");
	mb_internal_encoding("UTF-8");
    session_start();

	header("Content-Type: text/html; charset=utf-8");

	require "inc/config.php";

	$GLOBALS['error'] = false;

	$_SESSION['redirect_source'] = 'setup';

	function s($str){ return htmlspecialchars($str, ENT_NOQUOTES); } // Shorthand

	function displayErrors($e){
		if(count($e) <= 0){ return false; }
		$r = "";
		if(count($e) > 1){
			$r .= "<h2>Errors</h2><ul class=\"error\">";
			foreach($e as $error){
				$r .= "<li>" . $error . "</li>";
				// Not running htmlspecialchars 'cause errors are a set of finite approved messages seen below
			}
			$r .= "</ul>";
		} else {
			$r .= "<h2>Error</h2>\n<p class=\"error\">" . current($e) . "</p>";
		}
		return $r . "\n";
	}

	function errorHandler($errno, $message, $filename, $line, $context){
		if(error_reporting() == 0){ return false; }
		if($errno & (E_ALL ^ E_NOTICE)){
			$GLOBALS['error'] = true;
			$types = array(
				1 => "error",
				2 => "warning",
				4 => "parse error",
				8 => "notice",
				16 => "core error",
				32 => "core warning",
				64 => "compile error",
				128 => "compile warning",
				256 => "user error",
				512 => "user warning",
				1024 => "user notice",
				2048 => "strict warning",
				4096 => "recoverable fatal error"
			);
			echo "<div class=\"serror\"><strong>PHP " . $types[$errno] . ":</strong> " . s(strip_tags($message)) . " in <code>" . s($filename) . "</code> on line " . s($line) . ".</div>\n";
		}
		return true;
	}
	set_error_handler("errorHandler");

	// Utility function, thanks to stackoverflow.com/questions/3835636
	function str_lreplace($search, $replace, $subject){ $pos = strrpos($subject, $search); if($pos !== false){ $subject = substr_replace($subject, $replace, $pos, strlen($search)); } return $subject; }

	// Function to insert configuration value into the array literal in the configuration file
	function configSetting($cf, $setting, $value){
		if($value === ''){ return $cf; } // Empty
		$empty = is_bool($value) ? '(true|false)' : "''";
		$val   = is_bool($value) ? ($value ? 'true' : 'false') : "'" . preg_replace("/([\\'])/", '\\\$1', $value) . "'";

		// First check if the directive exists in the config file.
		$directiveHead = "'" . preg_quote($setting, '/') . "'(\s*)=>";
		$exists = preg_match('/' . $directiveHead . '/', $cf);

		if($exists){
			// If it exists, simply add the value instead of an empty one
			return preg_replace('/' . $directiveHead . '(\s*)' . $empty . '/', "'" . $setting . "'$1=>$2" . $val, $cf);
		} else {
			// If it does not exist, let's add it to the end of the literal array in the file.
			return str_lreplace(');', "'" . $setting . "' => " . $val . ",\n);", $cf);
		}
	}

	$e       = array();
	$log     = array();
	$success = false; // We are doomed :(
	$post    = (strtoupper($_SERVER['REQUEST_METHOD']) == "POST");

	// Get the full path
	$fPath = explode(DIRECTORY_SEPARATOR, rtrim(__FILE__, DIRECTORY_SEPARATOR));
	array_pop($fPath); // Remove setup.php
	$fPath = implode($fPath, "/");

	// Prerequisites and pre-checks
	if(!empty($config['twitter_screenname'])){ $e[] = "<strong>Your Tweet Nest has already been set up.</strong> If you wish to change settings, open <code>config.php</code> and change values using a text editor. Alternatively, replace it with the default empty <code>config.php</code> and reload this page."; } // Config already defined!
	if(!function_exists("json_decode")){ $e[] = "Your PHP version <strong>doesn&#8217;t seem to support JSON decoding</strong>. This functionality is required to retrieve tweets and is included in the core of PHP 5.2 and above. However, you can also install the <a href=\"http://pecl.php.net/package/json\">JSON PECL module</a> instead, if you&#8217;re using PHP 5.1."; }
	if(version_compare(PHP_VERSION, "5.1.0", "<")){ $e[] = "<strong>A PHP version of 5.1 or above is required.</strong> Your current PHP version is " . PHP_VERSION . ". You need to upgrade" . (version_compare(PHP_VERSION, "5.0.0", "<") ? " or turn PHP 5 on if you have a server that requires you to do that" : " your PHP installation") . "."; }
	if(function_exists("apache_get_modules") && !in_array("mod_rewrite", apache_get_modules())){ $e[] = "Could not detect the <code>mod_rewrite</code> module in your Apache installation. <strong>This module is required.</strong>"; }
	clearstatcache();
	if(!is_writable("inc/config.php")){ $e[] = "Your <code>config.php</code> file is not writable by the server. Please make sure it is before proceeding, then reload this page. Often, this is done through giving every system user the write privileges on that file through FTP."; }
	if(!function_exists("preg_match")){ $e[] = "PHP&#8217;s PCRE support module appears to be missing. Tweet Nest requires Perl-style regular expression support to function."; }
	if(!function_exists("mysql_connect") && !function_exists("mysqli_connect")){ $e[] = "Neither the MySQL nor the MySQLi library for PHP is installed. One of these are required, along with a MySQL server to connect to and store data in."; }

	// Message shown when people have actively tried to go through OAuth but it failed verification
	if(isset($_SESSION['status']) && $_SESSION['status'] == 'not verified'){
		$e[] = '<strong>We could not verify you through Twitter.</strong> Please make sure you&#8217;ve entered the correct credentials on the Twitter authentication page.';
	}
	// Message shown when people have actively tried to go through OAuth but there was an old key or other mechanical mishap
	if(isset($_SESSION['status']) && $_SESSION['status'] == 'try again'){
		$e[] = '<strong>Something broke during the verification through Twitter.</strong> Please try again.';
	}

	// PREPARE VARIABLES
	$pp   = strpos($_SERVER['REQUEST_URI'], "/setup");
	$path = is_numeric($pp) ? ($pp > 0 ? substr($_SERVER['REQUEST_URI'], 0, $pp) : "/") : "";

	// Someone's submitting! Time to set up!
	if($post){


        // Are we redirecting?
        if(!empty($_POST['redirect']) || !empty($_POST['redirect_x'])){
            if(
                isset($_POST['consumer_key']) && !empty($_POST['consumer_key']) &&
                isset($_POST['consumer_secret']) && !empty($_POST['consumer_secret'])
            ){
                if(!isset($config) || !is_array($config)){
                    $config = array();
                }

                $config['consumer_key']    = $_SESSION['entered_consumer_key']    = $_POST['consumer_key'];
                $config['consumer_secret'] = $_SESSION['entered_consumer_secret'] = $_POST['consumer_secret'];

                require 'redirect.php';
                exit;
            } else {
                $e[] = 'Please fill in your <strong>Twitter app consumer key and secret</strong> before authenticating with Twitter. ' .
                    'You can get these by creating an app at <a href="//dev.twitter.com/apps">dev.twitter.com</a>.';
            }
        }

		$log[] = "Settings being submitted!";
		$log[] = "PHP version: " . PHP_VERSION;
		if(
			empty($e) &&
			// Required fields
			!empty($_POST['tz']) &&
			!empty($_POST['path']) &&
			!empty($_POST['db_hostname']) &&
			!empty($_POST['db_username']) &&
			!empty($_POST['db_database']) &&
			!empty($_POST['consumer_key']) &&
			!empty($_POST['consumer_secret'])
		){
			$log[] = "All required fields filled in.";
			if(date_default_timezone_set($_POST['tz'])){
				$log[] = "Valid time zone.";
			} else {
				$e[] = "Invalid time zone.";
			}
			if(empty($_POST['db_table_prefix']) || preg_match("/^[a-zA-Z0-9_]+$/", $_POST['db_table_prefix'])){
				$log[] = "Valid table name prefix.";
			} else {
				$e[] = "Invalid table name prefix. You can only use letters, numbers and the _ character.";
			}
			if(!empty($_POST['maintenance_http_password']) && $_POST['maintenance_http_password'] != $_POST['maintenance_http_password_2']){
				$e[] = "The two typed admin passwords didn&#8217;t match. Please make sure they&#8217;re the same.";
			}
            if (!isset($_SESSION['access_token'])) {
                $e[] = "You must authorize Tweet Nest to use your Twitter account before continuing.";
            }
			$sPath = "/" . trim($_POST['path'], "/");
			$log[] = "Formatted path: " . $sPath;
			if(!$e){
				// Check the database first!
				require "inc/class.db.php";
				try {
					$db = new DB("mysql", array(
						"hostname" => $_POST['db_hostname'],
						"username" => $_POST['db_username'],
						"password" => $_POST['db_password'],
						"database" => $_POST['db_database']
					));
				} catch(Exception $err){
					$e[] = "Got the following database connection error! <code>" . $err->getMessage() . "</code> Please make sure that your database settings are correct and that the database server is running and then try again.";
				}
				if(!$e && !$db){
					$e[] = "Got the following database connection error! <code>" . $db->error() . "</code> Please make sure that your database settings are correct and that the database server is running and then try again.";
				}
				if(!$e && !$GLOBALS['error']){ // If we get a database error, it'll activate $GLOBALS['error'] through PHP error
					$log[] = "Connected to MySQL database!";
					$cv    = $db->clientVersion();
					if(version_compare($cv, "4.1.0", "<")){
						$e[] = "Your MySQL client version is too old. Tweet Nest requires MySQL version 4.1 or higher to function. Your client currently has " . s($cv) . ".";
					} else { $log[] = "MySQL client version: " . $cv; }
					$sv    = $db->serverVersion();
					if(version_compare($sv, "4.1.0", "<")){
						$e[] = "Your MySQL server version is too old. Tweet Nest requires MySQL version 4.1 or higher to function. Your server currently has " . s($sv) . ".";
					} else { $log[] = "MySQL server version: " . $sv; }
					if(!$e){
						// Set up the database!
						$log[] = "Acceptable MySQL version.";
						$DTP = $_POST['db_table_prefix']; // This has been verified earlier on in the code

						// Tweets table
						$q = $db->query("CREATE TABLE IF NOT EXISTS `".$DTP."tweets` (`id` int(10) unsigned NOT NULL AUTO_INCREMENT, `userid` varchar(100) NOT NULL, `tweetid` varchar(100) NOT NULL, `type` tinyint(4) NOT NULL DEFAULT '0', `time` int(10) unsigned NOT NULL, `text` varchar(255) NOT NULL, `source` varchar(255) NOT NULL, `favorite` tinyint(4) NOT NULL DEFAULT '0', `extra` text NOT NULL, `coordinates` text NOT NULL, `geo` text NOT NULL, `place` text NOT NULL, `contributors` text NOT NULL, PRIMARY KEY (`id`), UNIQUE (`tweetid`), FULLTEXT KEY `text` (`text`)) ENGINE=MyISAM  DEFAULT CHARSET=utf8");

						if(!$q){
							$e[] = "An error occured while creating table <code>".$DTP."tweets</code>: <code>" . $db->error() . "</code>";
						} else { $log[] = "Successfully created table ".$DTP."tweets"; }

                        // Alter the tweets text column to support greater than 255 characters, but only if the database version supports it. Otherwise, ignore the error.
                        $db->query('ALTER TABLE `'.DTP.'tweets` CHANGE `text` `text` VARCHAR(510) NOT NULL');

						// Tweet users table
						$q = $db->query("CREATE TABLE IF NOT EXISTS `".$DTP."tweetusers` (`id` int(10) unsigned NOT NULL AUTO_INCREMENT, `userid` varchar(100) NOT NULL, `screenname` varchar(25) NOT NULL, `realname` varchar(255) NOT NULL, `location` varchar(255) NOT NULL, `description` varchar(255) NOT NULL, `profileimage` varchar(255) NOT NULL, `url` varchar(255) NOT NULL, `extra` text NOT NULL, `enabled` tinyint(4) NOT NULL, PRIMARY KEY (`id`), UNIQUE (`userid`) ) ENGINE=MyISAM  DEFAULT CHARSET=utf8");
						if(!$q){
							$e[] = "An error occured while creating table <code>".$DTP."tweetusers</code>: <code>" . $db->error() . "</code>";
						} else { $log[] = "Successfully created table ".$DTP."tweetusers"; }

						// Tweet words table
						$q = $db->query("CREATE TABLE IF NOT EXISTS `".$DTP."tweetwords` (`id` int(10) unsigned NOT NULL AUTO_INCREMENT, `tweetid` int(10) unsigned NOT NULL, `wordid` int(10) unsigned NOT NULL, `frequency` float NOT NULL, PRIMARY KEY (`id`), KEY `tweetwords_tweetid` (`tweetid`), KEY `tweetwords_wordid` (`wordid`), KEY `tweetwords_frequency` (`frequency`)) ENGINE=MyISAM  DEFAULT CHARSET=utf8");
						if(!$q){
							$e[] = "An error occured while creating table <code>".$DTP."tweetwords</code>: <code>" . $db->error() . "</code>";
						} else { $log[] = "Successfully created table ".$DTP."tweetwords"; }

						// Words table
						$q = $db->query("CREATE TABLE IF NOT EXISTS `".$DTP."words` (`id` int(10) unsigned NOT NULL AUTO_INCREMENT, `word` varchar(150) NOT NULL, `tweets` int(11) NOT NULL, PRIMARY KEY (`id`), KEY `words_tweets` (`tweets`)) ENGINE=MyISAM  DEFAULT CHARSET=utf8");
						if(!$q){
							$e[] = "An error occured while creating table <code>".$DTP."words</code>: <code>" . $db->error() . "</code>";
						} else { $log[] = "Successfully created table ".$DTP."words"; }

						if(!$e){
							// WRITE THE CONFIG FILE, YAY!
							$cf = file_get_contents("inc/config.php");
                            $cf = configSetting($cf, "consumer_key", $_POST['consumer_key']);
                            $cf = configSetting($cf, "consumer_secret", $_POST['consumer_secret']);
							$cf = configSetting($cf, "twitter_screenname", $_SESSION['access_token']['screen_name']);
							$cf = configSetting($cf, 'your_tw_screenname', $_SESSION['access_token']['screen_name']);
                            $cf = configSetting($cf, "twitter_token", $_SESSION['access_token']['oauth_token']);
                            $cf = configSetting($cf, "twitter_token_secr", $_SESSION['access_token']['oauth_token_secret']);
							$cf = configSetting($cf, "timezone", $_POST['tz']);
							$cf = configSetting($cf, "path", $sPath);
							$cf = configSetting($cf, "hostname", $_POST['db_hostname']);
							$cf = configSetting($cf, "username", $_POST['db_username']);
							$cf = configSetting($cf, "password", $_POST['db_password']);
							$cf = configSetting($cf, "database", $_POST['db_database']);
							$cf = configSetting($cf, "table_prefix", $_POST['db_table_prefix']);
							$cf = configSetting($cf, "maintenance_http_password", $_POST['maintenance_http_password']);
							$cf = configSetting($cf, "follow_me_button", !empty($_POST['follow_me_button']));
							$cf = configSetting($cf, "smartypants", !empty($_POST['smartypants']));
							$cf = configSetting($cf, "https_strict", !empty($_POST['https_strict']));
							$f  = fopen("inc/config.php", "wt");
							$fe = "Could not write configuration to <code>config.php</code>, please make sure that it is writable! Often, this is done through giving every system user the write privileges on that file through FTP.";
							if($f){
								if(fwrite($f, $cf)){
									fclose($f);
									$success = true;
								} else {
									$e[] = $fe;
								}
							} else {
								$e[] = $fe;
							}
						}
					}
				}
			}
		} else {
			$e[] = "Not all required fields were filled in!";
		}
	}

    // Form preparation
    $enteredConsumerKey = '';
    $enteredConsumerSecret = '';

    if(isset($_SESSION['entered_consumer_key']) && !empty($_SESSION['entered_consumer_key'])){
        $enteredConsumerKey = $_SESSION['entered_consumer_key'];
    }
    if(isset($_SESSION['entered_consumer_secret']) && !empty($_SESSION['entered_consumer_secret'])){
        $enteredConsumerSecret = $_SESSION['entered_consumer_secret'];
    }
    if($post && isset($_POST['consumer_key']) && !empty($_POST['consumer_key'])){
        $enteredConsumerKey = $_POST['consumer_key'];
    }
    if($post && isset($_POST['consumer_secret']) && !empty($_POST['consumer_secret'])){
        $enteredConsumerSecret = $_POST['consumer_secret'];
    }
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
	<title>Set up Tweet Nest<?php if($e){ ?> &#8212; Error!<?php } ?></title>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<meta name="ROBOTS" content="NOINDEX,NOFOLLOW" />
	<link rel="stylesheet" type="text/css" href="styles/setup/style.css">
</head>
<body>
	<div id="container">
		<a id="pongsk" href="http://pongsocket.com/" target="_blank" title="Open pongsocket.com in a new window">pongsocket</a>
		<h1>Set up <strong>Tweet Nest</strong></h1>
<?php if($post && $success && !$e){
	$dPath = s(rtrim($sPath, "/"));
?>
		<div id="content">
			<h2 id="excerpt"><strong>Yay!</strong> Tweet Nest has now been set up on your server. There&#8217;s a couple things left you still need to do:</h2>
			<ol class="explanation">
				<li>Remove this <code>setup.php</code> file from your server; it&#8217;s not relevant any longer.</li>
				<li>Visit the <a href="<?php echo $dPath; ?>/maintenance/loaduser.php" target="_blank">load user</a> and <a href="<?php echo $dPath; ?>/maintenance/loadtweets.php" target="_blank">load tweets</a> pages to load your tweets into the system (log in username is your Twitter screen name). If you didn&#8217;t provide an admin password, you&#8217;ll have to do this through the command lines by executing the following commands:
					<ul>
						<li><code>php <?php echo s($fPath); ?>/maintenance/loaduser.php</code></li>
						<li><code>php <?php echo s($fPath); ?>/maintenance/loadtweets.php</code></li>
					</ul>
				The <em>load tweets</em> command will need to be run regularly; the <em>load user</em> command will only need to be run when you change user information like icon, full name or location. <a href="http://pongsocket.com/tweetnest/#installation" target="_blank">More information in the installation guide &rarr;</a>
				</li>
				<li>If you changed the write privileges on <code>config.php</code> prior to running the setup guide, you should now change them back to the normal values to prevent unexpected changes to your configuration.</li>
				<li>Customization! <a href="http://pongsocket.com/tweetnest/#customization" target="_blank">More information in the customization guide &rarr;</a></li>
			</ol>
<!--
INSTALL LOG: <?php var_dump($log); ?>
-->
		</div>
<?php } elseif($e && !$post){ ?>
		<div id="content">
			<h2 id="excerpt"><strong>Whoops!</strong> An error occured that prevented you from being able to install Tweet Nest until it is fixed.</h2>
			<?php echo displayErrors($e); ?>
		</div>
<?php } else { ?>
		<form id="content" action="" method="post">
<?php if($e && $post){ ?>
			<h2 id="excerpt"><strong>Whoops!</strong> An error occured that prevented you from being able to install Tweet Nest until it is fixed.</h2>
			<?php echo displayErrors($e); ?>
<!--
INSTALL LOG: <?php var_dump($log); ?>
-->
<?php } else { ?>
			<h2 id="excerpt">To <strong>install</strong> Tweet Nest on this server and <strong>customize</strong> it to your likings, please fill in the below <strong>one-page</strong> setup configuration. If you want to change any of these values, you can edit the file <code>config.php</code> at any time to do so.</h2>
<?php } ?>
			<h2>Basic settings</h2>
			<div id="greennotice"><span></span>Green color means the value is <strong>required</strong></div>
            <div class="input">
                <label for="consumer_key">Twitter consumer key</label>
                <div class="field required"><input type="text" class="text code" name="consumer_key" id="consumer_key" value="<?php echo s($enteredConsumerKey); ?>" /></div>
                <div class="what">The consumer key of an app created and registered on <a href="//dev.twitter.com/apps">dev.twitter.com</a>.</div>
            </div>
            <div class="input">
                <label for="consumer_secret">Twitter consumer secret</label>
                <div class="field required"><input type="text" class="text code" name="consumer_secret" id="consumer_secret" value="<?php echo s($enteredConsumerSecret); ?>" /></div>
                <div class="what">The consumer secret of the above.</div>
            </div>
			<div class="input">
				<label for="twitter_auth">Twitter</label>
				<div class="field required">
                    <?php
                    if(!isset($_SESSION['access_token'])){
                        echo '<input type="image" src="inc/twitteroauth/images/lighter.png" alt="Sign in with Twitter" name="redirect" value="redirect">';
                    } else {
                        echo '<strong class="authorized">Authorized &#10004;</strong>';
                    }?></div>
				<div class="what">Authorize Tweetnest to access your twitter account. Please fill in the consumer key and secret fields before clicking this.</div>
			</div>
			<div class="input">
				<label for="tz">Your time zone</label>
				<div class="field required">
				<select name="tz" id="tz">
				    <?php
					echo '<option class="deselected" value=""';
					if (!$_POST['tz']) {
						echo ' selected="selected"';
					}
					echo '>Choose …</option>';
					$timezones = timezone_identifiers_list();
					foreach ($timezones as $zone) {
						echo '<option value="' . $zone . '"';
						if ($_POST['tz'] && $_POST['tz'] == $zone) {
							echo ' selected="selected"';
						}
						echo '>' . str_replace('_', ' ', $zone) . '</option>';
					}
                    ?>
				</select>
				</div>
				<div class="what">The time zone (closest major city) that you live in. Used to make sure your tweets have the correct timestamp so it doesn&#8217;t look like you tweeted at 4 AM. (Unless, you know, you actually did.)</div>
			</div>
			<div class="input lastinput">
				<label for="path">Tweet Nest path</label>
				<div class="field required"><input type="text" class="text" name="path" id="path" value="<?php echo $_POST['path'] ? s($_POST['path']) : s($path); ?>" /></div>
				<div class="what">The folder in which you have installed Tweet Nest, i.e. the part after your domain name. If on the root of the domain, simply type <strong>/</strong>. <span class="address">Example: <strong>/tweets</strong></span> for <span class="address">http://pongsocket.com<strong>/tweets</strong></span> (Note: No end slash, please!)</div>
			</div>

			<h2>Database authentication</h2>
			<div class="input">
				<label for="db_hostname">Database host name</label>
				<div class="field required"><input type="text" class="text" name="db_hostname" id="db_hostname" value="<?php echo $_POST['db_hostname'] ? s($_POST['db_hostname']) : "localhost"; ?>" /></div>
				<div class="what">The host name of your database server. Usually this is the same as the web server and you can thus type <strong>&#8220;localhost&#8221;</strong>. But this is not always the case, so change it if you must!</div>
			</div>
			<div class="input">
				<label for="db_username">Database username</label>
				<div class="field required"><input type="text" class="text" name="db_username" id="db_username" value="<?php echo s($_POST['db_username']); ?>" /></div>
				<div class="what">The username part of your database login.</div>
			</div>
			<div class="input">
				<label for="db_password">Database password</label>
				<div class="field required"><input type="password" class="text" name="db_password" id="db_password" value="" /></div>
				<div class="what">The password part of your database login.<?php if($_POST['db_password']){ ?> <strong class="remember">REMEMBER TO TYPE THIS IN AGAIN!</strong><?php } ?></div>
			</div>
			<div class="input">
				<label for="db_database">Database name</label>
				<div class="field required"><input type="text" class="text" name="db_database" id="db_database" value="<?php echo s($_POST['db_database']); ?>" /></div>
				<div class="what">The name of the actual database where you want Tweet Nest to store its data once logged in to the database server.</div>
			</div>
			<div class="input lastinput">
				<label for="db_table_prefix">Table name prefix</label>
				<div class="field required"><input type="text" class="text code" name="db_table_prefix" id="db_table_prefix" maxlength="10" value="<?php echo !empty($_POST) ? s($_POST['db_table_prefix']) : s("tn_"); ?>" /></div>
				<div class="what">The Tweet Archive set up page (that&#8217;s this one!) generates three different tables, and to prevent the names clashing with some already there, here you can type a character sequence prefixed to the name of both tables. Something like <strong>&#8220;ta_&#8221;</strong> or <strong>&#8220;tn_&#8221;</strong> is good.</div>
			</div>

			<h2>Miscellaneous settings</h2>
			<div class="input">
				<label for="maintenance_http_password">Admin password</label>
				<div class="field"><input type="password" class="text" name="maintenance_http_password" id="maintenance_http_password" value="" /></div>
				<div class="what">If you want to <strong>load your tweets</strong> into Tweet Nest through your browser, specify an admin password. If you don&#8217;t specify this, you&#8217;ll only be able to load tweets through your server&#8217;s command line, so this is <strong>highly encouraged</strong>. Note: Unless you have SSL, this will be sent in clear text, so probably <strong>not</strong> make it the same as your Twitter password!<?php if($_POST['maintenance_http_password']){ ?> <strong class="remember">REMEMBER TO TYPE THIS IN AGAIN!</strong><?php } ?></div>
			</div>
			<div class="input">
				<label for="maintenance_http_password_2">(Repeat it)</label>
				<div class="field"><input type="password" class="text" name="maintenance_http_password_2" id="maintenance_http_password_2" value="" /></div>
				<div class="what">If you typed an admin password above, type it here again.<?php if($_POST['maintenance_http_password']){ ?> <strong class="remember">REMEMBER TO TYPE THIS IN AGAIN!</strong><?php } ?></div>
			</div>
			<div class="input">
				<label for="follow_me_button">&#8220;Follow me&#8221; button</label>
				<div class="field"><input type="checkbox" class="checkbox" name="follow_me_button" id="follow_me_button" checked="checked" /></div>
				<div class="what">Display a &#8220;Follow me on Twitter&#8221; button on your Tweet Nest page?</div>
			</div>
			<div class="input">
				<label for="smartypants">SmartyPants</label>
				<div class="field"><input type="checkbox" class="checkbox" name="smartypants" id="smartypants" checked="checked" /></div>
				<div class="what">Use <a href="http://daringfireball.net/projects/smartypants/" target="_blank">SmartyPants</a> to perfect punctuation inside tweets? Changes all "straight quotes" to &#8220;curly quotes&#8221; and more.</div>
			</div>
			<div class="input lastinput">
				<label for="https_strict">HTTPS Strict</label>
				<div class="field"><input type="checkbox" class="checkbox" name="https_strict" id="https_strict" checked="checked" /></div>
				<div class="what">Enforce to only show inline images (&#8220;thumbnails&#8221;) when they come from HTTPS urls. If you are concerned about <a href="https://developers.google.com/web/fundamentals/security/prevent-mixed-content/what-is-mixed-content" target="_blank">mixed content</a> (or just want to prevent the warnings from modern browsers), you should check this.</div>
			</div>

			<h2>Style settings</h2>
			<div class="note">
				<p>If you open up <code>config.php</code> after setting up your Tweet Nest, you will see a lot of style settings that you can play around with. Read the guide on the Tweet Nest website for more information on how to <a href="http://pongsocket.com/tweetnest/#customization" target="_blank">customize your Tweet Nest&#8217;s look &rarr;</a></p>
			</div>

			<h2>That&#8217;s it!</h2>
			<div><input type="submit" class="submit" value="Submit and set up" /></div>
		</form>
<?php } ?>
	</div>
</body>
</html>
